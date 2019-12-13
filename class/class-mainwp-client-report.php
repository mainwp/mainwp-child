<?php

class MainWP_Client_Report {
	
	public static $instance = null;	
	
	static function Instance() {
		if ( null === MainWP_Client_Report::$instance ) {
			MainWP_Client_Report::$instance = new MainWP_Client_Report();
		}

		return MainWP_Client_Report::$instance;
	}
	
	public function __construct() {		
		add_filter( 'wp_mainwp_stream_current_agent', array($this, 'current_agent' ), 10, 1 );
	}
	
	public function init() {
        add_filter( 'mainwp-site-sync-others-data', array( $this, 'syncOthersData' ), 10, 2 );		
		add_action( 'mainwp_child_log', array( 'MainWP_Client_Report', 'do_reports_log' ) );
	}

	public function current_agent( $agent ) {		
		if ( isset( $_POST['function'] ) && isset( $_POST['mainwpsignature'] ) )
			$agent = '';				
		return $agent;		
	}
	
	// ok
    public function syncOthersData( $information, $data = array() ) {
        if ( isset( $data['syncClientReportData'] ) && $data['syncClientReportData'] ) {
            $creport_sync_data = array();
            if ( ( $firsttime = get_option( 'mainwp_creport_first_time_activated' ) ) !== false ) {
                $creport_sync_data['firsttime_activated'] = $firsttime;
            }
            if ( !empty( $creport_sync_data ) ) {
                $information['syncClientReportData'] =  $creport_sync_data;
            }
        }
		return $information;
	}

	public  static function do_reports_log( $ext = '' ) {
		switch( $ext ) {
			case 'backupbuddy':
				MainWP_Child_Back_Up_Buddy::Instance()->do_reports_log( $ext );
				break;
			case 'backupwordpress':
				MainWP_Child_Back_Up_Wordpress::Instance()->do_reports_log( $ext );
				break;
			case 'backwpup':
				MainWP_Child_Back_WP_Up::Instance()->do_reports_log( $ext );
				break;
			case 'wordfence':
				MainWP_Child_Wordfence::Instance()->do_reports_log( $ext );
				break;
            case 'wptimecapsule':
				MainWP_Child_Timecapsule::Instance()->do_reports_log( $ext );
				break;
		}
	}

	public function action() {

		$information              = array();

		if ( !function_exists( 'wp_mainwp_stream_get_instance' ) ) {
			$information['error'] = __( 'Error: No MainWP Client Reports plugin installed.' );
			MainWP_Helper::write( $information );
		}

		if ( isset( $_POST['mwp_action'] ) ) {
			switch ( $_POST['mwp_action'] ) {
				case 'save_sucuri_stream':
					$information = $this->save_sucuri_stream();
					break;
				case 'save_backup_stream':
					$information = $this->save_backup_stream();
					break;
				case 'get_stream':
					$information = $this->get_stream();
					break;
				case 'set_showhide':
					$information = $this->set_showhide();
					break;
			}
		}
		MainWP_Helper::write( $information );
	}

	public function save_sucuri_stream() {		
        $scan_data = isset($_POST['scan_data']) ? $_POST['scan_data'] : '';		
		do_action( 'mainwp_reports_sucuri_scan', $_POST['result'], $_POST['scan_status'], $scan_data, isset($_POST['scan_time']) ? $_POST['scan_time'] : 0  );
		return true;
	}

	public function save_backup_stream() {
		do_action( 'mainwp_backup', $_POST['destination'], $_POST['message'], $_POST['size'], $_POST['status'], $_POST['type'] );

		return true;
	}
	
	public function is_backup_action( $action ) {
		if ( in_array( $action, array( 'mainwp_backup', 'backupbuddy_backup', 'backupwordpress_backup', 'backwpup_backup', 'updraftplus_backup', 'wptimecapsule_backup' ) ) ) 
			return true;
		return false;		
	}
	
	public function get_compatible_context( $context ) {	
		// convert context name of tokens to context name saved in child report
		// some context are not difference	
		$mapping_contexts =  array(
			'comment' => 'comments', // actual context values: post,page
			'plugin'  => 'plugins',
			'users' => 'profiles',
			'user'		=> 'profiles',
			'session' => 'sessions',
			'setting' => 'settings',
			'theme'   => 'themes',
			'posts'   => 'post',
			'pages'   => 'page',
			'widgets'  => 'widgets',
			'widget'  => 'widgets',
			'menu'    => 'menus',
			'backups' => 'backups',
			'backup'  => 'backups',
			'sucuri'  => 'sucuri_scan',
			'maintenance' => 'mainwp_maintenance',
			'wordfence' => 'wordfence_scan',
			'backups'  => 'backups', 
			'backup'   => 'backups', 
			'media'	=> 'media'					
		);
		
		$context = isset( $mapping_contexts[ $context ] ) ? $mapping_contexts[ $context ] : $context;		
		return strtolower($context);
	}
	
		
	public function get_connector_by_compatible_context( $context ) {
		
		$connector = "";
		if ( $context == "plugins" || $context == "themes" || $context == "wordpress" ) {			
			$connector = "installer";
		} else if ( $context == 'profiles' ) {
			$connector = "users";
		} else if ( $context == 'comments' ) { // multi values			
			$connector = "comments";
		} 
//		else if ( $context == 'sessions' ) {
//			$connector = "users";
//		} 
		else if ( $context == 'settings' ) {
			$connector = "settings";
		} else if ( $context == 'post' || $context == 'page') {
			$connector = "posts";
		} else if ( $context == 'widgets' ) {
			$connector = "widgets";
		} else if ( $context == 'menus' ) {
			$connector = "menus";
		} else if ( $context == 'backups' ) {
			$connector = "mainwp_backups";
		} else if ( $context == 'sucuri_scan' ) {						
			$connector = "mainwp_sucuri";
		} else if ( $context == 'mainwp_maintenance' ) {
			$connector = "mainwp_maintenance";
		} else if ( $context == 'wordfence_scan' ) {			
			$connector = "mainwp_wordfence";
		} else if ( $context == 'media' ) {			
			$connector = "media";
		} 				
		
		return $connector;		
	}

	public function get_compatible_action( $action, $context = '' ) {
		
		$mapping_actions = array(
			'restored' => 'untrashed',
			'spam'     => 'spammed',			
		);				
		
		if (isset($mapping_actions[ $action ]))
			return $mapping_actions[ $action ];
		
		if ( $context == 'mainwp_maintenance' ) {
			if ( $action == 'process')
				$action = 'maintenance';
		} else if ( $context == 'sucuri_scan' ) {
			if ( $action == 'checks')
				$action = 'sucuri_scan';
		} else if ($context == 'wordfence_scan') {
			if ( $action == 'scan')
				$action = 'wordfence_scan';
		}				
		return $action;
	}
		
	public function get_stream() {
		// Filters
		$allowed_params = array(
			'connector',
			'context',
			'action',
			'author',
			'author_role',
			'object_id',
			'search',
			'date',
			'date_from',
			'date_to',
			'record__in',
			'blog_id',
			'ip',
		);

		$sections = isset( $_POST['sections'] ) ? maybe_unserialize( base64_decode( $_POST['sections'] ) ) : array();
		if ( ! is_array( $sections ) ) {
			$sections = array();
		}
		//return $sections;

		$other_tokens = isset( $_POST['other_tokens'] ) ? maybe_unserialize( base64_decode( $_POST['other_tokens'] ) ) : array();
		if ( ! is_array( $other_tokens ) ) {
			$other_tokens = array();
		}
		//return $other_tokens;

		unset( $_POST['sections'] );
		unset( $_POST['other_tokens'] );

		$args = array();
		foreach ( $allowed_params as $param ) {
			$paramval = wp_mainwp_stream_filter_input( INPUT_POST, $param );
			if ( $paramval || '0' === $paramval ) {
				$args[ $param ] = $paramval;
			}
		}

		foreach ( $args as $arg => $val ) {
			if ( ! in_array( $arg, $allowed_params ) ) {
				unset( $args[ $arg ] );
			}
		}

		// to fix bug
		$exclude_connector_posts = true;
		if ( isset( $sections['body'] ) && isset( $sections['body']['section_token'] ) && is_array($sections['body']['section_token']) ) {
			foreach ($sections['body']['section_token'] as $sec) {
				if (strpos($sec, "[section.posts") !== false || strpos($sec, "[section.pages") !== false) {
					$exclude_connector_posts = false;
					break;
				}
			}
		}
		if ($exclude_connector_posts) {
			if ( isset( $sections['header'] ) && isset( $sections['header']['section_token'] ) && is_array($sections['header']['section_token']) ) {
				foreach ($sections['header']['section_token'] as $sec) {
					if (strpos($sec, "[section.posts") !== false  || strpos($sec, "[section.pages") !== false) {
						$exclude_connector_posts = false;
						break;
					}
				}
			}
		}
		if ($exclude_connector_posts) {
			if ( isset( $sections['footer'] ) && isset( $sections['footer']['section_token'] ) && is_array($sections['footer']['section_token']) ) {
				foreach ($sections['footer']['section_token'] as $sec) {
					if (strpos($sec, "[section.posts") !== false  || strpos($sec, "[section.pages") !== false) {
						$exclude_connector_posts = false;
						break;
					}
				}
			}
		}
		if ($exclude_connector_posts) {
			if ( isset( $other_tokens['body'] ) && is_array( $other_tokens['body'] ) ) {
				foreach ( $other_tokens['body'] as $sec ) {
					if ( strpos( $sec, "[post." ) !== false  || strpos($sec, "[page.") !== false) {
						$exclude_connector_posts = false;
						break;
					}
				}
			}
		}
		if ($exclude_connector_posts) {
			if ( isset( $other_tokens['header'] ) && is_array($other_tokens['header']) ) {
				foreach ($other_tokens['header'] as $sec) {
					if (strpos($sec, "[post.") !== false  || strpos($sec, "[page.") !== false) {
						$exclude_connector_posts = false;
						break;
					}
				}
			}
		}
		if ($exclude_connector_posts) {
			if ( isset( $other_tokens['footer'] ) && is_array($other_tokens['footer']) ) {
				foreach ($other_tokens['footer'] as $sec) {
					if (strpos($sec, "[post.") !== false || strpos($sec, "[page.") !== false) {
						$exclude_connector_posts = false;
						break;
					}
				}
			}
		}
		if ($exclude_connector_posts)
			$args['connector__not_in'] =  array('posts');
		///// end fix /////

		$args['action__not_in'] = array( 'login' );

		$args['with-meta'] = 1;
		
		if ( isset( $args['date_from'] ) ) {
			$args['date_from'] = date( 'Y-m-d', $args['date_from'] );
		}

		if ( isset( $args['date_to'] ) ) {
			$args['date_to'] = date( 'Y-m-d', $args['date_to'] );
		}

		if ( MainWP_Child_Branding::Instance()->is_branding() ) {
			$args['hide_child_reports'] = 1;
		}

		$args['records_per_page'] = 9999;

//		$records = mainwp_wp_stream_query( $args );
		$records = wp_mainwp_stream_get_instance()->db->query( $args );
		
		if ( ! is_array( $records ) ) {
			$records = array();
		}

		// to fix invalid data
		$skip_records = array();
		if ( isset( $other_tokens['header'] ) && is_array( $other_tokens['header'] ) ) {
			$other_tokens_data['header'] = $this->get_other_tokens_data( $records, $other_tokens['header'], $skip_records);
		}

		if ( isset( $other_tokens['body'] ) && is_array( $other_tokens['body'] ) ) {
			$other_tokens_data['body'] = $this->get_other_tokens_data( $records, $other_tokens['body'], $skip_records );
		}

		if ( isset( $other_tokens['footer'] ) && is_array( $other_tokens['footer'] ) ) {
			$other_tokens_data['footer'] = $this->get_other_tokens_data( $records, $other_tokens['footer'], $skip_records );
		}

		$sections_data = array();

		if ( isset( $sections['header'] ) && is_array( $sections['header'] ) && ! empty( $sections['header'] ) ) {
			foreach ( $sections['header']['section_token'] as $index => $sec ) {
				$tokens                            = $sections['header']['section_content_tokens'][ $index ];
				$sections_data['header'][ $index ] = $this->get_section_loop_data( $records, $tokens, $sec, $skip_records );
			}
		}
		if ( isset( $sections['body'] ) && is_array( $sections['body'] ) && ! empty( $sections['body'] ) ) {
			foreach ( $sections['body']['section_token'] as $index => $sec ) {
				$tokens                          = $sections['body']['section_content_tokens'][ $index ];
				$sections_data['body'][ $index ] = $this->get_section_loop_data( $records, $tokens, $sec, $skip_records );
			}
		}
		if ( isset( $sections['footer'] ) && is_array( $sections['footer'] ) && ! empty( $sections['footer'] ) ) {
			foreach ( $sections['footer']['section_token'] as $index => $sec ) {
				$tokens                            = $sections['footer']['section_content_tokens'][ $index ];
				$sections_data['footer'][ $index ] = $this->get_section_loop_data( $records, $tokens, $sec, $skip_records );
			}
		}

		$information = array(
			'other_tokens_data' => $other_tokens_data,
			'sections_data'     => $sections_data,
		);

		return $information;
	}

	function get_other_tokens_data( $records, $tokens, &$skip_records ) {

		$token_values = array();

		if ( ! is_array( $tokens ) ) {
			$tokens = array();
		}

        $backups_created_time_to_fix = array();
		foreach ( $tokens as $token ) {
			
			if ( isset( $token_values[ $token ] ) )
				continue;
			
			$str_tmp   = str_replace( array( '[', ']' ), '', $token );
			$array_tmp = explode( '.', $str_tmp );

			if ( is_array( $array_tmp ) ) {
				$context = $action = $data = '';
				if ( 2 === count( $array_tmp ) ) {
					list( $context, $data ) = $array_tmp;
				} else if ( 3 === count( $array_tmp ) ) {
					list( $context, $action, $data ) = $array_tmp;
				}

				$context = $this->get_compatible_context( $context );	
				
				// to compatible with new version of child report
				// to check condition for grabbing report data		
				$connector = $this->get_connector_by_compatible_context( $context );
				
				$action = $this->get_compatible_action( $action, $context );
								
				// custom values
				if ( $context == 'profiles' ) {
					if ( $action == 'created' || $action == 'deleted' ) {
						$context = 'users'; // see class-connector-user.php
					}
				}
				//// 
				
				switch ( $data ) {
					case 'count':
						
						$count = 0;
						foreach ( $records as $record ) {
							
							// check connector							
							if ( $record->connector == 'editor' ) {				
								if ( !in_array( $context, array('plugins', 'themes') ) || $action !== 'updated' )
									continue;				
							} else if ( $connector !== $record->connector ) {
								continue;
							}				
							
							// check context			
							if ( $context == 'comments' ) { // multi values									
								$comment_contexts = array( 'post', 'page' );					
								if ( ! in_array( $record->context, $comment_contexts ) ) {
									continue;
								}
							} else if ( $context == "menus") {
								// ok, pass, don't check context
							} else if ( $record->connector == 'editor' ) {
								// ok, pass, checked above 
							} else if ( $connector == 'media' && $record->connector == 'media' ) {
								// ok, pass, do not check context
							} else if ( $connector == "widgets" && $record->connector == 'widgets') {
								// ok, pass, don't check context
							} else if ( $context !== strtolower( $record->context )) {
								continue;
							}
									
							// custom action value
							if ( $connector == "widgets" ) {
								if ( $action == "deleted" ) {
									$action = "removed"; // action saved in database
								}									
							}
							//// 
							
							// check action
							if ( 'backups' === $context ) {																
								if ( ! $this->is_backup_action($record->action) ) {
									continue;
								}
                                $created = strtotime( $record->created );
                                if ( in_array( $created, $backups_created_time_to_fix ) ) {
                                    if ( ! in_array($record->ID, $skip_records) ) {
                                        $skip_records[] = $record->ID;
                                    }
                                    continue;
                                } else {
                                    $backups_created_time_to_fix[] = $created;
                                }
							} else {									
								if ( $action !== $record->action ) {
									continue;
								}

								if ( 'updated' === $action && ( 'post' === $context || 'page' === $context ) ) {
									$new_status = $this->get_stream_meta_data( $record, 'new_status' );
									if ( 'draft' === $new_status ) { // avoid auto save post
										continue;
									}
								} else if ( 'updated' === $action && ('themes' === $context || 'plugins' === $context)) {
									$name = $this->get_stream_meta_data( $record, 'name' );
									if ( empty($name) ) { // to fix empty value
										if (!in_array($record->ID, $skip_records))
											$skip_records[] = $record->ID;
										continue;
									} else {
										$old_version = $this->get_stream_meta_data( $record, 'old_version' );
										$version = $this->get_stream_meta_data( $record, 'version' );
										if (version_compare($version, $old_version, '<=')) { // to fix
											if (!in_array($record->ID, $skip_records))
												$skip_records[] = $record->ID;
											continue;
										}
									}									
								}
							}
							$count ++;
						}
						$token_values[ $token ] = $count;
						break;
				}
			}
		}

		return $token_values;
	}

//	function get_meta_value_from_summary( $summary, $meta ) {		
//		$value = '';
//		if ( $meta == 'name' ) {
//			$value = str_replace(array('Updated plugin:', 'Updated theme:'), '', $summary);
//			$value = trim( $value );
//			$last_space_pos = strrpos($value, ' ');
//			if ($last_space_pos !== false) {				
//				$value = substr($value , 0, 0 - $last_space_pos);
//			}			
//		} else if ( $meta == 'version') {
//			$last_space_pos = strrpos($value, ' ');
//			if ($last_space_pos !== false) {				
//				$value = substr($value , $last_space_pos);
//			}
//		} else if ( $meta == 'old_version' ) {
//			$value = 'N/A';
//		}		
//		return $value;			
//	}
	
	function get_section_loop_data( $records, $tokens, $section, $skip_records = array() ) {

		
		$maintenance_details = array(
			'revisions'    => __( 'Delete all post revisions', 'mainwp-child' ),
			'autodraft'    => __( 'Delete all auto draft posts',                   'mainwp-child' ),
			'trashpost'    => __( 'Delete trash posts',                            'mainwp-child' ),
			'spam'         => __( 'Delete spam comments',                          'mainwp-child' ),
			'pending'      => __( 'Delete pending comments',                       'mainwp-child' ),
			'trashcomment' => __( 'Delete trash comments',                         'mainwp-child' ),
			'tags'         => __( 'Delete tags with 0 posts associated',           'mainwp-child' ),
			'categories'   => __( 'Delete categories with 0 posts associated',     'mainwp-child' ),
			'optimize'     => __( 'Optimize database tables',                      'mainwp-child' )
		);
		
		$context = $action = '';
		
		// parse $context, $action values from section tokens
		$str_tmp   = str_replace( array( '[', ']' ), '', $section );
		$array_tmp = explode( '.', $str_tmp );
		if ( is_array( $array_tmp ) ) {
			if ( 2 === count( $array_tmp ) ) {
				list( $str1, $context ) = $array_tmp;
			} else if ( 3 === count( $array_tmp ) ) {
				list( $str1, $context, $action ) = $array_tmp;
			}
		}
		// end
		
		// get db $context value by mapping
		$context = $this->get_compatible_context( $context );		
		// to compatible with new version of child report
		// to check condition for grabbing report data		
		$connector = $this->get_connector_by_compatible_context( $context );
		
		$action = $this->get_compatible_action( $action, $context );
		
		if ( $context == 'profiles' ) {
			if ( $action == 'created' || $action == 'deleted' ) {
				$context = 'users'; // see class-connector-user.php
			}
		}
						
		$loops      = array();
		$loop_count = 0;

		foreach ( $records as $record ) {			
			
//			$fix_meta_name = $fix_old_version = $fix_version = '';			
			
			if ( in_array($record->ID, $skip_records) ) {
				// to fix incorrect meta for update logging
//				if ( 'updated' === $action && ('themes' === $context || 'plugins' === $context)) {
//					if ( !isset( $record->meta ) ||  $record->meta == '') {
//						$fix_meta_name = get_meta_value_from_summary($record->summary, 'name');						
//						$fix_old_version = get_meta_value_from_summary($record->summary, 'old_version');
//						$fix_version = get_meta_value_from_summary($record->summary, 'version');
//					} 					
//				} else {
					continue;
//				}
			}
				
			// check connector
			if ( $record->connector == 'editor' ) {				
				if ( !in_array( $context, array('plugins', 'themes') ) || $action !== 'updated' )
					continue;				
		    } else if ( $connector !== $record->connector ) {
				continue;
			}
			
			// check context
			if ( $context == "comments" ) { // multi values									
				$comment_contexts = array('post', 'page');					
				if ( ! in_array( $record->context, $comment_contexts ) ) {
					continue;
				}				
			} else if ( $context == "menus") {
				// ok, pass, don't check context
			} else if ( $record->connector == 'editor' ) {
				// ok, pass, checked above 
			} else if ( $connector == 'media' && $record->connector == 'media' ) {
				// ok, pass, do not check context
			} else if ( $connector == "widgets" && $record->connector == 'widgets') {
				// ok, pass, don't check context
				//
			} else if ( $context !== strtolower( $record->context ) ) {
				continue;
			} 
							
			// custom action value
			if ( $connector == "widgets" ) {
				if ( $action == "deleted" ) {
					$action = "removed"; // action saved in database
				}									
			}
			//// 
							
			// check action 
			if ( $context == 'backups' ) {					
				if ( ! $this->is_backup_action($record->action) ) {
					continue;
				}
			} else if ( $action !== $record->action ) {
				continue;
			}

			if ( 'updated' === $action && ( 'post' === $context || 'page' === $context ) ) {
				$new_status = $this->get_stream_meta_data( $record, 'new_status' );
				if ( 'draft' === $new_status ) { // avoid auto save post
					continue;
				}
			}			
			
			$token_values = array();

			foreach ( $tokens as $token ) {				
				// parse $data value from tokens in sections
				$data       = '';
				$token_name = str_replace( array( '[', ']' ), '', $token );
				$array_tmp  = explode( '.', $token_name );

				if ( 'user.name' === $token_name ) {
					$data = 'display_name';
				} else {
					if ( 1 === count( $array_tmp ) ) {
						list( $data ) = $array_tmp;
					} else if ( 2 === count( $array_tmp ) ) {
						list( $str1, $data ) = $array_tmp;
					} else if ( 3 === count( $array_tmp ) ) {
						list( $str1, $str2, $data ) = $array_tmp;
					}

					if ( 'version' === $data ) {
						if ( 'old' === $str2 ) {
							$data = 'old_version';
						} else if ( 'current' === $str2 && 'wordpress' === $str1 ) {
							$data = 'new_version';
						}
					}
				}

				if ( 'role' === $data ) {
					$data = 'roles';
				}

				switch ( $data ) {
					case 'ID':
						$token_values[ $token ] = $record->ID;
						break;
					case 'date':
						$token_values[ $token ] = MainWP_Helper::formatDate( MainWP_Helper::getTimestamp( strtotime( $record->created ) ) );
						break;
					case 'time':
						$token_values[ $token ] = MainWP_Helper::formatTime( MainWP_Helper::getTimestamp( strtotime( $record->created ) ) );
						break;
					case 'area':
						$data                   = 'sidebar_name';
						$token_values[ $token ] = $this->get_stream_meta_data( $record, $data );
						break;
					case 'name':
					case 'version':
					case 'old_version':
					case 'new_version':
					case 'display_name':
					case 'roles':
							if ( $data == 'name' ) {
								if ( $context == 'profiles' )
									$data  = 'display_name';
							}	
							$token_values[ $token ] = $this->get_stream_meta_data( $record, $data );						
						break;
					case 'title':
						if ( 'comments' === $context ) {
							$token_values[ $token ] = $record->summary;
						} else {
							if ( 'page' === $context || 'post' === $context ) {
								$data = 'post_title';
							} else if ( 'menus' === $record->connector ) {
								$data = 'name';
							}
							$token_values[ $token ] = $this->get_stream_meta_data( $record, $data );
						}
						break;
					case 'author':						
						if ( $connector == "comment" ) {							
							$data  = 'user_name'; 
						} else {
							$data  = 'user_meta'; 	
						}
						
						$value = $this->get_stream_meta_data( $record, $data );
						
						if ( empty( $value ) && 'comments' === $context ) {
							$value = __( 'Guest', 'mainwp-child-reports' );
						}
						
						// to check may compatible with old meta data
						if ( empty( $value )) {	
							$value = $this->get_stream_meta_data( $record, 'author_meta' );															
						}
						
						$token_values[ $token ] = $value;
						break;
					case 'status':   // sucuri cases
					case 'webtrust':
						if ( 'sucuri_scan' === $context ) {
                            $scan_data = $this->get_stream_meta_data( $record, 'scan_data' );
                            if (!empty($scan_data)) {
                                $scan_data  = maybe_unserialize( base64_decode( $scan_data ) );
                                if ( is_array( $scan_data ) ) {

                                    $blacklisted = $scan_data['blacklisted'];
                                    $malware_exists = $scan_data['malware_exists'];

                                    $status = array();
                                    if ( $blacklisted ) {
                                        $status[] = __( 'Site Blacklisted', 'mainwp-child' ); }
                                    if ( $malware_exists ) {
                                        $status[] = __( 'Site With Warnings', 'mainwp-child' ); }

                                    if ($data == 'status') {
                                        $token_values[$token] = count( $status ) > 0 ? implode( ', ', $status ) : __( 'Verified Clear', 'mainwp-child' );
                                    } else if ($data == 'webtrust') {
                                        $token_values[$token] = $blacklisted ? __( 'Site Blacklisted', 'mainwp-child' ) : __( 'Trusted', 'mainwp-child' );
                                    }
                                }

                            } else {
                                $token_values[ $token ] = $this->get_stream_meta_data( $record, $data );
                            }
						} else {
							$token_values[ $token ] = $value;
						}
						break;
					case 'details':
					case 'result':
						
						if ('mainwp_maintenance' === $context && 'details' == $data) {							
							
							$meta_value  = $this->get_stream_meta_data( $record, $data );
							$meta_value = explode(",", $meta_value);
							
							$details = array();
							
							if ( is_array( $meta_value) ) {
								foreach($meta_value as $mt) {
									if ( isset($maintenance_details[$mt]) ) {
										$details[] = $maintenance_details[$mt];
									}
								}
							}
							$token_values[ $token ] = implode(", ", $details);
							
						} else if ( 'wordfence_scan' === $context || 'mainwp_maintenance' === $context ) {
                            $meta_value  = $this->get_stream_meta_data( $record, $data );
                            // to fix
                            if ('wordfence' === $context && $data == 'result') {
                                // SUM_FINAL:Scan complete. You have xxx new issues to fix. See below.
                                // SUM_FINAL:Scan complete. Congratulations, no new problems found
                                if (stripos($meta_value, 'Congratulations')) {
                                    $meta_value = 'No issues detected';
                                } else if (stripos($meta_value, 'You have')) {
                                    $meta_value = 'Issues Detected';
                                } else {
                                    $meta_value = '';
                                }
                            }
							$token_values[ $token ] = $meta_value;
						}
						break;
					//case 'destination':  // for backup tokens 
					case 'type': 
						if ( 'backups' === $context ) {
							$token_values[ $token ] = $this->get_stream_meta_data( $record, $data );
						} else {
							$token_values[ $token ] = $token;
						}
						break;
					default:
						$token_values[ $token ] = 'N/A';
						break;
				}
			} // foreach $tokens

			if ( ! empty( $token_values ) ) {
				$loops[ $loop_count ] = $token_values;
				$loop_count ++;
			}
		} // foreach $records
		return $loops;
	}

	function get_stream_meta_data( $record, $data ) {

		if ( empty( $record ) ) {
			return '';
		}

		$meta_key = $data;

		$value = '';

		if ( isset( $record->meta ) ) {
			$meta = $record->meta;
						
			if ( isset( $meta[ $meta_key ] ) ) {				
				$value = $meta[ $meta_key ];
				$value = current( $value );			
				
				// to compatible with old meta data
				if ( 'author_meta' === $meta_key ) {	
					$value = maybe_unserialize( $value );					
					if (is_array($value)) {
						$value = $value['display_name'];
						// to fix empty author value
						if ( empty($value) ) {
							if (isset($value['agent']) && !empty($value['agent'])) {
								$value = $value['agent'];
							}
						}
					}
                    if (!is_string($value)) {
                        $value = '';
                    }
				}
				// end
			} 				
		}

		return $value;
	}

	function set_showhide() {
        $hide = isset( $_POST['showhide'] ) && ( 'hide' === $_POST['showhide'] ) ? 'hide' : '';
        MainWP_Child_Branding::Instance()->save_branding_options('hide_child_reports', $hide);
        //MainWP_Helper::update_option( 'mainwp_creport_branding_stream_hide', $hide, 'yes' ); // to compatible with old child reports
		$information['result'] = 'SUCCESS';

        return $information;
	}

	public function creport_init() {

        $branding_opts = MainWP_Child_Branding::Instance()->get_branding_options();
        $hide_nag = false;

        // check setting of 'hide_child_reports'
        if ( isset($branding_opts['hide_child_reports']) && $branding_opts['hide_child_reports'] == 'hide' ) {
            add_filter( 'all_plugins', array( $this, 'creport_branding_plugin' ) );
            add_action( 'admin_menu', array( $this, 'creport_remove_menu' ) );
            $hide_nag = true;
        }

        if ( ! $hide_nag ) {
            // check child branding settings
            if ( MainWP_Child_Branding::Instance()->is_branding() ) {
                $hide_nag = true;
            }
        }

        if ($hide_nag) {
            add_filter( 'site_transient_update_plugins', array( &$this, 'remove_update_nag' ) );
            add_filter( 'mainwp_child_hide_update_notice', array( &$this, 'hide_update_notice' ) );
        }
	}

    function hide_update_notice( $slugs ) {
        $slugs[] = 'mainwp-child-reports/mainwp-child-reports.php';
        return $slugs;
    }

	function remove_update_nag( $value ) {
        if ( isset( $_POST['mainwpsignature'] ) ) {
			return $value;
		}

        if (! MainWP_Helper::is_screen_with_update()) {
            return $value;
        }

		if ( isset( $value->response['mainwp-child-reports/mainwp-child-reports.php'] ) ) {
			unset( $value->response['mainwp-child-reports/mainwp-child-reports.php'] );
		}

		return $value;
	}


	public function creport_branding_plugin( $plugins ) {
		foreach ( $plugins as $key => $value ) {
			$plugin_slug = basename( $key, '.php' );
			if ( 'mainwp-child-reports' === $plugin_slug ) {
				unset( $plugins[ $key ] );
			}
		}

		return $plugins;
	}

	public function creport_remove_menu() {
		remove_menu_page( 'mainwp_wp_stream' );
	}
}

