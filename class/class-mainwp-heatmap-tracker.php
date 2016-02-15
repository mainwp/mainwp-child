<?php

/**
 * Class for tracking click heatmap
 *
 * Uses $wpdb object
 *
 * @version 1.0
 * @author Jeffri Hong
 */
class MainWP_Heatmap_Tracker {
	protected static $instance;
	protected $server;
	protected $dbVersion = 1000;

	/**
	 * Class constructor
	 *
	 * @param boolean $checkDb Do checking the database if set to true
	 */
	public function __construct( $checkDb = false ) {
		self::$instance = $this;
		$this->server   = get_option( 'mainwp_child_server' );
		add_action( 'template_redirect', array( $this, 'trackerJs' ) );
		add_action( 'wp_ajax_heatmapSaveClick', array( $this, 'saveClickCallback' ) );
		add_action( 'wp_ajax_nopriv_heatmapSaveClick', array( $this, 'saveClickCallback' ) );
	}

	/**
	 * Get Instance
	 */
	public static function getInstance() {
		if ( self::$instance instanceof HeatmapTracker ) {
			return self::$instance;
		}
		self::$instance = new HeatmapTracker( true );

		return self::$instance;
	}

	/**
	 * Parse which page we are on using URL
	 */
	public function getPageObject( $pageUrl ) {
		global $wp_rewrite;
		// If post type, we are using url_to_postid function
		$postId = url_to_postid( $pageUrl );
		if ( $postId ) {
			$postType = get_post_type_object( get_post( $postId )->post_type );

			return array(
				'value' => $postId,
				'title' => get_the_title( $postId ),
				'type'  => get_post( $postId )->post_type,
				'label' => ( is_array( $postType->labels ) ? $postType->labels['name'] : $postType->labels->name ),
			);
		}
		$path = str_replace( get_site_url(), '', $pageUrl );
		$path = trim( $path, '/' );
		// If path is empty, then it is front page
		if ( empty( $path ) ) {
			return array(
				'value' => get_option( 'page_on_front' ) ? get_option( 'page_on_front' ) : '',
				'title' => '',
				'type'  => 'front_page',
				'label' => __( 'Home Page' ),
			);
		}
		// Otherwise, we will try to match through rewrite or by query
		$rewrite = $wp_rewrite->wp_rewrite_rules();
		if ( is_array( $rewrite ) && count( $rewrite ) > 0 ) {
			foreach ( $rewrite as $match => $query ) {
				if ( preg_match( "#^$match#", $path, $matches ) || preg_match( "#^$match#", urldecode( $path ), $matches ) ) {
					$query = preg_replace( '!^.*\?!', '', $query );
					$query = addslashes( WP_MatchesMapRegex::apply( $query, $matches ) );
					parse_str( $query, $query_vars );
					break;
				}
			}
		} else {
			$query = preg_replace( '!^.*\?!', '', $path );
			parse_str( $query, $query_vars );
		}
		// Workaround for fail pagename rewrite match
		if ( isset( $query_vars['pagename'] ) && strpos( $query_vars['pagename'], '?' ) !== false ) {
			$query = preg_replace( '!^.*\?!', '', $query_vars['pagename'] );
			parse_str( $query, $query_vars );
		}
		$querypost = new WP_Query( $query_vars );
		if ( $querypost->is_date() ) {
			if ( $querypost->query_vars['m'] ) {
				$date = $querypost->query_vars['m'];
			} else if ( $querypost->is_day() ) {
				$date = $querypost->query_vars['year'] . zeroise( $querypost->query_vars['monthnum'], 2 ) . zeroise( $querypost->query_vars['day'], 2 );
			} else if ( $querypost->is_month() ) {
				$date = $querypost->query_vars['year'] . zeroise( $querypost->query_vars['monthnum'], 2 );
			} else if ( $querypost->is_year() ) {
				$date = $querypost->query_vars['year'];
			}

			return array(
				'value' => $date,
				'title' => '',
				'type'  => 'archive',
				'label' => __( 'Archive' ),
			);
		} else if ( $querypost->is_category() || $querypost->is_tag() || $querypost->is_tax() ) {
			$tax_query = $querypost->tax_query->queries;
			$taxonomy  = get_taxonomy( $tax_query[0]['taxonomy'] );
			if ( 'term_id' === $tax_query[0]['field'] ) {
				$term_id = $tax_query[0]['terms'][0];
			} else if ( 'slug' === $tax_query[0]['field'] ) {
				$term_id = get_term_by( 'slug', $tax_query[0]['terms'][0], $taxonomy->name )->term_id;
			}

			return array(
				'value' => $term_id,
				'title' => get_term( $term_id, $taxonomy->name )->name,
				'type'  => $taxonomy->name,
				'label' => ( is_array( $taxonomy->labels->name ) ? $taxonomy->labels['name'] : $taxonomy->labels->name ),
			);
		} else if ( $querypost->is_search() ) {
			return array(
				'value' => $querypost->query_vars['s'],
				'title' => '',
				'type'  => 'search',
				'label' => __( 'Search' ),
			);
		} else if ( $querypost->is_home() ) {
			return array(
				'value' => '',
				'title' => '',
				'type'  => 'home',
				'label' => __( 'Blog Home Page' ),
			);
		}
	}

	/**
	 * Save click callback for AJAX processing
	 */
	public function saveClickCallback() {
		if ( ! wp_verify_nonce( $_POST['nonce'], 'heatmapSaveClick' ) ) {
			return false;
		}
		$data      = isset( $_POST['data'] ) && is_array( $_POST['data'] ) ? $_POST['data'] : array();
		$storeData = get_option( 'mainwp_child_click_data' );
		if ( ! is_array( $storeData ) ) {
			$storeData = array();
		}
		foreach ( $data as $d ) {
			$coord    = isset( $d['coord'] ) && preg_match( '/^\d+,\d+$/', $d['coord'] ) ? explode( ',', $d['coord'] ) : null;
			$type     = isset( $d['type'] ) && preg_match( '/^(left|right|middle)$/', $d['type'] ) ? $d['type'] : 'left';
			$viewport = isset( $d['viewport'] ) && preg_match( '/^\d+,\d+$/', $d['viewport'] ) ? explode( ',', $d['viewport'] ) : null;
			$element  = isset( $d['element'] ) && preg_match( '/^[A-Za-z0-9#:().>_-]+$/is', $d['element'] ) ? $d['element'] : null;
			$attr     = array();
			if ( isset( $d['url'] ) && $d['url'] ) {
				$attr['url'] = esc_url_raw( $d['url'] );
			}
			if ( isset( $d['title'] ) && $d['title'] ) {
				$attr['title'] = sanitize_text_field( $d['title'] );
			}
			if ( isset( $d['alt'] ) && $d['alt'] ) {
				$attr['alt'] = sanitize_text_field( $d['alt'] );
			}
			if ( isset( $d['text'] ) && $d['text'] ) {
				$attr['text'] = sanitize_text_field( $d['text'] );
			}
			$useragent = $_SERVER['HTTP_USER_AGENT'];
			$object    = $this->getPageObject( $_SERVER['HTTP_REFERER'] );
			if ( ! is_null( $coord ) && ! is_null( $viewport ) && ! is_null( $element ) ) {
				$storeData[] = array(
					'url'       => $_SERVER['HTTP_REFERER'],
					'object'    => $object,
					'coord'     => $coord,
					'viewport'  => $viewport,
					'type'      => $type,
					'element'   => $element,
					'attr'      => $attr,
					'useragent' => $useragent,
					'date'      => current_time( 'mysql' ),
				);
			}
		}
		MainWP_Helper::update_option( 'mainwp_child_click_data', $storeData );
		// Customize when we need to send the data
		$this->sendClick();
		exit;
	}

	public function sendClick() {
		$url       = $this->server . 'admin-ajax.php';
		$clickData = get_option( 'mainwp_child_click_data' );
		$key       = get_option( 'mainwp_child_pubkey' );
		if ( ! is_array( $clickData ) ) {
			return false;
		}
		// send 1000 record per time to fix memory bug
		$sendNow = array();
		if ( count( $clickData ) > 1000 ) {
			for ( $i = 0; $i < 1000; $i ++ ) {
				$sendNow[] = $clickData[ $i ];
			}
		} else {
			$sendNow = $clickData;
		}

		$timestamp = time();
		$signature = $this->createSignature( $key, $timestamp, $sendNow );

		$params = array(
			'headers' => array(
				'Referer' => site_url(),
			),
			'body'    => array(
				'timestamp' => $timestamp,
				'signature' => $signature,
				'data'      => base64_encode( serialize( $sendNow ) ),
				'action'    => 'heatmapSendClick',
			),
			'timeout' => 30,
		);

		if ( strpos( $url, 'https://' ) === 0 ) {
			$params['sslverify'] = false;
		}

		$request = wp_remote_post( $url, $params );

		if ( is_array( $request ) && isset( $request['response']['code'] ) && 200 === (int) $request['response']['code'] ) {
			if ( count( $clickData ) > 1000 ) {
				$saveData = array();
				$clickDataLength = count( $clickData );
				for ( $i = 1000; $i < $clickDataLength; $i ++ ) {
					$saveData[] = $clickData[ $i ];
				}
				MainWP_Helper::update_option( 'mainwp_child_click_data', $saveData );
			} else {
				delete_option( 'mainwp_child_click_data' );
			}
		}
	}

	public function checkSignature( $signature, $timestamp, $data ) {
		$key = get_option( 'mainwp_child_pubkey' );
		if ( ! $key ) {
			return false;
		}
		$createSign = $this->createSignature( $key, $timestamp, $data );

		return ( $signature === $createSign );
	}

	public function createSignature( $key, $timestamp, $data ) {
		$datamd5   = md5( $timestamp . base64_encode( serialize( $data ) ) );
		$signature = md5( $key . $datamd5 );

		return $signature;
	}

	/**
	 * Whether the heatmap is requested to display or not
	 */
	public function displayHeatmap() {
		return ( isset( $_REQUEST['heatmap'] ) && '1' === $_REQUEST['heatmap'] );
		/*return ( ( isset($_REQUEST['heatmap']) && $_REQUEST['heatmap'] == '1' ) &&
                ( isset($_REQUEST['signature']) && isset($_REQUEST['timestamp']) && isset($_REQUEST['data']) &&
                $this->checkSignature($_REQUEST['signature'], $_REQUEST['timestamp'], $_REQUEST['data']) )
		);*/
	}

	/**
	 * Add tracker Javascript
	 */
	public function trackerJs() {
		if ( ! is_admin() ) {
			wp_enqueue_script( 'jquery' );
			wp_enqueue_script( 'heatmapTracker', plugins_url( '/js/tracker.js', dirname( __FILE__ ) ) );
			if ( $this->displayHeatmap() ) {
				wp_enqueue_script( 'heatmapJs', plugins_url( '/js/heatmap.js', dirname( __FILE__ ) ) );
				wp_enqueue_script( 'heatmapInit', plugins_url( '/js/heatmapinit.js', dirname( __FILE__ ) ) );
			}
			add_action( 'wp_head', array( $this, 'trackerJsInline' ), 1 );
		}
	}

	/**
	 * Add necessary inline tracker Javascript
	 */
	public function trackerJsInline() {
		echo '<script type="text/javascript">';
		echo 'var trackerAjaxUrl="' . admin_url( 'admin-ajax.php' ) . '"; var trackerNonce="' . wp_create_nonce( 'heatmapSaveClick' ) . '";';
		if ( $this->displayHeatmap() ) {
			wp_deregister_script( 'admin-bar' );
			wp_deregister_style( 'admin-bar' );
			remove_action( 'wp_footer', 'wp_admin_bar_render', 1000 );
			remove_action( 'wp_head', '_admin_bar_bump_cb' );
			$pageUrl        = sprintf( '%s%s', preg_replace( '#^((http|https)://([^/]+)).*#is', '$1', site_url() ), $_SERVER['REQUEST_URI'] );
			$pageUrl        = preg_replace( '#(&|\?)heatmap(|_start|_end|_browser|_browser_version|_platform|_width)=?([^&]*)#is', '', $pageUrl );
			$page           = $this->getPageObject( $pageUrl );
			$start          = isset( $_GET['heatmap_start'] ) && preg_match( '/^[2][01][0-9]{2}[\/\-][01][0-9][\/\-][0123][0-9]$/is', $_GET['heatmap_start'] ) ? $_GET['heatmap_start'] : null;
			$end            = isset( $_GET['heatmap_end'] ) && preg_match( '/^[2][01][0-9]{2}[\/\-][01][0-9][\/\-][0123][0-9]$/is', $_GET['heatmap_end'] ) ? $_GET['heatmap_end'] : null;
			$browser        = isset( $_GET['heatmap_browser'] ) ? strtolower( $_GET['heatmap_browser'] ) : '';
			$browserVersion = isset( $_GET['heatmap_browser_version'] ) ? $_GET['heatmap_browser_version'] : '';
			$platform       = isset( $_GET['heatmap_platform'] ) ? strtolower( $_GET['heatmap_platform'] ) : '';
			$width          = isset( $_GET['heatmap_width'] ) && is_numeric( $_GET['heatmap_width'] ) ? $_GET['heatmap_width'] : '';
			$args           = array();
			if ( $start ) {
				$args['start'] = $start;
			}
			if ( $end ) {
				$args['end'] = $end;
			}
			if ( $browser ) {
				$args['browser'] = $browser;
			}
			if ( $browser && $browserVersion ) {
				$args['browserVersion'] = $browserVersion;
			}
			if ( $platform ) {
				$args['platform'] = $platform;
			}
			if ( $width ) {
				$args['width'] = $width;
			}
			$this->generateHeatmap( $page['type'], $page['value'], $args );
		}
		echo '</script>';
	}

	/**
	 * Generate heatmap, print click data variable (wrap it on <script></script>)
	 *
	 * Available args:
	 * string $start Start date (d/m/Y)
	 * string $end End date (d/m/Y)
	 * string $browser Filter to only click by specified browser, see getBrowser method for list of supported browser name
	 * string $browserVersion The specific browser version to target at, could use some wildcard (for example: 7.*)
	 * string $platform Filter to only click by specified platform, see getBrowser method for list of supported platform name
	 * int $width Filter to width
	 *
	 * @param string $object_type Object type
	 * @param int|string $object_value Object value
	 * @param array $args Additional arguments
	 *
	 */
	public function generateHeatmap( $object_type, $object_value, $args ) {
		global $wpdb;
		$defaults = array(
			'start'          => '',
			'end'            => '',
			'browser'        => 'all',
			'browserVersion' => 'all',
			'platform'       => 'all',
			'width'          => 0,
		);
		$args     = wp_parse_args( $args, $defaults );
		extract( $args );

		$data                 = array();
		$data['object_type']  = $object_type;
		$data['object_value'] = $object_value;
		$data['start_date']   = $start;
		$data['end_date']     = $end;
		$data['browser']      = $browser;
		$data['platform']     = $platform;
		$data['width']        = $width;

		$url       = $this->server . 'admin-ajax.php';
		$key       = get_option( 'mainwp_child_pubkey' );
		$timestamp = time();
		$signature = $this->createSignature( $key, $timestamp, $data );

		$params = array(
			'headers' => array(
				'Referer' => site_url(),
			),
			'body'    => array(
				'timestamp' => $timestamp,
				'signature' => $signature,
				'data'      => base64_encode( serialize( $data ) ),
				'action'    => 'heatmapGetClickData',
			),
			'timeout' => 60,
		);

		if ( strpos( $url, 'https://' ) === 0 ) {
			$params['sslverify'] = false;
		}

		$request = wp_remote_post( $url, $params );

		if ( is_array( $request ) ) {
			$clicks = array();
			if (! empty($request['body']) ) {
				if (preg_match('/<heatmap>(.*)<\/heatmap>/', $request['body'], $results) > 0) {
					$result = $results[1];
					$clicks = json_decode($result);
				}
			}
			$clickData = array();
			if ( is_array( $clicks ) ) {
				foreach ($clicks as $click) {
					$clickData[] = array(
						'x' => $click->x,
						'y' => $click->y,
						'w' => $click->w,
						'h' => $click->h,
					);
				}
			}
			?>
			var heatmapClick = <?php echo json_encode( $clickData ) ?>;
            var heatmapError = 0;
			<?php
		} else {
			?>
            var heatmapError = 1;
			<?php
		}
	}
}

?>
