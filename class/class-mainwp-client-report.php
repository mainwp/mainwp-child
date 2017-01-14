<?php

class MainWP_Client_Report {
	public static $instance = null;

	static function Instance() {
		if ( null === MainWP_Client_Report::$instance ) {
			MainWP_Client_Report::$instance = new MainWP_Client_Report();
		}

		return MainWP_Client_Report::$instance;
	}

	public static function init() {
		add_filter( 'wp_stream_connectors', array( 'MainWP_Client_Report', 'init_stream_connectors' ), 10, 1 );
		add_filter( 'mainwp_client_reports_connectors', array( 'MainWP_Client_Report', 'init_report_connectors' ), 10, 1 );
        add_action( 'mainwp_child_log', array( 'MainWP_Client_Report', 'do_reports_log' ) );
	}

	public static function init_stream_connectors( $classes ) {
		$connectors = array(
			'Backups',
			'Sucuri',
		);

		foreach ( $connectors as $connector ) {
			$class_name = "MainWP_Child_Reports_Connector_$connector";
			if ( ! class_exists( $class_name ) ) {
				continue;
			}
			$class = new $class_name();
			if ( ! method_exists( $class, 'is_dependency_satisfied' ) ) {
				continue;
			}
			if ( $class->is_dependency_satisfied() ) {
				$classes[] = $class;
			}
		}

		return $classes;
	}

	public static function init_report_connectors( $classes ) {
		$connectors = array(
			'Backups',
			'Sucuri'
		);

		foreach ( $connectors as $connector ) {
			$class     = "MainWP_Child_Reports_Connector_$connector";
			if ( ! class_exists( $class ) ) {
				continue;
			}
			$classes[] = $class;
		}

		return $classes;
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
        }
    }

	public function action() {

		$information              = array();

        if ( !function_exists( 'mainwp_wp_stream_query' ) || !class_exists( 'MainWP_WP_Stream' ) ) {
			$information['error'] = 'NO_CREPORT';
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
		do_action( 'mainwp_sucuri_scan', $_POST['result'], $_POST['scan_status'] );

		return true;
	}

	public function save_backup_stream() {
		do_action( 'mainwp_backup', $_POST['destination'], $_POST['message'], $_POST['size'], $_POST['status'], $_POST['type'] );

		return true;
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
            $paramval = mainwp_wp_stream_filter_input( INPUT_POST, $param );
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
				if (strpos($sec, "[section.posts") !== false) {
					$exclude_connector_posts = false;
					break;
				}
			}
		}
		if ($exclude_connector_posts) {
			if ( isset( $sections['header'] ) && isset( $sections['header']['section_token'] ) && is_array($sections['header']['section_token']) ) {
				foreach ($sections['header']['section_token'] as $sec) {
					if (strpos($sec, "[section.posts") !== false) {
						$exclude_connector_posts = false;
						break;
					}
				}
			}
		}
		if ($exclude_connector_posts) {
			if ( isset( $sections['footer'] ) && isset( $sections['footer']['section_token'] ) && is_array($sections['footer']['section_token']) ) {
				foreach ($sections['footer']['section_token'] as $sec) {
					if (strpos($sec, "[section.posts") !== false) {
						$exclude_connector_posts = false;
						break;
					}
				}
			}
		}
		if ($exclude_connector_posts) {
			if ( isset( $other_tokens['body'] ) && is_array( $other_tokens['body'] ) ) {
				foreach ( $other_tokens['body'] as $sec ) {
					if ( strpos( $sec, "[post." ) !== false ) {
						$exclude_connector_posts = false;
						break;
					}
				}
			}
		}
		if ($exclude_connector_posts) {
			if ( isset( $other_tokens['header'] ) && is_array($other_tokens['header']) ) {
				foreach ($other_tokens['header'] as $sec) {
					if (strpos($sec, "[post.") !== false) {
						$exclude_connector_posts = false;
						break;
					}
				}
			}
		}
		if ($exclude_connector_posts) {
			if ( isset( $other_tokens['footer'] ) && is_array($other_tokens['footer']) ) {
				foreach ($other_tokens['footer'] as $sec) {
					if (strpos($sec, "[post.") !== false) {
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

        $args['fields'] = 'with-meta';
        if ( isset( $args['date_from'] ) ) {
                $args['date_from'] = date( 'Y-m-d H:i:s', $args['date_from'] );
        }

        if ( isset( $args['date_to'] ) ) {
                $args['date_to'] = date( 'Y-m-d H:i:s', $args['date_to'] );
        }

        if ( MainWP_Child_Branding::is_branding() ) {
            $args['hide_child_reports'] = 1;
        }

		$args['records_per_page'] = 9999;

        $records = mainwp_wp_stream_query( $args );

		if ( ! is_array( $records ) ) {
			$records = array();
		}

		//return $records;
		//$other_tokens_data = $this->get_other_tokens_data($records, $other_tokens);

		if ( isset( $other_tokens['header'] ) && is_array( $other_tokens['header'] ) ) {
			$other_tokens_data['header'] = $this->get_other_tokens_data( $records, $other_tokens['header'] );
		}

		if ( isset( $other_tokens['body'] ) && is_array( $other_tokens['body'] ) ) {
			$other_tokens_data['body'] = $this->get_other_tokens_data( $records, $other_tokens['body'] );
		}

		if ( isset( $other_tokens['footer'] ) && is_array( $other_tokens['footer'] ) ) {
			$other_tokens_data['footer'] = $this->get_other_tokens_data( $records, $other_tokens['footer'] );
		}

		$sections_data = array();

		if ( isset( $sections['header'] ) && is_array( $sections['header'] ) && ! empty( $sections['header'] ) ) {
			foreach ( $sections['header']['section_token'] as $index => $sec ) {
				$tokens                            = $sections['header']['section_content_tokens'][ $index ];
				$sections_data['header'][ $index ] = $this->get_section_loop_data( $records, $tokens, $sec );
			}
		}
		if ( isset( $sections['body'] ) && is_array( $sections['body'] ) && ! empty( $sections['body'] ) ) {
			foreach ( $sections['body']['section_token'] as $index => $sec ) {
				$tokens                          = $sections['body']['section_content_tokens'][ $index ];
				$sections_data['body'][ $index ] = $this->get_section_loop_data( $records, $tokens, $sec );
			}
		}
		if ( isset( $sections['footer'] ) && is_array( $sections['footer'] ) && ! empty( $sections['footer'] ) ) {
			foreach ( $sections['footer'] as $index => $sec ) {
				$tokens                            = $sections['footer']['section_content_tokens'][ $index ];
				$sections_data['footer'][ $index ] = $this->get_section_loop_data( $records, $tokens, $sec );
			}
		}

		$information = array(
			'other_tokens_data' => $other_tokens_data,
			'sections_data'     => $sections_data,
		);

		return $information;
	}

	function get_other_tokens_data( $records, $tokens ) {

		$convert_context_name = array(
			'comment' => 'comments',
			'plugin'  => 'plugins',
			'profile' => 'profiles',
			'session' => 'sessions',
			'setting' => 'settings',
			'setting' => 'settings',
			'theme'   => 'themes',
			'posts'   => 'post',
			'pages'   => 'page',
			'user'    => 'users',
			'widget'  => 'widgets',
			'menu'    => 'menus',
			'backups' => 'mainwp_backups',
			'backup'  => 'mainwp_backups',
			'sucuri'  => 'mainwp_sucuri',
		);

		$convert_action_name = array(
			'restored' => 'untrashed',
			'spam'     => 'spammed',
			'backups'  => 'mainwp_backup',
			'backup'   => 'mainwp_backup',
		);

		$allowed_data = array(
			'count'
		);

		$token_values = array();

		if ( ! is_array( $tokens ) ) {
			$tokens = array();
		}

		foreach ( $tokens as $token ) {
			$str_tmp   = str_replace( array( '[', ']' ), '', $token );
			$array_tmp = explode( '.', $str_tmp );

			if ( is_array( $array_tmp ) ) {
				$context = $action = $data = '';
				if ( 2 === count( $array_tmp ) ) {
					list( $context, $data ) = $array_tmp;
				} else if ( 3 === count( $array_tmp ) ) {
					list( $context, $action, $data ) = $array_tmp;
				}

				$context = isset( $convert_context_name[ $context ] ) ? $convert_context_name[ $context ] : $context;
				if ( isset( $convert_action_name[ $action ] ) ) {
					$action = $convert_action_name[ $action ];
				}
				switch ( $data ) {
					case 'count':
						$count = 0;
						foreach ( $records as $record ) {
							if ( 'themes' === $context && 'edited' === $action ) {
								if ( $record->action !== 'updated' || $record->connector !== 'editor' ) {
									continue;
								}
							} else if ( 'users' === $context && 'updated' === $action ) {
								if ( $record->context !== 'profiles' || $record->connector !== 'users' ) {
									continue;
								}
							} else if ( 'mainwp_backups' === $context ) {
								if ( $record->context !== 'mainwp_backups' && $record->context !== 'backwpup_backups' &&  $record->context !== 'updraftplus_backups' && $record->context !== 'backupwordpress_backups' && $record->context !== 'backupbuddy_backups' ) {
									continue;
								}
							} else if ( 'mainwp_sucuri' === $context ) {
								if ( $record->context !== 'mainwp_sucuri' ) {
									continue;
								}
							} else if ( 'wordfence' === $context ) {
								if ( $record->context !== 'wordfence_scans' ) {
									continue;
								}
							} else if ( 'maintenance' === $context ) {
								if ( $record->context !== 'mainwp_maintenances' ) {
									continue;
								}
							} else {
								if ( $action !== $record->action ) {
									continue;
								}

								if ( 'comments' === $context && 'comments' !== $record->connector ) {
									continue;
								} else if ( 'media' === $context && 'media' !== $record->connector ) {
									continue;
								} else if ( 'widgets' === $context && 'widgets' !== $record->connector ) {
									continue;
								} else if ( 'menus' === $context && 'menus' !== $record->connector ) {
									continue;
								}

								if ( 'comments' !== $context && 'media' !== $context &&
								     'widgets' !== $context && 'menus' !== $context &&
								     $record->context !== $context
								) {
									continue;
								}

								if ( 'updated' === $action && ( 'post' === $context || 'page' === $context ) ) {
									$new_status = $this->get_stream_meta_data( $record, 'new_status' );
									if ( 'draft' === $new_status ) { // avoid auto save post
										continue;
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

	function get_section_loop_data( $records, $tokens, $section ) {

		$convert_context_name = array(
			'comment' => 'comments',
			'plugin'  => 'plugins',
			'profile' => 'profiles',
			'session' => 'sessions',
			'setting' => 'settings',
			'theme'   => 'themes',
			'posts'   => 'post',
			'pages'   => 'page',
			'widget'  => 'widgets',
			'menu'    => 'menus',
			'backups' => 'mainwp_backups',
			'backup'  => 'mainwp_backups',
			'sucuri'  => 'mainwp_sucuri',
		);

		$convert_action_name = array(
			'restored' => 'untrashed',
			'spam'     => 'spammed',
			'backup'   => 'mainwp_backup',
		);

		$some_allowed_data = array(
			'name',
			'title',
			'oldversion',
			'currentversion',
			'date',
            'time',
			'count',
			'author',
			'old.version',
			'current.version',
		);

		$context   = $action = '';
		$str_tmp   = str_replace( array( '[', ']' ), '', $section );
		$array_tmp = explode( '.', $str_tmp );
		if ( is_array( $array_tmp ) ) {
			if ( 2 === count( $array_tmp ) ) {
				list( $str1, $context ) = $array_tmp;
			} else if ( 3 === count( $array_tmp ) ) {
				list( $str1, $context, $action ) = $array_tmp;
			}
		}

		$context = isset( $convert_context_name[ $context ] ) ? $convert_context_name[ $context ] : $context;
		$action  = isset( $convert_action_name[ $action ] ) ? $convert_action_name[ $action ] : $action;

		$loops      = array();
		$loop_count = 0;

		foreach ( $records as $record ) {
			$theme_edited = $users_updated = $plugin_edited = false;

			if ( $plugin_edited ) {
				// ok next
			} else if ( 'themes' === $context && 'edited' === $action ) {
				if ( $record->action !== 'updated' || $record->connector !== 'editor' ) {
					continue;
				} else {
					$theme_edited = true;
				}
			} else if ( 'users' === $context && 'updated' === $action ) {
				if ( $record->context !== 'profiles' || $record->connector !== 'users' ) {
					continue;
				} else {
					$users_updated = true;
				}
			} else if ( 'mainwp_backups' === $context ) {
				if ( $record->context !== 'mainwp_backups' && $record->context !== 'backwpup_backups' && $record->context !== 'updraftplus_backups' && $record->context !== 'backupwordpress_backups'  && $record->context !== 'backupbuddy_backups' ) {
					continue;
				}
			} else if ( 'mainwp_sucuri' === $context ) {
				if ( $record->context !== 'mainwp_sucuri' ) {
					continue;
				}
			} else if ( 'wordfence' === $context ) {
                if ( $record->context !== 'wordfence_scans' ) {
                        continue;
                }
            } else if ( 'maintenance' === $context ) {
                if ( $record->context !== 'mainwp_maintenances' ) {
                        continue;
                }
            } else {
				if ( $action !== $record->action ) {
					continue;
				}

				if ( 'comments' === $context && 'comments' !== $record->connector ) {
					continue;
				} else if ( 'media' === $context && 'media' !== $record->connector ) {
					continue;
				} else if ( 'widgets' === $context && 'widgets' !== $record->connector ) {
					continue;
				} else if ( 'menus' === $context && 'menus' !== $record->connector ) {
					continue;
				}
				//                else if ($context === "themes" && $record->connector !== "themes")
				//                    continue;

				if ( 'comments' !== $context && 'media' !== $context &&
				     'widgets' !== $context && 'menus' !== $context &&
				     $record->context !== $context
				) {
					continue;
				}

				if ( 'updated' === $action && ( 'post' === $context || 'page' === $context ) ) {
					$new_status = $this->get_stream_meta_data( $record, 'new_status' );
					if ( 'draft' === $new_status ) { // avoid auto save post
						continue;
					}
				}
			}

			$token_values = array();

			foreach ( $tokens as $token ) {
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
						if ( 'name' === $data ) {
							if ( $theme_edited ) {
								$data = 'theme_name';
							} else if ( $plugin_edited ) {
								$data = 'plugin_name';
							} else if ( $users_updated ) {
								$data = 'display_name';
							}
						}

						if ( 'roles' === $data && $users_updated ) {
							$user_info = get_userdata( $record->object_id );
							if ( ! ( is_object( $user_info ) && $user_info instanceof WP_User ) ) {
								$roles = '';
							} else {
								$roles = implode( ', ', $user_info->roles );
							}
							$token_values[ $token ] = $roles;
						} else {
							$token_values[ $token ] = $this->get_stream_meta_data( $record, $data );
						}
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
						$data  = 'author_meta';
						$value = $this->get_stream_meta_data( $record, $data );
						if ( empty( $value ) && 'comments' === $context ) {
							$value = __( 'Guest', 'mainwp-child-reports' );
						}
						$token_values[ $token ] = $value;
						break;
					case 'status':   // sucuri cases
					case 'webtrust':
						if ( 'mainwp_sucuri' === $context ) {
							$token_values[ $token ] = $this->get_stream_meta_data( $record, $data );
						} else {
							$token_values[ $token ] = $value;
						}
						break;
                    case 'details':
                    case 'result':
						if ( 'wordfence' === $context || 'maintenance' === $context ) {
                            $token_values[ $token ] = $this->get_stream_meta_data( $record, $data );
						}
						break;
					case 'destination':   // backup cases
					case 'type':
						if ( 'mainwp_backups' === $context ) {
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
				if ( 'author_meta' === $meta_key || 'user_meta' === $meta_key ) {
					$value = maybe_unserialize( $value );
					$value = $value['display_name'];
				}
			}
		}

		return $value;
	}

	function set_showhide() {
		MainWP_Helper::update_option( 'mainwp_creport_ext_branding_enabled', 'Y', 'yes' );
		$hide = isset( $_POST['showhide'] ) && ( 'hide' === $_POST['showhide'] ) ? 'hide' : '';
		MainWP_Helper::update_option( 'mainwp_creport_branding_stream_hide', $hide );
		$information['result'] = 'SUCCESS';

		return $information;
	}

	public function creport_init() {
		if ( get_option( 'mainwp_creport_ext_branding_enabled' ) !== 'Y' ) {
			return;
		}

		if ( get_option( 'mainwp_creport_branding_stream_hide' ) === 'hide' ) {
			add_filter( 'all_plugins', array( $this, 'creport_branding_plugin' ) );
			add_action( 'admin_menu', array( $this, 'creport_remove_menu' ) );
			add_filter( 'site_transient_update_plugins', array( &$this, 'remove_update_nag' ) );
		}
	}

	function remove_update_nag( $value ) {
		if ( isset( $_POST['mainwpsignature'] ) ) {
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

