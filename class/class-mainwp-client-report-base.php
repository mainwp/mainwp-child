<?php
/**
 * MainWP Client Reports Base
 *
 * MainWP Client Reprots Extension handler.
 * Extension URL: https://mainwp.com/extension/client-reports/
 *
 * @package MainWP\Child
 */

namespace MainWP\Child;

/**
 * Class MainWP_Client_Report_Base
 *
 * MainWP Client Reports extension handler, extened by the MainWP_Client_Report class.
 */
class MainWP_Client_Report_Base {

	/**
	 * Public static variable to hold the single instance of the class.
	 *
	 * @var mixed Default null
	 */
	public static $instance = null;

	/**
	 * Method get_class_name()
	 *
	 * Get class name.
	 *
	 * @return string __CLASS__ Class name.
	 */
	public static function get_class_name() {
		return __CLASS__;
	}

	/**
	 * Public static variable to hold the single instance of the class.
	 *
	 * @var mixed Default null
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Convert context name of tokens to context name saved in child reports.
	 *
	 * @param string $context Context name to be converted.
	 *
	 * @return string $context Converted context name.
	 */
	public function get_compatible_context( $context ) {
		// some context are not different.
		$mapping_contexts = array(
			'comment'     => 'comments', // actual context values: post, page.
			'plugin'      => 'plugins',
			'users'       => 'profiles',
			'user'        => 'profiles',
			'session'     => 'sessions',
			'setting'     => 'settings',
			'theme'       => 'themes',
			'posts'       => 'post',
			'pages'       => 'page',
			'widgets'     => 'widgets',
			'widget'      => 'widgets',
			'menu'        => 'menus',
			'backups'     => 'backups',
			'backup'      => 'backups',
			'sucuri'      => 'sucuri_scan',
			'maintenance' => 'mainwp_maintenance',
			'wordfence'   => 'wordfence_scan',
			'backups'     => 'backups',
			'backup'      => 'backups',
			'media'       => 'media',
		);

		$context = isset( $mapping_contexts[ $context ] ) ? $mapping_contexts[ $context ] : $context;

		return strtolower( $context );
	}

	/**
	 * Get connector by compatible context.
	 *
	 * @param string $context Context name as reference.
	 *
	 * @return string $connector Required connector.
	 */
	public function get_connector_by_compatible_context( $context ) {

		$connector = '';

		$mapping_connectors = array(
			'plugins'            => 'installer',
			'themes'             => 'installer',
			'wordpress'          => 'installer', // phpcs:ignore -- wordpress -> WordPress.
			'profiles'           => 'users',
			'comments'           => 'comments',
			'settings'           => 'settings',
			'post'               => 'posts',
			'page'               => 'posts',
			'widgets'            => 'widgets',
			'menus'              => 'menus',
			'backups'            => 'mainwp_backups',
			'sucuri_scan'        => 'mainwp_sucuri',
			'mainwp_maintenance' => 'mainwp_maintenance',
			'wordfence_scan'     => 'mainwp_wordfence',
			'media'              => 'media',
		);

		if ( isset( $mapping_connectors[ $context ] ) ) {
			$connector = $mapping_connectors[ $context ];
		}

		return $connector;
	}

	/**
	 * Get compatible action by context.
	 *
	 * @param string $action  Action name as reference.
	 * @param string $context Context name as reference.
	 *
	 * @return string $action Compatible action.
	 */
	public function get_compatible_action( $action, $context = '' ) {

		$mapping_actions = array(
			'restored' => 'untrashed',
			'spam'     => 'spammed',
		);

		if ( isset( $mapping_actions[ $action ] ) ) {
			return $mapping_actions[ $action ];
		}

		if ( 'mainwp_maintenance' == $context ) {
			if ( 'process' == $action ) {
				$action = 'maintenance';
			}
		} elseif ( 'sucuri_scan' == $context ) {
			if ( 'checks' == $action ) {
				$action = 'sucuri_scan';
			}
		} elseif ( 'wordfence_scan' == $context ) {
			if ( 'scan' == $action ) {
				$action = 'wordfence_scan';
			}
		}
		return $action;
	}

	/**
	 * Get the Stream parameters.
	 *
	 * @param array $other_tokens An array containing other tokens.
	 * @param array $sections     An array containing sections.
	 *
	 * @return array Arguments array.
	 *
	 * @uses \MainWP\Child\MainWP_Child_Branding::is_branding()
	 */
	public function get_stream_get_params( $other_tokens, $sections ) {

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

		$args = array();
		foreach ( $allowed_params as $param ) {
			$paramval = \wp_mainwp_stream_filter_input( INPUT_POST, $param );
			if ( $paramval || '0' === $paramval ) {
				$args[ $param ] = $paramval;
			}
		}

		foreach ( $args as $arg => $val ) {
			if ( ! in_array( $arg, $allowed_params ) ) {
				unset( $args[ $arg ] );
			}
		}

		$exclude_connector_posts = $this->get_stream_get_not_in_params( $sections, $other_tokens );
		if ( $exclude_connector_posts ) {
			$args['connector__not_in'] = array( 'posts' );
		}

		$args['action__not_in'] = array( 'login' );

		$args['with-meta'] = 1;

		if ( isset( $args['date_from'] ) ) {
			$args['date_from'] = date( 'Y-m-d', $args['date_from'] ); // phpcs:ignore -- required to achieve desired results, pull request solutions appreciated.
		}

		if ( isset( $args['date_to'] ) ) {
			$args['date_to'] = date( 'Y-m-d', $args['date_to'] ); // phpcs:ignore -- required to achieve desired results, pull request solutions appreciated.
		}

		if ( MainWP_Child_Branding::instance()->is_branding() ) {
			$args['hide_child_reports'] = 1;
		}

		$args['records_per_page'] = 599999;

		return $args;
	}

	/**
	 * Get the Stream excluded parameters.
	 *
	 * @param array $sections     An array containing sections.
	 * @param array $other_tokens An array containing other tokens.
	 *
	 * @return bool true|false
	 */
	private function get_stream_get_not_in_params( $sections, $other_tokens ) {

		$exclude_connector_posts = true;

		$parts = array( 'header', 'body', 'footer' );
		foreach ( $parts as $part ) {
			if ( isset( $sections[ $part ] ) && isset( $sections[ $part ]['section_token'] ) && is_array( $sections[ $part ]['section_token'] ) ) {
				foreach ( $sections[ $part ]['section_token'] as $sec ) {
					if ( false !== strpos( $sec, '[section.posts' ) || false !== strpos( $sec, '[section.pages' ) ) {
						$exclude_connector_posts = false;
						break;
					}
				}
			}
			if ( ! $exclude_connector_posts ) {
				break;
			}
		}

		if ( $exclude_connector_posts ) {
			foreach ( $parts as $part ) {
				if ( isset( $other_tokens[ $part ] ) && is_array( $other_tokens[ $part ] ) ) {
					foreach ( $other_tokens[ $part ] as $sec ) {
						if ( false !== strpos( $sec, '[post.' ) || false !== strpos( $sec, '[page.' ) ) {
							$exclude_connector_posts = false;
							break;
						}
					}
				}
				if ( ! $exclude_connector_posts ) {
					break;
				}
			}
		}
		return $exclude_connector_posts;
	}

	/**
	 * Get the Stream other tokens.
	 *
	 * @param array $records      An array containg actions records.
	 * @param array $other_tokens An array containing other tokens.
	 * @param array $skip_records An array containing skipped records.
	 *
	 * @return array Other tokens data.
	 */
	public function get_stream_others_tokens( $records, $other_tokens, $skip_records ) {
		$other_tokens_data = array();
		$parts             = array( 'header', 'body', 'footer' );
		foreach ( $parts as $part ) {
			if ( isset( $other_tokens[ $part ] ) && is_array( $other_tokens[ $part ] ) ) {
				$other_tokens_data[ $part ] = $this->get_other_tokens_data( $records, $other_tokens[ $part ], $skip_records );
			}
		}
		return $other_tokens_data;
	}

	/**
	 * Get the Stream sections data.
	 *
	 * @param array $records      An array containg actions records.
	 * @param array $sections     An array containing sections.
	 * @param array $skip_records An array containing skipped records.
	 *
	 * @return array Sections data.
	 */
	public function get_stream_sections_data( $records, $sections, $skip_records ) {
		$sections_data = array();
		$parts         = array( 'header', 'body', 'footer' );
		foreach ( $parts as $part ) {
			if ( isset( $sections[ $part ] ) && is_array( $sections[ $part ] ) && ! empty( $sections[ $part ] ) ) {
				foreach ( $sections[ $part ]['section_token'] as $index => $sec ) {
					$tokens                           = $sections[ $part ]['section_content_tokens'][ $index ];
					$sections_data[ $part ][ $index ] = $this->get_section_loop_data( $records, $tokens, $sec, $skip_records );
				}
			}
		}
		return $sections_data;
	}

	/**
	 * Fix logging for posts creation action.
	 *
	 * @param array $records An array containg actions records.
	 * @param array $skip_records An array containg records to skip.
	 */
	protected function fix_logs_posts_created( &$records, &$skip_records ) {

		$args = array(
			'post_type'   => 'post',
			'post_status' => 'publish',
			'date_query'  => array(
				'column' => 'post_date',
				'after'  => $args['date_from'],
				'before' => $args['date_to'],
			),
		);

		$result                = new \WP_Query( $args );
		$records_created_posts = $result->posts;

		if ( $records_created_posts ) {

			$count_records = count( $records );
			for ( $i = 0; $i < $count_records; $i++ ) {
				$record = $records[ $i ];
				if ( 'posts' == $record->connector && 'post' == $record->context && 'created' == $record->action ) {
					if ( ! in_array( $record->ID, $skip_records ) ) {
						$skip_records[] = $record->ID;
					}
				}
			}

			$post_authors = array();

			foreach ( $records_created_posts as $_post ) {
				$au_id = $_post->post_author;
				if ( ! isset( $post_authors[ $au_id ] ) ) {
					$au                     = get_user_by( 'id', $au_id );
					$post_authors[ $au_id ] = $au->display_name;
				}
				$au_name = $post_authors[ $au_id ];

				// simulate logging created posts record.
				$stdObj            = new \stdClass();
				$stdObj->ID        = 0; // simulate ID value.
				$stdObj->connector = 'posts';
				$stdObj->context   = 'post';
				$stdObj->action    = 'created';
				$stdObj->created   = $_post->post_date;
				$stdObj->meta      = array(
					'post_title' => array( $_post->post_title ),
					'user_meta'  => array( $au_name ),
				);

				$records[] = $stdObj;
			}
		}
	}

	/**
	 * Get the other tokens data.
	 *
	 * @param array $records      An array containg actions records.
	 * @param array $tokens       An array containg the tokens list.
	 * @param array $skip_records An array containg records to skip.
	 *
	 * @return array An array containg the tokens values.
	 */
	public function get_other_tokens_data( $records, $tokens, &$skip_records ) {

		$token_values = array();

		if ( ! is_array( $tokens ) ) {
			$tokens = array();
		}

		$backups_created_time_to_fix = array();

		foreach ( $tokens as $token ) {
			if ( isset( $token_values[ $token ] ) ) {
				continue;
			}
			$str_tmp   = str_replace( array( '[', ']' ), '', $token );
			$array_tmp = explode( '.', $str_tmp );
			if ( is_array( $array_tmp ) ) {
				$context = '';
				$action  = '';
				$data    = '';
				if ( 2 === count( $array_tmp ) ) {
					list( $context, $data ) = $array_tmp;
				} elseif ( 3 === count( $array_tmp ) ) {
					list( $context, $action, $data ) = $array_tmp;
				}
				$context = $this->get_compatible_context( $context );
				// to compatible with new version of child report.
				// to check condition for grabbing report data.
				$connector = $this->get_connector_by_compatible_context( $context );
				$action    = $this->get_compatible_action( $action, $context );
				// custom values.
				if ( 'profiles' == $context ) {
					if ( 'created' == $action || 'deleted' == $action ) {
						$context = 'users';
					}
				}
				switch ( $data ) {
					case 'count':
						$token_values[ $token ] = $this->get_other_tokens_count( $records, $connector, $context, $action, $skip_records, $backups_created_time_to_fix );
						break;
				}
			}
		}

		return $token_values;
	}

	/**
	 * Get the other tokens count.
	 *
	 * @param object $records                     Object containng reports records.
	 * @param string $connector                   Record connector.
	 * @param string $context                     Record context.
	 * @param string $action                      Record action.
	 * @param array  $skip_records                Records to skip.
	 * @param array  $backups_created_time_to_fix Backups created time.
	 *
	 * @return int The count number.
	 */
	private function get_other_tokens_count( $records, $connector, $context, $action, &$skip_records, &$backups_created_time_to_fix ) { // phpcs:ignore -- Current complexity is the only way to achieve desired results, pull request solutions appreciated.
		$count = 0;

		foreach ( $records as $record ) {
			// check connector.
			if ( 'editor' == $record->connector ) {
				if ( ! in_array( $context, array( 'plugins', 'themes' ) ) || 'updated' !== $action ) {
					continue;
				}
			} elseif ( $connector !== $record->connector ) {
				continue;
			}

			$valid_context = false;
			// check context.
			if ( 'comments' == $context ) { // multi values.
				$comment_contexts = array( 'post', 'page' );
				if ( ! in_array( $record->context, $comment_contexts ) ) {
					continue;
				}
				$valid_context = true;
			} elseif ( 'post' === $context && 'created' === $action ) {
				if ( in_array( $record->ID, $skip_records ) ) {
					continue;
				}
				$valid_context = true;
			} elseif ( 'menus' == $context ) {
				$valid_context = true; // ok, pass, don't check context.
			} elseif ( 'editor' == $record->connector ) {
				$valid_context = true; // ok, pass, checked above.
			} elseif ( 'media' == $connector && 'media' == $record->connector ) {
				$valid_context = true; // ok, pass, do not check context.
			} elseif ( 'widgets' == $connector && 'widgets' == $record->connector ) {
				$valid_context = true; // ok, pass, don't check context.
			}

			$valid_context = ( $valid_context || strtolower( $record->context ) == $context ) ? true : false;
			if ( ! $valid_context ) {
				continue;
			}
			// custom action value.
			if ( 'widgets' == $connector ) {
				if ( 'deleted' == $action ) {
					$action = 'removed'; // action saved in database.
				}
			}

			// check action.
			if ( 'backups' === $context ) {
				if ( ! $this->is_backup_action( $record->action ) ) {
					continue;
				}
				$created = strtotime( $record->created );
				if ( in_array( $created, $backups_created_time_to_fix ) ) {
					if ( ! in_array( $record->ID, $skip_records ) ) {
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
					if ( 'draft' === $new_status ) {
						continue;
					}
				} elseif ( 'updated' === $action && ( 'themes' === $context || 'plugins' === $context ) ) {
					$name = $this->get_stream_meta_data( $record, 'name' );
					if ( empty( $name ) ) {
						if ( ! in_array( $record->ID, $skip_records ) ) {
							$skip_records[] = $record->ID;
						}
						continue;
					} else {
						$old_version = $this->get_stream_meta_data( $record, 'old_version' );
						$version     = $this->get_stream_meta_data( $record, 'version' );
						if ( version_compare( $version, $old_version, '<=' ) ) {
							if ( ! in_array( $record->ID, $skip_records ) ) {
								$skip_records[] = $record->ID;
							}
							continue;
						}
					}
				}
			}
			$count ++;
		}
		return $count;
	}

	/**
	 * Get the section loop data.
	 *
	 * @param object $records      Object containng reports records.
	 * @param array  $tokens       An array containing report tokens.
	 * @param string $section      Section name.
	 * @param array  $skip_records Records to skip.
	 *
	 * @return array Section loop records.
	 */
	public function get_section_loop_data( $records, $tokens, $section, $skip_records = array() ) {

		$context = '';
		$action  = '';

		$str_tmp   = str_replace( array( '[', ']' ), '', $section );
		$array_tmp = explode( '.', $str_tmp );
		if ( is_array( $array_tmp ) ) {
			if ( 2 === count( $array_tmp ) ) {
				list( $str1, $context ) = $array_tmp;
			} elseif ( 3 === count( $array_tmp ) ) {
				list( $str1, $context, $action ) = $array_tmp;
			}
		}

		// get db $context value by mapping.
		$context = $this->get_compatible_context( $context );
		// to compatible with new version of child report.
		// to check condition for grabbing report data.
		$connector = $this->get_connector_by_compatible_context( $context );

		$action = $this->get_compatible_action( $action, $context );

		if ( 'profiles' == $context ) {
			if ( 'created' == $action || 'deleted' == $action ) {
				$context = 'users';
			}
		}

		return $this->get_section_loop_records( $records, $tokens, $connector, $context, $action, $skip_records );
	}

	/**
	 * Get the section loop records.
	 *
	 * @param object $records      Object containng reports records.
	 * @param array  $tokens       An array containing report tokens.
	 * @param string $connector    Record connector.
	 * @param string $context      Record context.
	 * @param string $action       Record action.
	 * @param array  $skip_records Records to skip.
	 *
	 * @return array Loops.
	 */
	public function get_section_loop_records( $records, $tokens, $connector, $context, $action, $skip_records ) {  // phpcs:ignore -- Current complexity is the only way to achieve desired results, pull request solutions appreciated.

		$loops      = array();
		$loop_count = 0;

		$max_items_get    = ( isset( $_POST['max_items_get'] ) && ! empty( $_POST['max_items_get'] ) ) ? intval( $_POST['max_items_get'] ) : 0;
		$limit_connectors = ( isset( $_POST['limit_reports'] ) && ! empty( $_POST['limit_reports'] ) ) ? intval( $_POST['limit_reports'] ) : array();

		if ( ! is_array( $limit_connectors ) || empty( $limit_connectors ) ) {
			$limit_connectors = array( 'mainwp_sucuri', 'mainwp_maintenance', 'mainwp_backups' );
		}

		foreach ( $records as $record ) {

			if ( in_array( $record->ID, $skip_records ) ) {
				continue;
			}

			if ( 'editor' == $record->connector ) {
				if ( ! in_array( $context, array( 'plugins', 'themes' ) ) || 'updated' !== $action ) {
					continue;
				}
			} elseif ( $connector !== $record->connector ) {
				continue;
			}

			$valid_context = false;

			if ( 'comments' == $context ) {
				$comment_contexts = array( 'post', 'page' );
				if ( ! in_array( $record->context, $comment_contexts ) ) {
					continue;
				}
				$valid_context = true;
			} elseif ( 'menus' == $context ) {
				$valid_context = true; // ok, pass, don't check context.
			} elseif ( 'editor' == $record->connector ) {
				$valid_context = true; // ok, pass, checked above.
			} elseif ( 'media' == $connector && 'media' == $record->connector ) {
				$valid_context = true; // ok, pass, do not check context.
			} elseif ( 'widgets' == $connector && 'widgets' == $record->connector ) {
				$valid_context = true; // ok, pass, don't check context.
			}

			$valid_context = ( $valid_context || strtolower( $record->context ) == $context ) ? true : false;

			if ( ! $valid_context ) {
				continue;
			}

			// custom action value!
			if ( 'widgets' == $connector ) {
				if ( 'deleted' == $action ) {
					$action = 'removed'; // action saved in database!
				}
			}

			if ( 'backups' == $context ) {
				if ( ! $this->is_backup_action( $record->action ) ) {
					continue;
				}
			} elseif ( $action !== $record->action ) {
				continue;
			}

			if ( 'updated' === $action && ( 'post' === $context || 'page' === $context ) ) {
				$new_status = $this->get_stream_meta_data( $record, 'new_status' );
				if ( 'draft' === $new_status ) { // avoid auto save post!
					continue;
				}
			}
			$token_values = $this->get_section_loop_token_values( $record, $context, $tokens );
			if ( ! empty( $token_values ) ) {
				$loops[ $loop_count ] = $token_values;
				$loop_count ++;
			}

			if ( $max_items_get && ( $loop_count >= $max_items_get ) ) {
				if ( in_array( $connector, $limit_connectors ) ) {
					break;
				}
			}
		}
		return $loops;
	}

	/**
	 * Check if it's backup action.
	 *
	 * @param string $action Record action.
	 *
	 * @return bool If backup action, return trie, if not, false.
	 */
	public function is_backup_action( $action ) {
		if ( in_array( $action, array( 'mainwp_backup', 'backupbuddy_backup', 'backupwordpress_backup', 'backwpup_backup', 'updraftplus_backup', 'wptimecapsule_backup', 'wpvivid_backup' ) ) ) {
			return true;
		}
		return false;
	}

	/**
	 * Get the section loop token values.
	 *
	 * @param object $record  Object containing the record data.
	 * @param string $context Record context.
	 * @param array  $tokens  An array containg the report tokens.
	 *
	 * @return array Token values.
	 *
	 * @uses \MainWP\Child\MainWP_Helper::log_debug()
	 */
	private function get_section_loop_token_values( $record, $context, $tokens ) {

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
				} elseif ( 2 === count( $array_tmp ) ) {
					list( $str1, $data ) = $array_tmp;
				} elseif ( 3 === count( $array_tmp ) ) {
					list( $str1, $str2, $data ) = $array_tmp;
				}

				if ( 'version' === $data ) {
					if ( 'old' === $str2 ) {
						$data = 'old_version';
					} elseif ( 'current' === $str2 && 'wordpress' === $str1 ) { // phpcs:ignore -- wordpress -> WordPress.
						$data = 'new_version';
					}
				}
			}

			if ( 'role' === $data ) {
				$data = 'roles';
			}

			$tok_value = $this->get_section_token_value( $record, $data, $context, $token );

			$token_values[ $token ] = $tok_value;

			if ( empty( $tok_value ) ) {
				$msg = 'MainWP Child Report:: skip empty value :: token :: ' . $token . ' :: record :: ' . print_r( $record, true );  // phpcs:ignore -- required to achieve desired results, pull request solutions appreciated.
				MainWP_Helper::log_debug( $msg );
			}
		}
		return $token_values;
	}

	/**
	 * Get the section token value.
	 *
	 * @param object $record  Object containing the record data.
	 * @param string $data    Data to process.
	 * @param string $context Record context.
	 * @param string $token   Requested token.
	 *
	 * @return array Token value.
	 *
	 * @uses \MainWP\Child\MainWP_Helper::format_date()
	 * @uses \MainWP\Child\MainWP_Helper::format_time()
	 */
	public function get_section_token_value( $record, $data, $context, $token ) {  // phpcs:ignore -- Current complexity is the only way to achieve desired results, pull request solutions appreciated.
		$tok_value = '';
		switch ( $data ) {
			case 'ID':
				$tok_value = $record->ID;
				break;
			case 'date':
				$tok_value = MainWP_Helper::format_date( MainWP_Helper::get_timestamp( strtotime( $record->created ) ) );
				break;
			case 'time':
				$tok_value = MainWP_Helper::format_time( MainWP_Helper::get_timestamp( strtotime( $record->created ) ) );
				break;
			case 'area':
				$data      = 'sidebar_name';
				$tok_value = $this->get_stream_meta_data( $record, $data );
				break;
			case 'name':
			case 'version':
			case 'old_version':
			case 'new_version':
			case 'display_name':
			case 'roles':
				if ( 'name' == $data ) {
					if ( 'profiles' == $context ) {
						$data = 'display_name';
					}
				}
				$tok_value = $this->get_stream_meta_data( $record, $data );
				break;
			case 'title':
				if ( 'comments' === $context ) {
					$tok_value = $record->summary;
				} else {
					if ( 'page' === $context || 'post' === $context ) {
						$data = 'post_title';
					} elseif ( 'menus' === $record->connector ) {
						$data = 'name';
					}
					$tok_value = $this->get_stream_meta_data( $record, $data );
				}
				break;
			case 'author':
				$tok_value = $this->get_author_data_token_value( $record, $connector, $context, $data );

				break;
			case 'status':
			case 'webtrust':
				$value = '';
				if ( 'sucuri_scan' === $context ) {
					$value = $this->get_sucuri_scan_token_value( $record, $data );
				}
				$tok_value = $value;
				break;
			case 'details':
			case 'result':
				$tok_value = $this->get_result_data_token_value( $record, $context, $data );
				break;
			case 'type':
				if ( 'backups' === $context ) {
					$tok_value = $this->get_stream_meta_data( $record, $data );
				} else {
					$tok_value = $token;
				}
				break;
			default:
				$tok_value = 'N/A';
				break;
		}
		return $tok_value;
	}

	/**
	 * Get the Stream meta data for a certain record.
	 *
	 * @param object $record Object containing the record data.
	 * @param string $data   Data to process.
	 *
	 * @return string Return the meta data value.
	 */
	public function get_stream_meta_data( $record, $data ) {

		if ( empty( $record ) ) {
			return '';
		}

		$meta_key = $data;

		$value = '';

		if ( isset( $record->meta ) ) {
			$meta = $record->meta;

			if ( isset( $meta[ $meta_key ] ) ) {
				$value = $meta[ $meta_key ];
				$value = ( 'user_meta' == $meta_key && isset( $value[1] ) ) ? $value[1] : current( $value );

				if ( 'author_meta' === $meta_key ) {
					$value = maybe_unserialize( $value );
					if ( is_array( $value ) ) {
						$value = $value['display_name'];
						// fix empty author value!
						if ( empty( $value ) ) {
							if ( isset( $value['agent'] ) && ! empty( $value['agent'] ) ) {
								$value = $value['agent'];
							}
						}
					}
					if ( ! is_string( $value ) ) {
						$value = '';
					}
				}
			}
		}

		return $value;
	}

	/**
	 * Get the author data token value.
	 *
	 * @param object $record    Object containing the record data.
	 * @param string $connector Record connector.
	 * @param string $context   Record context.
	 * @param string $data      Data to process.
	 *
	 * @return string Author data token value.
	 */
	private function get_author_data_token_value( $record, $connector, $context, $data ) {
		if ( 'comment' == $connector ) {
			$data = 'user_name';
		} else {
			$data = 'user_meta';
		}
		$value = $this->get_stream_meta_data( $record, $data );

		if ( empty( $value ) && 'comments' === $context ) {
			$value = __( 'Guest', 'mainwp-child' );
		}

		// check compatibility with old meta data.
		if ( empty( $value ) ) {
			$value = $this->get_stream_meta_data( $record, 'author_meta' );
		}

		return $value;
	}

	/**
	 * Get the result data token value.
	 *
	 * @param object $record  Object containing the record data.
	 * @param string $context Record context.
	 * @param string $data    Data to process.
	 *
	 * @return string Result data token value.
	 */
	private function get_result_data_token_value( $record, $context, $data ) {
		if ( 'mainwp_maintenance' === $context && 'details' == $data ) {
			$tok_value = $this->get_mainwp_maintenance_token_value( $record, $data );
		} elseif ( 'wordfence_scan' === $context || 'mainwp_maintenance' === $context ) {
			$meta_value = $this->get_stream_meta_data( $record, $data );
			if ( 'wordfence_scan' === $context ) {
				if ( 'result' == $data ) {
					$completed_log  = __( 'Scan complete. Congratulations, no new problems found.', 'wordfence' );
					$str_loc1       = MainWP_Child_Wordfence::instance()->get_substr( $completed_log, 2 ); // loc string.
					$str_loc2       = MainWP_Child_Wordfence::instance()->get_substr( $completed_log, 3 ); // loc string.
					$congra_str_loc = str_replace( $str_loc1, '', $str_loc2 );
					$congra_str_loc = trim( $congra_str_loc, ' ,' );

					// SUM_FINAL:Scan complete. You have xxx new issues to fix. See below.
					// SUM_FINAL:Scan complete. Congratulations, no new problems found.
					if ( stripos( $meta_value, 'Congratulations' ) || stripos( $meta_value, $congra_str_loc ) ) {
						$meta_value = 'No issues detected';
					} elseif ( stripos( $meta_value, 'You have' ) ) {
						$meta_value = 'Issues Detected';
					} else {
						$meta_value = '';
					}
				} elseif ( 'details' == $data ) {
					$meta_value = str_replace( 'SUM_FINAL:', '', $meta_value );
				}
			}
			$tok_value = $meta_value;
		}
		return $tok_value;
	}

	/**
	 * Get the Sucuri scan token value.
	 *
	 * @param object $record Object containing the record data.
	 * @param string $data   Data to process.
	 *
	 * @return string Sucuri scan token value.
	 */
	private function get_sucuri_scan_token_value( $record, $data ) {
		$tok_value = '';
		$scan_data = $this->get_stream_meta_data( $record, 'scan_data' );
		if ( ! empty( $scan_data ) ) {
			$scan_data = maybe_unserialize( base64_decode( $scan_data ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions -- base64_encode required for backwards compatibility.
			if ( is_array( $scan_data ) ) {

				$blacklisted    = $scan_data['blacklisted'];
				$malware_exists = $scan_data['malware_exists'];

				$status = array();
				if ( $blacklisted ) {
					$status[] = __( 'Site Blacklisted', 'mainwp-child' ); }
				if ( $malware_exists ) {
					$status[] = __( 'Site With Warnings', 'mainwp-child' ); }

				if ( 'status' == $data ) {
					$tok_value = count( $status ) > 0 ? implode( ', ', $status ) : __( 'Verified Clear', 'mainwp-child' );
				} elseif ( 'webtrust' == $data ) {
					$tok_value = $blacklisted ? __( 'Site Blacklisted', 'mainwp-child' ) : __( 'Trusted', 'mainwp-child' );
				}
			}
		} else {
			$tok_value = $this->get_stream_meta_data( $record, $data );
		}
		return $tok_value;
	}

	/**
	 * Get the Maintanence token value.
	 *
	 * @param object $record Object containing the record data.
	 * @param string $data   Data to process.
	 *
	 * @return string Maintanence token value.
	 */
	private function get_mainwp_maintenance_token_value( $record, $data ) {

		$maintenance_details = array(
			'revisions'     => __( 'Delete all post revisions', 'mainwp-child' ),
			'revisions_max' => __( 'Delete all post revisions, except for the last:', 'mainwp-child' ),
			'autodraft'     => __( 'Delete all auto draft posts', 'mainwp-child' ),
			'trashpost'     => __( 'Delete trash posts', 'mainwp-child' ),
			'spam'          => __( 'Delete spam comments', 'mainwp-child' ),
			'pending'       => __( 'Delete pending comments', 'mainwp-child' ),
			'trashcomment'  => __( 'Delete trash comments', 'mainwp-child' ),
			'tags'          => __( 'Delete tags with 0 posts associated', 'mainwp-child' ),
			'categories'    => __( 'Delete categories with 0 posts associated', 'mainwp-child' ),
			'optimize'      => __( 'Optimize database tables', 'mainwp-child' ),
		);

		$meta_value = $this->get_stream_meta_data( $record, $data );
		$meta_value = explode( ',', $meta_value );

		$details = array();

		if ( is_array( $meta_value ) ) {
			foreach ( $meta_value as $mt ) {
				if ( isset( $maintenance_details[ $mt ] ) ) {
					if ( 'revisions_max' == $mt ) {
						$max_revisions = $this->get_stream_meta_data( $record, 'revisions' );
						$dtl           = $maintenance_details['revisions_max'] . ' ' . $max_revisions;
					} else {
						$dtl = $maintenance_details[ $mt ];
					}
					$details[] = $dtl;
				}
			}
		}
		$tok_value = implode( ', ', $details );
		return $tok_value;
	}
}
