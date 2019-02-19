<?php

/*
 *
 * Credits
 *
 * Plugin-Name: Broken Link Checker
 * Plugin URI: https://wordpress.org/plugins/broken-link-checker/
 * Author: Janis Elsts, Vladimir Prelovac
 *
 * The code is used for the MainWP Broken Links Checker Extension
 * Extension URL: https://mainwp.com/extension/broken-links-checker/
 *
*/

class MainWP_Child_Links_Checker {

	public static $instance = null;
    public $is_plugin_installed = false;

	static function Instance() {
		if ( null === MainWP_Child_Links_Checker::$instance ) {
			MainWP_Child_Links_Checker::$instance = new MainWP_Child_Links_Checker();
		}

		return MainWP_Child_Links_Checker::$instance;
	}

	public function __construct() {
        require_once( ABSPATH . 'wp-admin/includes/plugin.php' );
		if ( is_plugin_active( 'broken-link-checker/broken-link-checker.php' ) ) {
                    $this->is_plugin_installed = true;
		}

        if ( !$this->is_plugin_installed )
            return;

        add_filter( 'mainwp-site-sync-others-data', array( $this, 'syncOthersData' ), 10, 2 );

	}

	public function action() {
		$information = array();
		if ( ! defined( 'BLC_ACTIVE' ) || ! function_exists( 'blc_init' ) ) {
			$information['error'] = 'NO_BROKENLINKSCHECKER';
			MainWP_Helper::write( $information );
		}
		blc_init();

        MainWP_Helper::update_option( 'mainwp_linkschecker_ext_enabled', 'Y', 'yes' );
        // need this try()
        try {
            if ( isset( $_POST['mwp_action'] ) ) {
                switch ( $_POST['mwp_action'] ) {
                    case 'set_showhide':
                        $information = $this->set_showhide();
                        break;
                    case 'sync_data':
                        $information = $this->get_sync_data();
                        break;
                    case 'sync_links_data':
                        $information = $this->get_links_data();
                        break;
                    case 'edit_link':
                        $information = $this->edit_link();
                        break;
                    case 'unlink':
                        $information = $this->unlink();
                        break;
                    case 'set_dismiss':
                        $information = $this->set_link_dismissed();
                        break;
                    case 'discard':
                        $information = $this->discard();
                        break;
                    case 'save_settings':
                        $information = $this->save_settings();
                        break;
                    case 'force_recheck':
                        $information = $this->force_recheck();
                        break;
                }
            }
            MainWP_Helper::write( $information );
        } catch(Exception $e) {
            MainWP_Helper::write( array('error' => $e->getMessage()) );
        }
	}


	public function init() {
		if ( get_option( 'mainwp_linkschecker_ext_enabled' ) !== 'Y' ) {
			return;
		}

		if ( get_option( 'mainwp_linkschecker_hide_plugin' ) === 'hide' ) {
			add_filter( 'all_plugins', array( $this, 'hide_plugin' ) );
			add_filter( 'update_footer', array( &$this, 'update_footer' ), 15 );
		}
	}

	public static function hook_trashed_comment( $comment_id ) {
		if ( get_option( 'mainwp_linkschecker_ext_enabled' ) !== 'Y' ) {
			return;
		}

		if ( ! defined( 'BLC_ACTIVE' ) || ! function_exists( 'blc_init' ) ) {
			return;
		}
		blc_init();
		$container = blcContainerHelper::get_container( array( 'comment', $comment_id ) );
		$container->delete();
		blc_cleanup_links();
	}

	function save_settings() {
		$information           = array();
		$check_threshold   = intval( $_POST['check_threshold'] );
		if ( $check_threshold > 0 ) {
			$conf                             = blc_get_configuration();
			$conf->options['check_threshold'] = $check_threshold;
			$conf->save_options();
		}
		$information['result'] = 'SUCCESS';
		return $information;
	}

	function force_recheck() {
		$this->initiate_recheck();
		$information           = array();
		$information['result'] = 'SUCCESS';

		return $information;
	}

	function initiate_recheck() {
		global $wpdb;
		/** @var wpdb $wpdb */

		//Delete all discovered instances
		$wpdb->query( "TRUNCATE {$wpdb->prefix}blc_instances" );

		//Delete all discovered links
		$wpdb->query( "TRUNCATE {$wpdb->prefix}blc_links" );

		//Mark all posts, custom fields and bookmarks for processing.
		blc_resynch( true );
	}


	public static function hook_post_deleted( $post_id ) {
		if ( get_option( 'mainwp_linkschecker_ext_enabled' ) !== 'Y' ) {
			return;
		}

		if ( ! defined( 'BLC_ACTIVE' ) || ! function_exists( 'blc_init' ) ) {
			return;
		}
		blc_init();

		//Get the container type matching the type of the deleted post
		$post = get_post( $post_id );
		if ( ! $post ) {
			return;
		}
		//Get the associated container object
		$post_container = blcContainerHelper::get_container( array( $post->post_type, intval( $post_id ) ) );

		if ( $post_container ) {
			//Delete it
			$post_container->delete();
			//Clean up any dangling links
			blc_cleanup_links();
		}
	}


	public function hide_plugin( $plugins ) {
		foreach ( $plugins as $key => $value ) {
			$plugin_slug = basename( $key, '.php' );
			if ( 'broken-link-checker' === $plugin_slug ) {
				unset( $plugins[ $key ] );
			}
		}

		return $plugins;
	}

	function update_footer( $text ) {
		?>
		<script>
			jQuery( document ).ready( function () {
				jQuery( '#menu-tools a[href="tools.php?page=view-broken-links"]' ).closest( 'li' ).remove();
				jQuery( '#menu-settings a[href="options-general.php?page=link-checker-settings"]' ).closest( 'li' ).remove();
			} );
		</script>
		<?php
		return $text;
	}


	function set_showhide() {
		$hide = isset( $_POST['showhide'] ) && ( 'hide' === $_POST['showhide'] ) ? 'hide' : '';
		MainWP_Helper::update_option( 'mainwp_linkschecker_hide_plugin', $hide );
		$information['result'] = 'SUCCESS';

		return $information;
	}

    // ok
    public function syncOthersData( $information, $data = array() ) {
        if ( isset( $data['syncBrokenLinksCheckerData'] ) && $data['syncBrokenLinksCheckerData'] ) {
            try{
                $information['syncBrokenLinksCheckerData'] = $this->get_sync_data();
            } catch(Exception $e) {

            }
        }
		return $information;
	}


	function get_sync_data( $strategy = '' ) {
		$information = array();
		$data        = $this->get_count_links();
        if (is_array($data))
            $information['data'] = $data;
		return $information;
	}

	function get_links_data() {

        if (!defined('BLC_DIRECTORY')) return;

        $file_path1 = BLC_DIRECTORY . '/includes/link-query.php';
        $file_path2 = BLC_DIRECTORY . '/includes/modules.php';
        MainWP_Helper::check_files_exists(array( $file_path1, $file_path2 ));

        require_once $file_path1;
        require_once $file_path2;

        MainWP_Helper::check_classes_exists('blcLinkQuery');
        MainWP_Helper::check_methods('blcLinkQuery', 'getInstance');

		$blc_link_query      = blcLinkQuery::getInstance();

        MainWP_Helper::check_methods($blc_link_query, 'get_filter_links');

		$total         = $blc_link_query->get_filter_links( 'all', array( 'count_only' => true ) );


		$max_results = isset($_POST['max_results']) ? intval($_POST['max_results']) : 50;
		$offset = isset($_POST['offset']) ? intval($_POST['offset']) : 0;

		$params      = array(
							array( 'load_instances' => true ),
							'max_results' => $max_results
						);

		if (empty($offset)) {
			$first_sync = true;
		} else {
			$params['offset'] = $offset;
		}

		$link_data = $this->links_checker_data($params);

		$total_sync = 0;
		if ($offset){
			$total_sync = $offset;
		}
		$total_sync += (is_array($link_data) ? count($link_data) : 0);

		$information = array('links_data' => $link_data);

		if ($first_sync) {
			$information['data'] = $this->get_count_links();
		}

		if ($total > $offset + $max_results ) {
			$information['sync_offset'] = $offset + $max_results;
		} else {
			$information['last_sync'] = 1;
			$information['total_sync'] = $total_sync;
			$information['data'] = $this->get_count_links();
		}

		$information['result'] = 'success';
		return $information;
	}

	function get_count_links() {
        if (!defined('BLC_DIRECTORY')) return;

        $file_path1 = BLC_DIRECTORY . '/includes/link-query.php';
        $file_path2 = BLC_DIRECTORY . '/includes/modules.php';

        MainWP_Helper::check_files_exists(array( $file_path1, $file_path2 ));

        require_once $file_path1;
        require_once $file_path2;

        MainWP_Helper::check_classes_exists('blcLinkQuery');
        MainWP_Helper::check_methods('blcLinkQuery', 'getInstance');

		$data = array();
		$blc_link_query      = blcLinkQuery::getInstance();

        MainWP_Helper::check_methods($blc_link_query, 'get_filter_links');

		$data['broken']      = $blc_link_query->get_filter_links( 'broken', array( 'count_only' => true ) );
		$data['redirects']   = $blc_link_query->get_filter_links( 'redirects', array( 'count_only' => true ) );
		$data['dismissed']   = $blc_link_query->get_filter_links( 'dismissed', array( 'count_only' => true ) );
		$data['warning']   = $blc_link_query->get_filter_links( 'warning', array( 'count_only' => true ) );
		$data['all']         = $blc_link_query->get_filter_links( 'all', array( 'count_only' => true ) );
		return $data;
	}

	function links_checker_data($params) {

        MainWP_Helper::check_functions('blc_get_links');
        MainWP_Helper::check_classes_exists('blcLink');

		$links      = blc_get_links( $params );

		$filter_fields = array(
			'link_id',
			'url',
			'being_checked',
			'last_check',
			'last_check_attempt',
			'check_count',
			'http_code',
			'request_duration',
			'timeout',
			'redirect_count',
			'final_url',
			'broken',
			'warning',
			'first_failure',
			'last_success',
			'may_recheck',
			'false_positive',
			//'result_hash',
			'dismissed',
			'status_text',
			'status_code',
			'log'
		);
		$return     = array();

		$blc_option = get_option( 'wsblc_options' );

		if ( is_string( $blc_option ) && ! empty( $blc_option ) ) {
			$blc_option = json_decode( $blc_option, true );
		}

		if ( is_array( $links ) ) {
			foreach ( $links as $link ) {
				$new_link = new stdClass();
				foreach ( $filter_fields as $field ) {
					$new_link->$field = $link->$field;
				}

				$extra_info = array();

				if ( ! empty( $link->post_date ) ) {
					$extra_info['post_date'] = $link->post_date;
				}

				$days_broken = 0;
				if ( $link->broken ) {
					//Add a highlight to broken links that appear to be permanently broken
					$days_broken = intval( ( time() - $link->first_failure ) / ( 3600 * 24 ) );
					if ( $days_broken >= $blc_option['failure_duration_threshold'] ) {
						$extra_info['permanently_broken'] = 1;
						if ( $blc_option['highlight_permanent_failures'] ) {
							$extra_info['permanently_broken_highlight'] = 1;
						}
					}
				}
				$extra_info['days_broken'] = $days_broken;
				$instances = false;

				$get_link = new blcLink( intval( $link->link_id ) );
				if ( $get_link->valid() ) {
                    MainWP_Helper::check_methods($get_link, 'get_instances');
					$instances = $get_link->get_instances();
				}

				if ( ! empty( $instances ) ) {
                    $first_instance      = reset( $instances );

                    MainWP_Helper::check_methods($first_instance, array( 'ui_get_link_text', 'get_container', 'is_link_text_editable', 'is_url_editable') );

					$new_link->link_text = $first_instance->ui_get_link_text();
					$extra_info['count_instance'] = count( $instances );
					$container           = $first_instance->get_container();

					/** @var blcContainer $container */

					if ( ! empty( $container ) /* && ($container instanceof blcAnyPostContainer) */ ) {
                        if (true === MainWP_Helper::check_properties($first_instance, array( 'container_field' ), true )) {
                            if (true === MainWP_Helper::check_properties($container, array( 'container_type', 'container_id' ), true )) {
                                $extra_info['container_type'] = $container->container_type;
                                $extra_info['container_id'] = $container->container_id;
                                $extra_info['source_data'] = $this->ui_get_source( $container, $first_instance->container_field );
                            }
                        }
					}

					$can_edit_text       = false;
					$can_edit_url        = false;
					$editable_link_texts = $non_editable_link_texts = array();

					foreach ( $instances as $instance ) {
						if ( $instance->is_link_text_editable() ) {
							$can_edit_text                               = true;
							$editable_link_texts[ $instance->link_text ] = true;
						} else {
							$non_editable_link_texts[ $instance->link_text ] = true;
						}

						if ( $instance->is_url_editable() ) {
							$can_edit_url = true;
						}
					}

					$link_texts     = $can_edit_text ? $editable_link_texts : $non_editable_link_texts;
					$data_link_text = '';
					if ( count( $link_texts ) === 1 ) {
						//All instances have the same text - use it.
						$link_text      = key( $link_texts );
						$data_link_text = esc_attr( $link_text );
					}
					$extra_info['data_link_text'] = $data_link_text;
					$extra_info['can_edit_url'] = $can_edit_url;
					$extra_info['can_edit_text'] = $can_edit_text;
				} else {
					$new_link->link_text = '';
					$extra_info['count_instance'] = 0;
				}
				$new_link->extra_info = base64_encode(serialize($extra_info));
				$new_link->synced = 1;
				$return[] = $new_link;
			}
		} else {
			return array();
		}

		return $return;

	}

	function edit_link() {
		$information = array();
		if ( ! current_user_can( 'edit_others_posts' ) ) {
			$information['error'] = 'NOTALLOW';

			return $information;
		}
		//Load the link
		$link = new blcLink( intval( $_POST['link_id'] ) );
		if ( ! $link->valid() ) {
			$information['error'] = 'NOTFOUNDLINK'; // Oops, I can't find the link
			return $information;
		}

		//Validate the new URL.
		$new_url = stripslashes( $_POST['new_url'] );
		$parsed  = @parse_url( $new_url );
		if ( ! $parsed ) {
			$information['error'] = 'URLINVALID'; // Oops, the new URL is invalid!
			return $information;
		}

		$new_text = ( isset( $_POST['new_text'] ) && is_string( $_POST['new_text'] ) ) ? stripslashes( $_POST['new_text'] ) : null;
		if ( '' === $new_text ) {
			$new_text = null;
		}
		if ( ! empty( $new_text ) && ! current_user_can( 'unfiltered_html' ) ) {
			$new_text = stripslashes( wp_filter_post_kses( addslashes( $new_text ) ) ); //wp_filter_post_kses expects slashed data.
		}

		$rez = $link->edit( $new_url, $new_text );
		if ( false === $rez ) {
			$information['error'] = __( 'An unexpected error occurred!', 'mainwp-child' );

			return $information;
		} else {
			$new_link = $rez['new_link'];
			/** @var blcLink $new_link */
			$new_status   = $new_link->analyse_status();
			$ui_link_text = null;
			if ( isset( $new_text ) ) {
				$instances = $new_link->get_instances();
				if ( ! empty( $instances ) ) {
					$first_instance = reset( $instances );
					$ui_link_text   = $first_instance->ui_get_link_text();
				}
			}

			$response = array(
				'new_link_id'  => $rez['new_link_id'],
				'cnt_okay'     => $rez['cnt_okay'],
				'cnt_error'    => $rez['cnt_error'],
				'status_text'  => $new_status['text'],
				'status_code'  => $new_status['code'],
				'http_code'    => empty( $new_link->http_code ) ? '' : $new_link->http_code,
				'url'          => $new_link->url,
				'link_text'    => isset( $new_text ) ? $new_text : null,
				'ui_link_text' => isset( $new_text ) ? $ui_link_text : null,
				'errors'       => array(),
			);
			//url, status text, status code, link text, editable link text

			foreach ( $rez['errors'] as $error ) {
				/** @var $error WP_Error */
				array_push( $response['errors'], implode( ', ', $error->get_error_messages() ) );
			}

			return $response;
		}
	}

	function unlink() {
		$information = array();
		if ( ! current_user_can( 'edit_others_posts' ) ) {
			$information['error'] = 'NOTALLOW';

			return $information;
		}

		if ( isset( $_POST['link_id'] ) ) {
			//Load the link
			$link = new blcLink( intval( $_POST['link_id'] ) );

			if ( ! $link->valid() ) {
				$information['error'] = 'NOTFOUNDLINK'; // Oops, I can't find the link
				return $information;
			}

			//Try and unlink it
			$rez = $link->unlink();

			if ( false === $rez ) {
				$information['error'] = 'UNDEFINEDERROR'; // An unexpected error occured!
				return $information;
			} else {
				$response = array(
					'cnt_okay'  => $rez['cnt_okay'],
					'cnt_error' => $rez['cnt_error'],
					'errors'    => array(),
				);
				foreach ( $rez['errors'] as $error ) {
					/** @var WP_Error $error */
					array_push( $response['errors'], implode( ', ', $error->get_error_messages() ) );
				}

				return $response;
			}
		} else {
			$information['error'] = __( 'Error: link_id is not specified.', 'mainwp-child' );

			return $information;
		}
	}

	private function set_link_dismissed() {
		$information = array();
		$dismiss     = $_POST['dismiss'];

		if ( ! current_user_can( 'edit_others_posts' ) ) {
			$information['error'] = 'NOTALLOW';

			return $information;
		}

		if ( isset( $_POST['link_id'] ) ) {
			//Load the link
			$link = new blcLink( intval( $_POST['link_id'] ) );

			if ( ! $link->valid() ) {
				$information['error'] = 'NOTFOUNDLINK'; // Oops, I can't find the link
				return $information;
			}

			$link->dismissed = $dismiss;

			//Save the changes
			if ( $link->save() ) {
				$information = 'OK';
			} else {
				$information['error'] = 'COULDNOTMODIFY'; // Oops, couldn't modify the link
			}

			return $information;
		} else {
			$information['error'] = __( 'Error: link_id not specified.', 'mainwp-child' );

			return $information;
		}
	}

	private function discard() {
		$information = array();
		if ( ! current_user_can( 'edit_others_posts' ) ) {
			$information['error'] = 'NOTALLOW';

			return $information;
		}
		if ( isset( $_POST['link_id'] ) ) {
			//Load the link
			$link = new blcLink( intval( $_POST['link_id'] ) );

			if ( ! $link->valid() ) {
				$information['error'] = 'NOTFOUNDLINK'; // Oops, I can't find the link
				return $information;
			}

			//Make it appear "not broken"
			$link->broken             = false;
			$link->false_positive     = true;
			$link->last_check_attempt = time();
			$link->log                = __( 'This link was manually marked as working by the user.', 'mainwp-child' );

			//Save the changes
			if ( $link->save() ) {
				$information['status']             = 'OK';
				$information['last_check_attempt'] = $link->last_check_attempt;
			} else {
				$information['error'] = 'COULDNOTMODIFY'; // Oops, couldn't modify the link
			}
		} else {
			$information['error'] = __( 'Error: link_id is not specified.', 'mainwp-child' );
		}

		return $information;
	}

	function ui_get_source( $container, $container_field = '' ) {
		if ( 'comment' === $container->container_type ) {
			return $this->ui_get_source_comment( $container, $container_field );
		} else if ( $container instanceof blcAnyPostContainer ) {
			return $this->ui_get_source_post( $container, $container_field );
		}

		return array();
	}

	function ui_get_source_comment( $container, $container_field = '' ) {
		//Display a comment icon.
		if ( 'comment_author_url' === $container_field ) {
			$image = 'font-awesome/font-awesome-user.png';
		} else {
			$image = 'font-awesome/font-awesome-comment-alt.png';
		}

        if (true !== MainWP_Helper::check_methods($container, array( 'get_wrapped_object'), true ))
            return false;

        $comment = $container->get_wrapped_object();

		//Display a small text sample from the comment
		$text_sample = strip_tags( $comment->comment_content );
		$text_sample = blcUtility::truncate( $text_sample, 65 );

		return array(
			'image'                 => $image,
			'text_sample'           => $text_sample,
			'comment_author'        => esc_attr( $comment->comment_author ),
			'comment_id'            => esc_attr( $comment->comment_ID ),
			'comment_status'        => wp_get_comment_status( $comment->comment_ID ),
			'container_post_title'  => get_the_title( $comment->comment_post_ID ),
			'container_post_status' => get_post_status( $comment->comment_post_ID ),
			'container_post_ID'     => $comment->comment_post_ID,
		);
	}

	function ui_get_source_post( $container, $container_field = '' ) {
		return array(
			'post_title'        => get_the_title( $container->container_id ),
			'post_status'		=> get_post_status($container->container_id),
			'container_anypost' => true,
		);
	}
}

