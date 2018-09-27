<?php

class MainWP_Keyword_Links {
	public static $instance = null;
	protected $config;
	protected $keyword_links;
	protected $server;
	protected $siteurl;
	protected $link_temp;
	protected $link_count_temp;
	protected $link_count_each_temp;
	protected $link_exact_match = 1;
	protected $link_case_sensitive = 1;

	static function Instance() {
		if ( null === MainWP_Keyword_Links::$instance ) {
			MainWP_Keyword_Links::$instance = new MainWP_Keyword_Links();
		}

		return MainWP_Keyword_Links::$instance;
	}

	public function __construct() {
		global $wpdb;
		$this->server = get_option( 'mainwp_child_server' );
		add_action( 'wp_ajax_keywordLinksSaveClick', array( $this, 'saveClickCallback' ) );
		add_action( 'wp_ajax_nopriv_keywordLinksSaveClick', array( $this, 'saveClickCallback' ) );
		add_action( 'template_redirect', array( $this, 'keywordLinksJS' ) );
		$this->config        = get_option( 'mainwp_kwl_options', array() );
		$this->keyword_links = get_option( 'mainwp_kwl_keyword_links', array() );
		if ( empty( $this->keyword_links ) ) {
			$this->keyword_links = array();
		}
		//print_r($this->keyword_links);
		$this->siteurl = get_option( 'home' );
		add_action( 'permalink_structure_changed', array( &$this, 'permalinkChanged' ), 10, 2 );
	}


	public function keywordLinksJS() {
		if ( ! is_admin() && get_option( 'mainwp_kwl_enable_statistic' ) ) {
			wp_enqueue_script( 'jquery' );
			wp_enqueue_script( 'keywordLinks', plugins_url( '/js/keywordlinks.js', dirname( __FILE__ ) ) );
			add_action( 'wp_head', array( $this, 'head_loading' ), 1 );
		}
	}

	public function head_loading() {
		?>
		<script type="text/javascript">
			var kwlAjaxUrl = "<?php echo esc_js( admin_url( 'admin-ajax.php' ) ); ?>";
			var kwlNonce = "<?php echo esc_js( wp_create_nonce( 'keywordLinksSaveClick' ) ); ?>";
			var kwlIp ="<?php echo esc_html( $_SERVER['REMOTE_ADDR'] ); ?>";
			var kwlReferer ="<?php echo esc_html( $_SERVER['HTTP_REFERER'] ); ?>";
		</script>
		<?php
	}


	public function permalinkChanged( $old_struct, $new_struct ) {
		if ( '1' !== get_option( 'mainwpKeywordLinks' ) ) {
			if ( 'yes' === get_option( 'mainwp_keyword_links_htaccess_set' ) ) {
				$this->update_htaccess( false, true ); // force clear
			}
		} else {
			$this->update_htaccess( true ); // force update
		}
	}

	function mod_rewrite_rules( $pRules ) {
		$home_root = parse_url( home_url() );
		if ( isset( $home_root['path'] ) ) {
			$home_root = trailingslashit( $home_root['path'] );
		} else {
			$home_root = '/';
		}

		$rules = "<IfModule mod_rewrite.c>\n";
		$rules .= "RewriteEngine On\n";
		$rules .= "RewriteBase $home_root\n";

		//add in the rules that don't redirect to WP's index.php (and thus shouldn't be handled by WP at all)
		foreach ( $pRules as $match => $query ) {
			// Apache 1.3 does not support the reluctant (non-greedy) modifier.
			$match = str_replace( '.+?', '.+', $match );

			$rules .= 'RewriteRule ^' . $match . ' ' . $home_root . $query . " [QSA,L]\n";
		}

		$rules .= "</IfModule>\n";

		return $rules;
	}

	function update_htaccess( $force_update = false, $force_clear = false ) {
		if ( $force_clear ) {
			$this->do_update_htaccess( true );
		} else if ( $force_update ) {
			return $this->do_update_htaccess();
		} else {
			if ( '' == get_option( 'permalink_structure' ) && 'yes' !== get_option( 'mainwp_keyword_links_htaccess_set' ) ) {
				$this->do_update_htaccess();
			} // need to update
			else if ( '' != get_option( 'permalink_structure' ) && 'yes' === get_option( 'mainwp_keyword_links_htaccess_set' ) ) {
				$this->do_update_htaccess();
			} // need to update
		}

		return true;
	}

	public static function clear_htaccess() {
		include_once( ABSPATH . '/wp-admin/includes/misc.php' );
		$home_path     = ABSPATH;
		$htaccess_file = $home_path . '.htaccess';
		if ( function_exists( 'save_mod_rewrite_rules' ) ) {
			$rules = explode( "\n", '' );
			insert_with_markers( $htaccess_file, 'MainWP Keyword Links Extension', $rules );
		}
		MainWP_Helper::update_option( 'mainwp_keyword_links_htaccess_set', '', 'yes' );
	}

	public function do_update_htaccess( $force_clear = false ) {
		if ( $force_clear ) {
			self::clear_htaccess();

			return true;
		} else if ( '' === get_option( 'permalink_structure' ) ) {
			include_once( ABSPATH . '/wp-admin/includes/misc.php' );
			$redirection_folder = $this->get_option( 'redirection_folder', '' );
			if ( empty( $redirection_folder ) ) {
				$rules = $this->get_cloak_rules();
				$rules = $this->mod_rewrite_rules( $rules );
				//self::clear_htaccess();
			} else {
				//Create rewrite ruler
				$rules = $this->mod_rewrite_rules( array( $redirection_folder . '/' => 'index.php' ) );
			}
			$home_path     = ABSPATH;
			$htaccess_file = $home_path . '.htaccess';
			if ( function_exists( 'save_mod_rewrite_rules' ) ) {
				$rules = explode( "\n", $rules );
				insert_with_markers( $htaccess_file, 'MainWP Keyword Links Extension', $rules );
			}
			MainWP_Helper::update_option( 'mainwp_keyword_links_htaccess_set', 'yes', 'yes' );

			return true;
		} else {
			self::clear_htaccess();

			return true;
		}
	}


	function get_cloak_rules() {
		$cloak_rules = array();
		foreach ( $this->keyword_links as $link ) {
			if ( ! empty( $link->cloak_path ) ) {
				$cloak_rules[ $link->cloak_path ] = 'index.php';
			}
		}

		return $cloak_rules;
	}

	public function saveClickCallback() {
		if ( ! wp_verify_nonce( $_POST['nonce'], 'keywordLinksSaveClick' ) ) {
			return false;
		}
		$link_id = intval( $_POST['link_id'] );
		if ( $link_id ) {
			$this->add_statistic( $link_id, $_POST['ip'], $_POST['referer'] );
		}
		exit;
	}

	public function sendClick() {
		$url       = $this->server . 'admin-ajax.php';
		$clickData = get_option( 'mainwp_kwl_click_statistic_data' );
		$key       = get_option( 'mainwp_child_pubkey' );
		if ( ! is_array( $clickData ) ) {
			return false;
		}
		$timestamp = time();
		$signature = $this->createSignature( $key, $timestamp, $clickData );
		$request   = wp_remote_post( $url, array(
			'headers' => array(
				'Referer' => site_url(),
			),
			'body'    => array(
				'timestamp' => $timestamp,
				'signature' => $signature,
				'data'      => base64_encode( serialize( $clickData ) ),
				'action'    => 'keywordLinksSendClick',
			),
		) );
		if ( is_array( $request ) && intval( $request['body'] ) > 0 ) {
			delete_option( 'mainwp_kwl_click_statistic_data' );
		}
	}

	public function createSignature( $key, $timestamp, $data ) {
		$datamd5   = md5( $timestamp . base64_encode( serialize( $data ) ) );
		$signature = md5( $key . $datamd5 );

		return $signature;
	}

	public function checkSignature( $signature, $timestamp, $data ) {
		$key = get_option( 'mainwp_child_pubkey' );
		if ( ! $key ) {
			return false;
		}
		$createSign = $this->createSignature( $key, $timestamp, $data );

		return ( $signature === $createSign );
	}

	public function get_option( $key, $default = '' ) {
		if ( isset( $this->config[ $key ] ) ) {
			return $this->config[ $key ];
		}

		return $default;
	}

	public function set_option( $key, $value ) {
		$this->config[ $key ] = $value;

		return MainWP_Helper::update_option( 'mainwp_kwl_options', $this->config );
	}

	public function get_link( $link_id, $default = '' ) {
		if ( isset( $this->keyword_links[ $link_id ] ) ) {
			return $this->keyword_links[ $link_id ];
		}

		return $default;
	}

	public function set_link( $link_id, $link ) {
		if ( empty( $link ) ) {
			unset( $this->keyword_links[ $link_id ] );
		} else {
			$this->keyword_links[ $link_id ] = $link;
		}

		return MainWP_Helper::update_option( 'mainwp_kwl_keyword_links', $this->keyword_links );
	}


	// This function is to generate links for keywords in post content
	public function filter_content( $content ) {
		global $post, $wpdb;
		if ( $this->get_option( 'mainwp_kwl_do_not_link_site_blocked', false ) ) {
			return $content;
		}

		// get allow post typies, if it isn't belong that => avoid
		$allow_post_type = (array) $this->get_option( 'enable_post_type' );
		if ( ! in_array( $post->post_type, $allow_post_type ) ) {
			return $content;
		}

		if ( $post ) {
			// Check if this post was disabled with this function, come back
			$disable = get_post_meta( $post->ID, '_mainwp_kl_disable', true );
			if ( 1 === (int) $disable ) {
				return $content;
			}

			$paths_blocked = $this->get_option( 'mainwp_kwl_do_not_link_paths_blocked', array() );
			if ( is_array( $paths_blocked ) ) {
				$permalink = get_permalink( $post->ID );
				$url_paths = str_replace( $this->siteurl, '', $permalink );
				$url_paths = trim( $url_paths, '/' );

				// check full path blocked
				if ( in_array( $url_paths, $paths_blocked ) ) {
					return $content;
				}

				$url_paths = explode( '/', $url_paths );
				foreach ( $url_paths as $path ) {
					// check partial paths blocked
					if ( ! empty( $path ) && in_array( $path, $paths_blocked ) ) {
						return $content;
					}
				}
			}
		}

		// save specific link
		if ( $post ) {
			$specific_link = maybe_unserialize( get_post_meta( $post->ID, '_mainwp_kwl_specific_link', true ) );
			if ( is_array( $specific_link ) && count( $specific_link ) > 0 ) {
				$specific_link          = current( $specific_link );
				$specific_link->post_id = $post->ID;
				//update_post_meta($post->ID, '_mainwp_kwl_specific_link_save', array($specific_link->id => $specific_link));
				update_post_meta( $post->ID, '_mainwp_kwl_specific_link_id', $specific_link->id );
				if ( $this->set_link( $specific_link->id, $specific_link ) ) {
					update_post_meta( $post->ID, '_mainwp_kwl_specific_link', '<saved>' );
				}
			}
		}

		if ( $post && $post->ID ) {
			$links = $this->get_available_links( $post->ID );
		} else {
			$links = $this->get_available_links();
		}

		// print_r($this->keyword_links);
		//        if ($post->ID == 751) {
		//            //print_r($links);
		//            $custom = get_post_custom($post->ID);
		//            print_r($custom);
		//        }

		if ( empty( $links ) ) {
			return $content;
		}

		$replace_max         = intval( $this->get_option( 'replace_max', - 1 ) );
		$replace_max_keyword = intval( $this->get_option( 'replace_max_keyword', - 1 ) );
		// start create links for keywords (terms) in post content
		$this->link_count_temp = $replace_max;
		$not_allow_keywords    = get_post_meta( $post->ID, 'mainwp_kl_not_allowed_keywords_on_this_post', true );
		$not_allow_keywords    = maybe_unserialize( $not_allow_keywords );
		foreach ( $links as $link ) {
			if ( ! $link ) {
				continue;
			}

			global $current_user;

			$link->exact_match    = isset( $link->exact_match ) ? $link->exact_match : 1; // to remove warning
			$link->case_sensitive = isset( $link->case_sensitive ) ? $link->case_sensitive : 1; // to remove warning

			$this->link_temp            = $link;
			$this->link_count_each_temp = $replace_max_keyword;
			$this->link_exact_match     = $link->exact_match;
			$this->link_case_sensitive  = $link->case_sensitive;
			$keywords                   = $this->explode_multi( $link->keyword );
			//usort( $keywords, create_function( '$a,$b', 'return strlen($a)<strlen($b);' ) );
            usort( $keywords, array($this, 'usort_callback_func') );
			$replace_cs = $link->case_sensitive ? 's' : 'is';
			//print_r($keywords);
			foreach ( $keywords as $keyword ) {
				$keyword = trim( $keyword );
				if ( empty( $keyword ) ) {
					continue;
				}
				if ( in_array( array(
					'keyword' => $keyword,
					'link'    => $link->destination_url,
				), (array) $not_allow_keywords ) ) {
					continue;
				}
				$keyword = preg_replace( '/([$^\/?+.*\]\[)(}{])/is', '\\\\\1', $keyword );

				if ( ( $link->case_sensitive && strpos( $content, $keyword ) !== false ) || ( ! $link->case_sensitive && stripos( $content, $keyword ) !== false ) ) {
					//Replace keyword in H tag
					if ( $this->get_option( 'replace_keyword_in_h_tag' ) ) {
						//$content = preg_replace_callback('/(<a[^>]*>.*?'.$keyword.'.*?<\/a>|<[^>]*'.$keyword.'[^>]*>|\{[^}]*'.$keyword.'[^}]*\}|\w*('.$keyword.')\w*)/is', array(&$this, 'keyword_mark'), $content);
						$content = preg_replace_callback( '/(<a[^>]*>[^<]*?' . $keyword . '[^<]*?<\/a>|<[^>]*' . $keyword . '[^>]*>|\{[^\}]*' . $keyword . '[^\}]*\}|\w*(' . $keyword . ')\w*)/' . $replace_cs, array(
							&$this,
							'keyword_mark',
						), $content );
					} else {
						//$content = preg_replace_callback('/(<h[123456][^>]*>.*?'.$keyword.'.*?<\/h[123456]>|<a[^>]*>.*?'.$keyword.'.*?<\/a>|<[^>]*'.$keyword.'[^>]*>|\{[^}]*'.$keyword.'[^}]*\}|\w*('.$keyword.')\w*)/is', array(&$this, 'keyword_mark'), $content);
						$content = preg_replace_callback( '/(<h[123456][^>]*>[^<]*?' . $keyword . '[^<]*?<\/h[123456]>|<a[^>]*>[^<]*?' . $keyword . '[^<]*?<\/a>|<[^>]*' . $keyword . '[^>]*>|\{[^\}]*' . $keyword . '[^\}]*\}|\w*(' . $keyword . ')\w*)/' . $replace_cs, array(
							&$this,
							'keyword_mark',
						), $content );
					}
				}
			}
		}
		//$content = preg_replace_callback('/\{MAINWP_LINK +HREF="(.*?)" +TARGET="(.*?)" +REL="(.*?)" +LINK-ID="(.*?)" +CLASS="(.*?)" +TEXT="(.*?)" *\}/is', array(&$this, 'keyword_replace'), $content);
		$content = preg_replace_callback( '/\{MAINWP_LINK +HREF="(.*?)" +TARGET="(.*?)" +REL="(.*?)" +LINK-ID="(.*?)" +CLASS="(.*?)" +TEXT="(.*?)" +FULL_TEXT="(.*?)" *\}/is', array(
			&$this,
			'keyword_replace',
		), $content );

		return $content;
	}

    private function usort_callback_func($a, $b) {
		return strlen($a)<strlen($b);
	}

	public function keyword_mark( $matches ) {

		if ( preg_match( '/^[<{].*?[>}]$/is', $matches[1] ) ) {
			return $matches[1];
		}

		if ( $this->link_count_temp === 0 || $this->link_count_each_temp === 0 ) {
			return $matches[1];
		}

		if ( $this->link_exact_match ) {
			if ( $matches[1] !== $matches[2] ) {
				return $matches[1];
			}
		}

		if ( -1 !== $this->link_count_temp ) {
			$this->link_count_temp --;
		}

		if ( -1 !== $this->link_count_temp ) {
			$this->link_count_each_temp --;
		}

		//        if (isset($this->link_temp->type) && $this->link_temp->type == 'post_type') {
		////            $post = get_post($this->link_temp->id);
		////            if ($post) {
		////                $disable_linking = $this->get_option('disable_linking_automatically', array());
		////                if (in_array($post->post_name, $disable_linking[$post->post_type]))
		////                    return $matches[1]; // do not link to this post
		////            }
		//            $link_target = get_post_meta($this->link_temp->id, '_mainwp_kl_link_newtab', true);
		//            $this->link_temp->link_target = ( $link_target != -1 && $link_target == 1 ? '_blank' : '' );
		//            $link_rel = get_post_meta($this->link_temp->id, '_mainwp_kl_link_nofollow', true);
		//            $this->link_temp->link_rel = ( $link_rel != -1 && $link_rel == 1 ? 'nofollow' : '' );
		//            $this->link_temp->link_class = get_post_meta($this->link_temp->id, '_mainwp_kl_link_class', true);
		//        }
		if ( '-1' !== $this->link_temp->link_target ) {
			$target = $this->link_temp->link_target;
		} else {
			$target = $this->get_option( 'default_link_newtab' ) ? '_blank' : '';
		}

		if ( '-1' !== $this->link_temp->link_rel ) {
			$rel = $this->link_temp->link_rel;
		} else {
			$rel = $this->get_option( 'default_link_nofollow' ) ? 'nofollow' : '';
		}
		if ( '' !== $this->link_temp->link_class ) {
			$class = $this->link_temp->link_class;
		} else {
			$class = $this->get_option( 'default_link_class' );
		}
		$redirection_folder = $this->get_option( 'redirection_folder', '' );

		if ( ! empty( $redirection_folder ) ) {
			$redirection_folder = '/' . $redirection_folder;
		}

		$regular_link = false;
		if ( empty( $this->link_temp->cloak_path ) ) {
			$regular_link = true;
			$class .= ' kwl-regular-link';
		}

		//return '{MAINWP_LINK HREF="' . ( $this->link_temp->cloak_path ? $this->siteurl . $redirection_folder . '/' . $this->link_temp->cloak_path : $this->link_temp->destination_url) . '" TARGET="' . $target . '" REL="' . $rel . '" LINK-ID="' . (isset($this->link_temp->id) ? $this->link_temp->id : 0) . '" CLASS="' . $class . '" TEXT="' . $matches[1] . '"}';
		return '{MAINWP_LINK HREF="' . ( $this->link_temp->cloak_path ? $this->siteurl . $redirection_folder . '/' . $this->link_temp->cloak_path : $this->link_temp->destination_url ) . '" TARGET="' . $target . '" REL="' . $rel . '" LINK-ID="' . ( isset( $this->link_temp->id ) ? $this->link_temp->id : 0 ) . '" CLASS="' . $class . '" TEXT="' . $matches[2] . '" FULL_TEXT="' . $matches[1] . '"}';
	}

	public function keyword_replace( $matches ) {
		$a = '<a href="' . $matches[1] . '"';
		$a .= ( $matches[2] ) ? ' target="' . $matches[2] . '"' : '';
		$a .= ( $matches[3] ) ? ' rel="' . $matches[3] . '"' : '';
		$a .= ( $matches[4] ) ? ' link-id="' . $matches[4] . '"' : '';
		$a .= ( $matches[5] ) ? ' class="' . $matches[5] . '"' : '';
		$a .= '>' . $matches[6] . '</a>';

		if ( ! $this->link_exact_match ) {
			if ( $matches[7] !== $matches[6] ) {
				$a = str_replace( $matches[6], $a, $matches[7] );
			}
		}

		return $a;
	}

	public function get_available_links( $post_id = null ) {
		global $post, $wpdb;
		if ( null !== $post_id ) {
			$post = get_post( $post_id );
		}
		$links = array();
		//        $disable_add_links = $this->get_option('disable_add_links_automatically');
		//        // if disabled add links automatically in this post, avoid
		//        if (in_array($post->post_name, (array) $disable_add_links[$post->post_type])) {
		//            return $links;
		//        }

		// Check if this post was disabled with this function, come back
		//        $disable = get_post_meta($post->ID, '_mainwp_kl_disable', true);
		//        if ($disable == 1)
		//            return $links;
		// count replace max and max keyword allowed.
		$replace_max         = intval( $this->get_option( 'replace_max' ) );
		$replace_max_keyword = intval( $this->get_option( 'replace_max_keyword' ) );
		if ( 0 === $replace_max || 0 === $replace_max_keyword ) {
			return $links;
		}
		// Post types enabled to create links
		$post_types = (array) $this->get_option( 'enable_post_type_link' );
		foreach ( $post_types as $post_type ) {
			if ( $post_type === $post->post_type ) {
				$categories = get_the_terms( $post->ID, 'category' );
				$cats       = array();
				if ( is_array( $categories ) ) {
					foreach ( $categories as $category ) {
						$cats[] = $category->term_id;
					}
				}
				$links_post_type = (array) $this->get_post_keywords( $post_type, $cats );
			} else {
				$links_post_type = (array) $this->get_post_keywords( $post_type );
			}
			//print_r($links_post_type);
			if ( count( $links_post_type ) > 0 ) {
				$links = array_merge( $links, $links_post_type );
			}
		}

		if ( $post && $post->ID > 0 ) {
			$spec_link_id = get_post_meta( $post->ID, '_mainwp_kwl_specific_link_id', true );
		}

		$post_timestamp = strtotime( $post->post_date );
		foreach ( $this->keyword_links as $link ) {
			if ( 1 === (int) $link->type || 3 === (int) $link->type ) {   // type: 1,3 is normal link and specific link
				if ( isset( $link->check_post_date ) && $link->check_post_date ) {
					if ( $post_timestamp < $link->check_post_date ) {
						$links[] = $link;
					}
				} else {
					$links[] = $link;
				}
			} else if ( $spec_link_id && $spec_link_id === $link->id ) { // type 2 is specific link
				if ( $link->check_post_date ) {
					if ( $post_timestamp < $link->check_post_date ) {
						$links[] = $link;
					}
				} else {
					$links[] = $link;
				}
			}
		}

		return $links;
	}


	public function get_post_keywords( $post_type, $cats = null ) {
		global $wpdb, $post;
		$join  = '';
		$where = '';
		if ( is_array( $cats ) && count( $cats ) > 0 ) {
			$join  = "JOIN $wpdb->term_relationships tr ON tr.object_id = p.ID";
			$where = " AND (tr.term_taxonomy_id = '" . implode( "' OR tr.term_taxonomy_id = '", $cats ) . "')";
		}
		//$results = $wpdb->get_results(sprintf("SELECT * FROM $wpdb->posts as p LEFT JOIN $wpdb->postmeta as pm ON p.ID=pm.post_id $join WHERE p.post_status='publish' AND p.post_type='%s' AND pm.meta_key='_mainwp_kl_post_keyword' $where", $post_type));
		$results = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM $wpdb->posts as p $join WHERE p.post_status='publish' AND p.post_type= %s $where", $post_type ) );
		$links   = array();
		if ( ! is_array( $results ) ) {
			return array();
		}
		$paths_blocked = $this->get_option( 'mainwp_kwl_do_not_link_paths_blocked', array() );
		foreach ( $results as $result ) {
			if ( $result->ID === $post->ID ) {
				continue;
			} // do not link to myself
			if ( in_array( $result->post_name, (array) $paths_blocked ) ) {
				continue;
			}
			$link = new stdClass;
			// This is on-fly link so have not ID
			//$link->id = $result->ID;
			$link->name = $result->post_title;
			//if ($result->post_type == 'page')
			//    $link->destination_url = get_permalink($result->ID);
			//else
			//    $link->destination_url = $result->guid;
			$link->destination_url = get_permalink( $result->ID );
			$link->cloak_path      = '';
			$link->keyword         = ( 1 === (int) $this->get_option( 'post_match_title' ) ? $result->post_title : '' );
			$link->link_target     = '';
			$link->link_rel        = '';
			$link->link_class      = '';
			$link->type            = 1;
			$links[]               = $link;
		}

		return $links;
	}

	public function explode_multi( $str ) {
		$delimiters = array( ',', ';', '|' );
		$str        = str_replace( $delimiters, ',', $str );
		$kws        = explode( ',', $str );
		$return     = array();
		foreach ( $kws as $kw ) {
			$return[] = trim( $kw );
		}

		return $return;
	}

	public function redirect_cloak() {
		global $wpdb;

		if ( $this->get_option( 'mainwp_kwl_do_not_link_site_blocked', false ) ) {
			return;
		}

		$request = $_SERVER['REQUEST_URI'];
		// Check if the request is correct
		if ( ! preg_match( '|^[a-z0-9-~+_.?#=!&;,/:%@$\|*\'()\\x80-\\xff]+$|i', $request ) ) {
			return;
		}
		// Check to see if Wordpress is installed in sub folder
		$siteurl        = parse_url( $this->siteurl );
		$sitepath       = ( isset( $siteurl['path'] ) ) ? $siteurl['path'] : '';
		$filter_request = preg_replace( '|^' . $sitepath . '/?|i', '', $request );
		$filter_request = preg_replace( '|/?$|i', '', $filter_request );

		$redirection_folder = $this->get_option( 'redirection_folder', '' );

		if ( ! empty( $redirection_folder ) ) {
			//if the request doesn't' containt the redirection folder we will return immediately
			if ( strpos( $filter_request, $redirection_folder . '/' ) === false ) {
				return;
			}
		}

		$filter_request = str_replace( $redirection_folder . '/', '', $filter_request );

		if ( empty( $filter_request ) ) {
			return;
		}

		if ( '/' === substr( $filter_request, - 1 ) ) {
			$filter_request = substr( $filter_request, 0, - 1 );
		}

		$link_id = 0;
		foreach ( $this->keyword_links as $link ) {
			if ( $link->cloak_path === $filter_request ) {
				$destination_url = $link->destination_url;
				$link_id         = $link->id;
				break;
			}
		}

		if ( ! empty( $destination_url ) ) {
			if ( get_option( 'mainwp_kwl_enable_statistic' ) ) {
				$this->add_statistic( $link_id, $_SERVER['REMOTE_ADDR'], $_SERVER['HTTP_REFERER'] );
			}
			wp_redirect( $destination_url );
			die();
		}
	}

	public function add_statistic( $link_id, $addr, $referer, $type = 'click' ) {
		if ( $link_id > 0 ) {
			$storeData = get_option( 'mainwp_kwl_click_statistic_data' );
			if ( ! is_array( $storeData ) ) {
				$storeData = array();
			}
			$storeData[] = array(
				'timestamp' => time(),
				'link_id'   => $link_id,
				'ip'        => $addr,
				'referer'   => $referer,
			);
			MainWP_Helper::update_option( 'mainwp_kwl_click_statistic_data', $storeData );
			// Customize when we need to send the data
			$this->sendClick();
		}
	}

	//    public function get_statistic() {
	//        global $wpdb;
	//        $link_id = $_POST['link_id'];
	//        if ($link_id) {
	//            $stat_data = get_option('mainwp_kwl_statistic_data_' . $link_id, array());
	//            if ($stat_data) {
	//                $return['stat_data'] = $stat_data;
	//                //$wpdb->query("UPDATE {$wpdb->prefix}options SET option_name = 'mainwp_kwl_statistic_data_done_" . $link_id . "' WHERE option_name = 'mainwp_kwl_statistic_data_" . $link_id . "'");
	//                update_option('mainwp_kwl_statistic_data_' . $link_id, '');
	//            } else
	//                $return['stat_data'] = 'EMPTY';
	//            $return['status'] = 'SUCCESS';
	//        }
	//        return $return;
	//    }

	public function action() {
		$result = array();
		switch ( $_POST['action'] ) {
			case 'enable_stats':
				$result = $this->enable_stats();
				break;
			case 'refresh_data':
				$result = $this->refresh_data();
				break;
			case 'import_link':
			case 'add_link':
				$result = $this->edit_link();
				break;
			case 'delete_link':
				$result = $this->delete_link();
				break;
			case 'clear_link':
				$result = $this->clear_link();
				break;
			case 'update_config':
				$result = $this->update_config();
				break;
			case 'donotlink_site_blocks':
				$result = $this->donotlink_site_blocks();
				break;
			case 'donotlink_path_blocks':
				$result = $this->donotlink_path_blocks();
				break;
			case 'donotlink_clear':
				$result = $this->donotlink_clear();
				break;
			case 'remove_keywords':
				$result = $this->remove_keywords();
				break;
		}
		MainWP_Helper::write( $result );
	}

	function remove_keywords() {
		$result          = array();
		$remove_settings = $_POST['removeSettings'];
		$remove_keywords = $_POST['keywords'];
		$remove_keywords = maybe_unserialize( base64_decode( $remove_keywords ) );
		$remove_kws      = $this->explode_multi( $remove_keywords );

		if ( $remove_settings ) {
			$this->clear_settings();
			$return['status'] = 'SUCCESS';
		} else if ( is_array( $remove_kws ) && is_array( $this->keyword_links ) ) {
			$new_keyword_links = array();
			foreach ( $this->keyword_links as $link_id => $link ) {
				$lnk_kws  = $link->keyword;
				$lnk_kws  = $this->explode_multi( $lnk_kws );
				$diff_kws = array();
				if ( is_array( $lnk_kws ) ) {
					$diff_kws = array_diff( $lnk_kws, $remove_kws );
				}
				if ( count( $diff_kws ) > 0 ) {
					$link->keyword                 = implode( ',', $diff_kws );
					$new_keyword_links[ $link_id ] = $link;
				}
			}
			$this->keyword_links = $new_keyword_links;
			MainWP_Helper::update_option( 'mainwp_kwl_keyword_links', $this->keyword_links );
			$return['status'] = 'SUCCESS';
		} else {
			$return['status'] = 'NOCHANGE';
		}

		return $return;
	}

	public function clear_settings() {
		$dell_all = array(
			'mainwp_keyword_links_htaccess_set',
			'mainwp_kwl_options',
			'mainwpKeywordLinks',
			'mainwp_kwl_keyword_links',
			'mainwp_kwl_click_statistic_data',
			'mainwp_kwl_enable_statistic',
			'mainwp_kwl_keyword_links',
		);

		foreach ( $dell_all as $opt ) {
			delete_option( $opt );
		}
	}

	public function enable_stats() {
		global $mainWPChild;
		$result       = array();
		$enable_stats = intval( $_POST['enablestats'] );
		if ( get_option( 'mainwp_kwl_enable_statistic' ) !== $enable_stats ) {
			if ( MainWP_Helper::update_option( 'mainwp_kwl_enable_statistic', $enable_stats ) ) {
				$return['status'] = 'SUCCESS';
			}
			$mainWPChild->update_htaccess( true );
		}

		return $return;
	}

	public function refresh_data() {
		$return = array();
		if ( isset( $_POST['clear_all'] ) ) {
			$cleared1 = MainWP_Helper::update_option( 'mainwp_kwl_keyword_links', '' );
			$cleared2 = MainWP_Helper::update_option( 'mainwp_kwl_options', '' );
			if ( $cleared1 || $cleared2 ) {
				$return['status'] = 'SUCCESS';
			}
		}

		return $return;
	}

	function update_htaccess_for_change_cloak_links( $link ) {
		if ( empty( $link ) ) {
			return;
		}
		$redirection_folder = $this->get_option( 'redirection_folder', '' );
		if ( empty( $redirection_folder ) ) {
			$this->update_htaccess( true );
		}
	}

	public function delete_link() {
		$result = array();
		if ( ! empty( $_POST['link_id'] ) ) {
			$current          = $this->get_link( $_POST['link_id'], false );
			$delete_permanent = intval( $_POST['delete_permanent'] );
			if ( $current ) {
				if ( $delete_permanent ) {
					if ( 2 === (int) $current->type || 3 === (int) $current->type ) {
						$deleted = delete_post_meta( $current->post_id, '_mainwp_kwl_specific_link_id' );
					}
					if ( $this->set_link( $current->id, '' ) ) {
						$return['status'] = 'SUCCESS';
					}
				} else {
					$current->check_post_date = time();
					$this->set_link( $current->id, $current );
				}
				$this->update_htaccess_for_change_cloak_links( $current );
			} else {
				$return['status'] = 'SUCCESS';
			}
		}

		return $return;
	}

	public function clear_link() {
		$return  = array();
		$cleared = false;
		if ( ! empty( $_POST['link_id'] ) ) {
			$clear_link = $this->get_link( $_POST['link_id'], false );
			if ( $clear_link ) {
				if ( 3 === (int) $clear_link->type ) {
					$clear_link->type = 2;
					$cleared          = $this->set_link( $clear_link->id, $clear_link );
				} else if ( 1 === (int) $clear_link->type ) {
					$cleared = $this->set_link( $clear_link->id, '' ); // delete link
				}
				$this->update_htaccess_for_change_cloak_links( $clear_link );
			} else {
				$cleared = true;
			}
		}

		if ( $cleared ) {
			$return['status'] = 'SUCCESS';
		}

		return $return;
	}


	public function edit_link() {
		$return  = array();
		$link_id = $_POST['id'];
		if ( ! empty( $link_id ) ) {

			$valid_kws = '';
			$chec_kws  = $this->check_existed_keywords( $link_id, sanitize_text_field( $_POST['keyword'] ) );
			if ( is_array( $chec_kws['existed'] ) && count( $chec_kws['existed'] ) > 0 ) {
				$return['existed_keywords'] = $chec_kws['existed'];
			}
			if ( is_array( $chec_kws['valid'] ) && count( $chec_kws['valid'] ) > 0 ) {
				$valid_kws = implode( ',', $chec_kws['valid'] );
			}

			$old                   = $this->get_link( $link_id );
			$link                  = new stdClass;
			$link->id              = intval( $link_id );
			$link->name            = sanitize_text_field( $_POST['name'] );
			$link->destination_url = esc_url( $_POST['destination_url'] );
			$link->cloak_path      = sanitize_text_field( $_POST['cloak_path'] );
			$link->keyword         = $valid_kws;
			$link->link_target     = $_POST['link_target'];  // number or text
			$link->link_rel        = $_POST['link_rel']; // number or text
			$link->link_class      = sanitize_text_field( $_POST['link_class'] );
			$link->type            = intval( $_POST['type'] );
			$link->exact_match     = intval( $_POST['exact_match'] );
			$link->case_sensitive  = intval( $_POST['case_sensitive'] );

			if ( 2 === (int) $link->type || 3 === (int) $link->type ) {
				if ( intval( $_POST['post_id'] ) ) {
					$link->post_id = intval( $_POST['post_id'] );
				} else if ( $old && $old->post_id ) {
					$link->post_id = $old->post_id;
				}
				if ( $link->post_id ) {
					update_post_meta( $link->post_id, '_mainwp_kwl_specific_link_id', $link_id );
				}
			}

			if ( $this->set_link( $link->id, $link ) ) {
				$return['status'] = 'SUCCESS';
				$this->update_htaccess_for_change_cloak_links( $link );
			}
		}
		MainWP_Helper::update_option( 'mainwpKeywordLinks', 1, 'yes' ); // enable extension functions
		return $return;
	}

	function check_existed_keywords( $link_id, $keywords ) {
		$new_kws     = $this->explode_multi( $keywords );
		$existed_kws = array();
		if ( is_array( $new_kws ) && is_array( $this->keyword_links ) ) {
			foreach ( $this->keyword_links as $lnk_id => $kw ) {
				if ( $link_id !== $lnk_id ) {
					$link_kws = $this->explode_multi( $kw->keyword );
					if ( is_array( $link_kws ) ) {
						foreach ( $new_kws as $new_kw ) {
							if ( in_array( $new_kw, $link_kws ) && ! in_array( $new_kw, $existed_kws ) ) {
								$existed_kws[] = $new_kw;
							}
						}
					}
				}
			}
		}

		return array(
			'existed' => $existed_kws,
			'valid'   => array_diff( $new_kws, $existed_kws ),
		);
	}

	public function update_config() {
		$return       = array();
		$this->config = array(
			'replace_max'              => intval( $_POST['replace_max'] ),
			'replace_max_keyword'      => intval( $_POST['replace_max_keyword'] ),
			'default_link_nofollow'    => intval( $_POST['default_link_nofollow'] ),
			'default_link_newtab'      => intval( $_POST['default_link_newtab'] ),
			'replace_keyword_in_h_tag' => intval( $_POST['replace_keyword_in_h_tag'] ),
			'default_link_class'       => sanitize_text_field( $_POST['default_link_class'] ),
			'post_match_title'         => intval( $_POST['post_match_title'] ),
			'redirection_folder'       => sanitize_text_field( $_POST['redirection_folder'] ),
			'enable_post_type'         => $_POST['enable_post_type'],
			'enable_post_type_link'    => $_POST['enable_post_type_link'],
		);
		MainWP_Helper::update_option( 'mainwpKeywordLinks', 1, 'yes' ); // enable extension functions
		MainWP_Helper::update_option( 'mainwp_kwl_options', $this->config );
		// force update
		$this->update_htaccess( true );
		$return['status'] = 'SUCCESS';
		return $return;
	}

	public function donotlink_site_blocks() {
		$return = array();
		if ( $this->set_option( 'mainwp_kwl_do_not_link_site_blocked', true ) ) {
			$return['status'] = 'SUCCESS';
		}

		return $return;
	}

	public function donotlink_path_blocks() {
		$return = array();
		if ( $path = $_POST['path'] ) {
			$paths   = $this->get_option( 'mainwp_kwl_do_not_link_paths_blocked', array() );
			$paths[] = $path;
			if ( $this->set_option( 'mainwp_kwl_do_not_link_paths_blocked', $paths ) ) {
				$return['status'] = 'SUCCESS';
			}
		}

		return $return;
	}

	public function donotlink_clear() {
		$return = array();
		if ( $this->set_option( 'mainwp_kwl_do_not_link_site_blocked', '' ) ) {
			$return['status'] = 'SUCCESS';
		}
		if ( $this->set_option( 'mainwp_kwl_do_not_link_paths_blocked', '' ) ) {
			$return['status'] = 'SUCCESS';
		}

		return $return;
	}
}

