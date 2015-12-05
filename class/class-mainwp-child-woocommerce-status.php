<?php

class MainWP_Child_WooCommerce_Status {
	public static $instance = null;

	static function Instance() {
		if ( null === MainWP_Child_WooCommerce_Status::$instance ) {
			MainWP_Child_WooCommerce_Status::$instance = new MainWP_Child_WooCommerce_Status();
		}

		return MainWP_Child_WooCommerce_Status::$instance;
	}

	public function __construct() {
		add_action( 'mainwp_child_deactivation', array( $this, 'child_deactivation' ) );

	}

	public function child_deactivation() {

	}

	public function action() {
		$information = array();
		if ( ! class_exists( 'WooCommerce' ) ) {
			$information['error'] = 'NO_WOOCOMMERCE';
			MainWP_Helper::write( $information );
		}

		$is_ver220 = $this->is_version_220();
		if ( isset( $_POST['mwp_action'] ) ) {
			switch ( $_POST['mwp_action'] ) {
				case 'sync_data':
					$information = ! $is_ver220 ? $this->sync_data() : $this->sync_data_two();
					break;
				case 'report_data':
					$information = ! $is_ver220 ? $this->report_data() : $this->report_data_two();
					break;
			}
		}
		MainWP_Helper::write( $information );
	}

	function is_version_220() {
		return version_compare( WC()->version, '2.2.0', '>=' );
	}

	function sync_data() {
		global $wpdb;
		$file = WP_PLUGIN_DIR . '/woocommerce/includes/admin/reports/class-wc-admin-report.php';
		if ( file_exists( $file ) ) {
			include_once( $file );
		} else {
			return false;
		}

		$reports = new WC_Admin_Report();

		// Get sales
		$sales = $wpdb->get_var( $wpdb->prepare( "SELECT SUM( postmeta.meta_value ) FROM {$wpdb->posts} as posts
                LEFT JOIN {$wpdb->term_relationships} AS rel ON posts.ID=rel.object_ID
                LEFT JOIN {$wpdb->term_taxonomy} AS tax USING( term_taxonomy_id )
                LEFT JOIN {$wpdb->terms} AS term USING( term_id )
                LEFT JOIN {$wpdb->postmeta} AS postmeta ON posts.ID = postmeta.post_id
                WHERE 	posts.post_type 	= 'shop_order'
                AND 	posts.post_status 	= 'publish'
                AND 	tax.taxonomy		= 'shop_order_status'
                AND		term.slug			IN ( '" . implode( "','", apply_filters( 'woocommerce_reports_order_statuses', array(
				'completed',
				'processing',
				'on-hold',
			) ) ) . "' )
                AND 	postmeta.meta_key   = '_order_total'
                AND 	posts.post_date >= %s
                AND 	posts.post_date <= %s
        ", date( 'Y-m-01', $start_date ), date( 'Y-m-d H:i:s', $end_date ) ) );



		// Get top seller
		$top_seller = $wpdb->get_row( $wpdb->prepare(  "SELECT SUM( order_item_meta.meta_value ) as qty, order_item_meta_2.meta_value as product_id
                FROM {$wpdb->posts} as posts
                LEFT JOIN {$wpdb->term_relationships} AS rel ON posts.ID=rel.object_ID
                LEFT JOIN {$wpdb->term_taxonomy} AS tax USING( term_taxonomy_id )
                LEFT JOIN {$wpdb->terms} AS term USING( term_id )
                LEFT JOIN {$wpdb->prefix}woocommerce_order_items AS order_items ON posts.ID = order_id
                LEFT JOIN {$wpdb->prefix}woocommerce_order_itemmeta AS order_item_meta ON order_items.order_item_id = order_item_meta.order_item_id
                LEFT JOIN {$wpdb->prefix}woocommerce_order_itemmeta AS order_item_meta_2 ON order_items.order_item_id = order_item_meta_2.order_item_id
                WHERE 	posts.post_type 	= 'shop_order'
                AND 	posts.post_status 	= 'publish'
                AND 	tax.taxonomy		= 'shop_order_status'
                AND		term.slug			IN ( '" . implode( "','", apply_filters( 'woocommerce_reports_order_statuses', array(
				'completed',
				'processing',
				'on-hold',
			) ) ) . "' )
                AND 	order_item_meta.meta_key = '_qty'
                AND 	order_item_meta_2.meta_key = '_product_id'
                AND 	posts.post_date >= %s
                AND 	posts.post_date <= %s
                GROUP BY product_id
                ORDER BY qty DESC
                LIMIT   1
        ", date( 'Y-m-01', $start_date ),  date( 'Y-m-d H:i:s', $end_date )) );

		if ( ! empty( $top_seller ) ) {
			$top_seller->name = get_the_title( $top_seller->product_id );
		}

		// Counts
		$on_hold_count    = get_term_by( 'slug', 'on-hold', 'shop_order_status' )->count;
		$processing_count = get_term_by( 'slug', 'processing', 'shop_order_status' )->count;

		// Get products using a query - this is too advanced for get_posts :(
		$stock   = absint( max( get_option( 'woocommerce_notify_low_stock_amount' ), 1 ) );
		$nostock = absint( max( get_option( 'woocommerce_notify_no_stock_amount' ), 0 ) );

		$query_from = "FROM {$wpdb->posts} as posts
                INNER JOIN {$wpdb->postmeta} AS postmeta ON posts.ID = postmeta.post_id
                INNER JOIN {$wpdb->postmeta} AS postmeta2 ON posts.ID = postmeta2.post_id
                WHERE 1=1
                        AND posts.post_type IN ('product', 'product_variation')
                        AND posts.post_status = 'publish'
                        AND (
                                postmeta.meta_key = '_stock' AND CAST(postmeta.meta_value AS SIGNED) <= '{$stock}' AND CAST(postmeta.meta_value AS SIGNED) > '{$nostock}' AND postmeta.meta_value != ''
                        )
                        AND (
                                ( postmeta2.meta_key = '_manage_stock' AND postmeta2.meta_value = 'yes' ) OR ( posts.post_type = 'product_variation' )
                        )
                ";

		$lowinstock_count = absint( $wpdb->get_var( "SELECT COUNT( DISTINCT posts.ID ) {$query_from};" ) );

		$query_from = "FROM {$wpdb->posts} as posts
                INNER JOIN {$wpdb->postmeta} AS postmeta ON posts.ID = postmeta.post_id
                INNER JOIN {$wpdb->postmeta} AS postmeta2 ON posts.ID = postmeta2.post_id
                WHERE 1=1
                        AND posts.post_type IN ('product', 'product_variation')
                        AND posts.post_status = 'publish'
                        AND (
                                postmeta.meta_key = '_stock' AND CAST(postmeta.meta_value AS SIGNED) <= '{$nostock}' AND postmeta.meta_value != ''
                        )
                        AND (
                                ( postmeta2.meta_key = '_manage_stock' AND postmeta2.meta_value = 'yes' ) OR ( posts.post_type = 'product_variation' )
                        )
                ";

		$outofstock_count = absint( $wpdb->get_var( "SELECT COUNT( DISTINCT posts.ID ) {$query_from};" ) );

		$data                = array(
			'sales'          => $sales,
			'formated_sales' => wc_price( $sales ),
			'top_seller'     => $top_seller,
			'onhold'         => $on_hold_count,
			'awaiting'       => $processing_count,
			'stock'          => $stock,
			'nostock'        => $nostock,
			'lowstock'       => $lowinstock_count,
			'outstock'       => $outofstock_count,
		);
		$information['data'] = $data;

		return $information;
	}

	function report_data() {
		global $wpdb;
		$file = WP_PLUGIN_DIR . '/woocommerce/includes/admin/reports/class-wc-admin-report.php';
		if ( file_exists( $file ) ) {
			include_once( $file );
		} else {
			return false;
		}

		$reports    = new WC_Admin_Report();
		$start_date = $_POST['start_date'];
		$end_date   = $_POST['end_date'];
		// Get sales
		$sales = $wpdb->get_var( "SELECT SUM( postmeta.meta_value ) FROM {$wpdb->posts} as posts
                LEFT JOIN {$wpdb->term_relationships} AS rel ON posts.ID=rel.object_ID
                LEFT JOIN {$wpdb->term_taxonomy} AS tax USING( term_taxonomy_id )
                LEFT JOIN {$wpdb->terms} AS term USING( term_id )
                LEFT JOIN {$wpdb->postmeta} AS postmeta ON posts.ID = postmeta.post_id
                WHERE 	posts.post_type 	= 'shop_order'
                AND 	posts.post_status 	= 'publish'
                AND 	tax.taxonomy		= 'shop_order_status'
                AND		term.slug			IN ( '" . implode( "','", apply_filters( 'woocommerce_reports_order_statuses', array(
					'completed',
					'processing',
					'on-hold',
				) ) ) . "' )
                AND 	postmeta.meta_key   = '_order_total'
                AND 	posts.post_date >= '" . date( 'Y-m-01', $start_date ) . "'
                AND 	posts.post_date <= '" . date( 'Y-m-d H:i:s', $end_date ) . "'
        " );

		// Get top seller
		$top_seller = $wpdb->get_row( "SELECT SUM( order_item_meta.meta_value ) as qty, order_item_meta_2.meta_value as product_id
                FROM {$wpdb->posts} as posts
                LEFT JOIN {$wpdb->term_relationships} AS rel ON posts.ID=rel.object_ID
                LEFT JOIN {$wpdb->term_taxonomy} AS tax USING( term_taxonomy_id )
                LEFT JOIN {$wpdb->terms} AS term USING( term_id )
                LEFT JOIN {$wpdb->prefix}woocommerce_order_items AS order_items ON posts.ID = order_id
                LEFT JOIN {$wpdb->prefix}woocommerce_order_itemmeta AS order_item_meta ON order_items.order_item_id = order_item_meta.order_item_id
                LEFT JOIN {$wpdb->prefix}woocommerce_order_itemmeta AS order_item_meta_2 ON order_items.order_item_id = order_item_meta_2.order_item_id
                WHERE 	posts.post_type 	= 'shop_order'
                AND 	posts.post_status 	= 'publish'
                AND 	tax.taxonomy		= 'shop_order_status'
                AND		term.slug			IN ( '" . implode( "','", apply_filters( 'woocommerce_reports_order_statuses', array(
					'completed',
					'processing',
					'on-hold',
				) ) ) . "' )
                AND 	order_item_meta.meta_key = '_qty'
                AND 	order_item_meta_2.meta_key = '_product_id'
                AND 	posts.post_date >= '" . date( 'Y-m-01', $start_date ) . "'
                AND 	posts.post_date <= '" . date( 'Y-m-d H:i:s', $end_date ) . "'
                GROUP BY product_id
                ORDER BY qty DESC
                LIMIT   1
        " );

		if ( ! empty( $top_seller ) ) {
			$top_seller->name = get_the_title( $top_seller->product_id );
		}

		// Counts
		$on_hold_count    = get_term_by( 'slug', 'on-hold', 'shop_order_status' )->count;
		$processing_count = get_term_by( 'slug', 'processing', 'shop_order_status' )->count;

		// Get products using a query - this is too advanced for get_posts :(
		$stock   = absint( max( get_option( 'woocommerce_notify_low_stock_amount' ), 1 ) );
		$nostock = absint( max( get_option( 'woocommerce_notify_no_stock_amount' ), 0 ) );

		$query_from = "FROM {$wpdb->posts} as posts
                INNER JOIN {$wpdb->postmeta} AS postmeta ON posts.ID = postmeta.post_id
                INNER JOIN {$wpdb->postmeta} AS postmeta2 ON posts.ID = postmeta2.post_id
                WHERE 1=1
                        AND posts.post_type IN ('product', 'product_variation')
                        AND posts.post_status = 'publish'
                        AND (
                                postmeta.meta_key = '_stock' AND CAST(postmeta.meta_value AS SIGNED) <= '{$stock}' AND CAST(postmeta.meta_value AS SIGNED) > '{$nostock}' AND postmeta.meta_value != ''
                        )
                        AND (
                                ( postmeta2.meta_key = '_manage_stock' AND postmeta2.meta_value = 'yes' ) OR ( posts.post_type = 'product_variation' )
                        )
                ";

		$lowinstock_count = absint( $wpdb->get_var( "SELECT COUNT( DISTINCT posts.ID ) {$query_from};" ) );

		$query_from = "FROM {$wpdb->posts} as posts
                INNER JOIN {$wpdb->postmeta} AS postmeta ON posts.ID = postmeta.post_id
                INNER JOIN {$wpdb->postmeta} AS postmeta2 ON posts.ID = postmeta2.post_id
                WHERE 1=1
                        AND posts.post_type IN ('product', 'product_variation')
                        AND posts.post_status = 'publish'
                        AND (
                                postmeta.meta_key = '_stock' AND CAST(postmeta.meta_value AS SIGNED) <= '{$nostock}' AND postmeta.meta_value != ''
                        )
                        AND (
                                ( postmeta2.meta_key = '_manage_stock' AND postmeta2.meta_value = 'yes' ) OR ( posts.post_type = 'product_variation' )
                        )
                ";

		$outofstock_count = absint( $wpdb->get_var( "SELECT COUNT( DISTINCT posts.ID ) {$query_from};" ) );

		$data                = array(
			'sales'          => $sales,
			'formated_sales' => wc_price( $sales ),
			'top_seller'     => $top_seller,
			'onhold'         => $on_hold_count,
			'awaiting'       => $processing_count,
			'stock'          => $stock,
			'nostock'        => $nostock,
			'lowstock'       => $lowinstock_count,
			'outstock'       => $outofstock_count,
		);
		$information['data'] = $data;

		return $information;
	}

	function sync_data_two() {
		$start_date = current_time( 'timestamp' );
		$end_date   = current_time( 'timestamp' );

		return $this->get_woocom_data( $start_date, $end_date );
	}

	function report_data_two() {
		$start_date = $_POST['start_date'];
		$end_date   = $_POST['end_date'];

		return $this->get_woocom_data( $start_date, $end_date );
	}

	function get_woocom_data( $start_date, $end_date ) {
		global $wpdb;
		$file = WP_PLUGIN_DIR . '/woocommerce/includes/admin/reports/class-wc-admin-report.php';
		if ( file_exists( $file ) ) {
			include_once( $file );
		} else {
			return false;
		}
		$reports = new WC_Admin_Report();
		// Sales
		$query           = array();
		$query['fields'] = "SELECT SUM( postmeta.meta_value ) FROM {$wpdb->posts} as posts";
		$query['join']   = "INNER JOIN {$wpdb->postmeta} AS postmeta ON posts.ID = postmeta.post_id ";
		$query['where']  = "WHERE posts.post_type IN ( '" . implode( "','", wc_get_order_types( 'reports' ) ) . "' ) ";
		$query['where'] .= "AND posts.post_status IN ( 'wc-" . implode( "','wc-", apply_filters( 'woocommerce_reports_order_statuses', array(
				'completed',
				'processing',
				'on-hold',
			) ) ) . "' ) ";
		$query['where'] .= "AND postmeta.meta_key   = '_order_total' ";
		$query['where'] .= "AND posts.post_date >= '" . date( 'Y-m-01', $start_date ) . "' ";
		$query['where'] .= "AND posts.post_date <= '" . date( 'Y-m-d H:i:s', $end_date ) . "' ";

		$sales = $wpdb->get_var( implode( ' ', apply_filters( 'woocommerce_dashboard_status_widget_sales_query', $query ) ) );

		// Get top seller
		$query           = array();
		$query['fields'] = "SELECT SUM( order_item_meta.meta_value ) as qty, order_item_meta_2.meta_value as product_id
                FROM {$wpdb->posts} as posts";
		$query['join']   = "INNER JOIN {$wpdb->prefix}woocommerce_order_items AS order_items ON posts.ID = order_id ";
		$query['join'] .= "INNER JOIN {$wpdb->prefix}woocommerce_order_itemmeta AS order_item_meta ON order_items.order_item_id = order_item_meta.order_item_id ";
		$query['join'] .= "INNER JOIN {$wpdb->prefix}woocommerce_order_itemmeta AS order_item_meta_2 ON order_items.order_item_id = order_item_meta_2.order_item_id ";
		$query['where'] = "WHERE posts.post_type IN ( '" . implode( "','", wc_get_order_types( 'order-count' ) ) . "' ) ";
		$query['where'] .= "AND posts.post_status IN ( 'wc-" . implode( "','wc-", apply_filters( 'woocommerce_reports_order_statuses', array(
				'completed',
				'processing',
				'on-hold',
			) ) ) . "' ) ";
		$query['where'] .= "AND order_item_meta.meta_key = '_qty' ";
		$query['where'] .= "AND order_item_meta_2.meta_key = '_product_id' ";
		$query['where'] .= "AND posts.post_date >= %s ";
		$query['where'] .= "AND posts.post_date <= %s ";
		$query['groupby'] = 'GROUP BY product_id';
		$query['orderby'] = 'ORDER BY qty DESC';
		$query['limits']  = 'LIMIT 1';

		$top_seller = $wpdb->get_row(  $wpdb->prepare( implode( ' ',  $query ), date( 'Y-m-01', $start_date ), date( 'Y-m-d H:i:s', $end_date ) ) );


		if ( ! empty( $top_seller ) ) {
			$top_seller->name = get_the_title( $top_seller->product_id );
		}

		// Counts
		$on_hold_count    = 0;
		$processing_count = 0;

		foreach ( wc_get_order_types( 'order-count' ) as $type ) {
			$counts = (array) wp_count_posts( $type );
			$on_hold_count += isset( $counts['wc-on-hold'] ) ? $counts['wc-on-hold'] : 0;
			$processing_count += isset( $counts['wc-processing'] ) ? $counts['wc-processing'] : 0;
		}

		// Get products using a query - this is too advanced for get_posts :(
		$stock   = absint( max( get_option( 'woocommerce_notify_low_stock_amount' ), 1 ) );
		$nostock = absint( max( get_option( 'woocommerce_notify_no_stock_amount' ), 0 ) );

		$query_from = "FROM {$wpdb->posts} as posts
                INNER JOIN {$wpdb->postmeta} AS postmeta ON posts.ID = postmeta.post_id
                INNER JOIN {$wpdb->postmeta} AS postmeta2 ON posts.ID = postmeta2.post_id
                WHERE 1=1
                        AND posts.post_type IN ('product', 'product_variation')
                        AND posts.post_status = 'publish'
                        AND (
                                postmeta.meta_key = '_stock' AND CAST(postmeta.meta_value AS SIGNED) <= '{$stock}' AND CAST(postmeta.meta_value AS SIGNED) > '{$nostock}' AND postmeta.meta_value != ''
                        )
                        AND (
                                ( postmeta2.meta_key = '_manage_stock' AND postmeta2.meta_value = 'yes' ) OR ( posts.post_type = 'product_variation' )
                        )
                ";

		$lowinstock_count = absint( $wpdb->get_var( "SELECT COUNT( DISTINCT posts.ID ) {$query_from};" ) );

		$query_from = "FROM {$wpdb->posts} as posts
                INNER JOIN {$wpdb->postmeta} AS postmeta ON posts.ID = postmeta.post_id
                INNER JOIN {$wpdb->postmeta} AS postmeta2 ON posts.ID = postmeta2.post_id
                WHERE 1=1
                        AND posts.post_type IN ('product', 'product_variation')
                        AND posts.post_status = 'publish'
                        AND (
                                postmeta.meta_key = '_stock' AND CAST(postmeta.meta_value AS SIGNED) <= '{$nostock}' AND postmeta.meta_value != ''
                        )
                        AND (
                                ( postmeta2.meta_key = '_manage_stock' AND postmeta2.meta_value = 'yes' ) OR ( posts.post_type = 'product_variation' )
                        )
                ";

		$outofstock_count = absint( $wpdb->get_var( "SELECT COUNT( DISTINCT posts.ID ) {$query_from};" ) );

		$data                = array(
			'sales'          => $sales,
			'formated_sales' => wc_price( $sales ),
			'top_seller'     => $top_seller,
			'onhold'         => $on_hold_count,
			'awaiting'       => $processing_count,
			'stock'          => $stock,
			'nostock'        => $nostock,
			'lowstock'       => $lowinstock_count,
			'outstock'       => $outofstock_count,
		);
		$information['data'] = $data;

		return $information;
	}
}

