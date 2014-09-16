<?php

class MainWPChildWooCommerceStatus      
{   
    public static $instance = null;   
    
    static function Instance() {
        if (MainWPChildWooCommerceStatus::$instance == null) {
            MainWPChildWooCommerceStatus::$instance = new MainWPChildWooCommerceStatus();
        }
        return MainWPChildWooCommerceStatus::$instance;
    }  
    
    public function __construct() {
        add_action('mainwp_child_deactivation', array($this, 'child_deactivation'));
         
    }  
   
    public function child_deactivation()
    {
        
    }
      
    public function action() {  
        $information = array();
        if (!class_exists('WooCommerce')) {
            $information['error'] = 'NO_WOOCOMMERCE';
            MainWPHelper::write($information);
        }  
        if (isset($_POST['mwp_action'])) {
            switch ($_POST['mwp_action']) {    
                case "sync_data":
                    $information = $this->sync_data();                    
                    break;                
                case "report_data":
                    $information = $this->report_data();                    
                    break;                
            }        
        }
        MainWPHelper::write($information);
    }  
    
    function sync_data() {  
        global $wpdb; 
        $file = WP_PLUGIN_DIR . '/woocommerce/includes/admin/reports/class-wc-admin-report.php';
        if (file_exists($file))
            include_once( $file );
        else 
            return false;
        
        $reports = new WC_Admin_Report();

        // Get sales
        $sales = $wpdb->get_var( "SELECT SUM( postmeta.meta_value ) FROM {$wpdb->posts} as posts
                LEFT JOIN {$wpdb->term_relationships} AS rel ON posts.ID=rel.object_ID
                LEFT JOIN {$wpdb->term_taxonomy} AS tax USING( term_taxonomy_id )
                LEFT JOIN {$wpdb->terms} AS term USING( term_id )
                LEFT JOIN {$wpdb->postmeta} AS postmeta ON posts.ID = postmeta.post_id
                WHERE 	posts.post_type 	= 'shop_order'
                AND 	posts.post_status 	= 'publish'
                AND 	tax.taxonomy		= 'shop_order_status'
                AND		term.slug			IN ( '" . implode( "','", apply_filters( 'woocommerce_reports_order_statuses', array( 'completed', 'processing', 'on-hold' ) ) ) . "' )
                AND 	postmeta.meta_key   = '_order_total'
                AND 	posts.post_date >= '" . date( 'Y-m-01', current_time( 'timestamp' ) ) . "'
                AND 	posts.post_date <= '" . date( 'Y-m-d H:i:s', current_time( 'timestamp' ) ) . "'
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
                AND		term.slug			IN ( '" . implode( "','", apply_filters( 'woocommerce_reports_order_statuses', array( 'completed', 'processing', 'on-hold' ) ) ) . "' )
                AND 	order_item_meta.meta_key = '_qty'
                AND 	order_item_meta_2.meta_key = '_product_id'
                AND 	posts.post_date >= '" . date( 'Y-m-01', current_time( 'timestamp' ) ) . "'
                AND 	posts.post_date <= '" . date( 'Y-m-d H:i:s', current_time( 'timestamp' ) ) . "'
                GROUP BY product_id
                ORDER BY qty DESC
                LIMIT   1
        " );
                
        if (!empty($top_seller))
            $top_seller->name = get_the_title( $top_seller->product_id );
         
        // Counts
        $on_hold_count      = get_term_by( 'slug', 'on-hold', 'shop_order_status' )->count;
        $processing_count   = get_term_by( 'slug', 'processing', 'shop_order_status' )->count;

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
                         
	$data = array('sales' => $sales,
                'formated_sales' => wc_price( $sales ),
                'top_seller' => $top_seller,  
                'onhold' => $on_hold_count,  
                'awaiting' => $processing_count,  
                'stock' => $stock,  
                'nostock' => $nostock,
                'lowstock' => $lowinstock_count,
                'outstock' => $outofstock_count,
            );	
        $information['data'] = $data;
        return $information;
    }
    
    function report_data() {  
        global $wpdb; 
        $file = WP_PLUGIN_DIR . '/woocommerce/includes/admin/reports/class-wc-admin-report.php';
        if (file_exists($file))
            include_once( $file );
        else 
            return false;
        
        $reports = new WC_Admin_Report();
        $start_date = $_POST['start_date'];
        $end_date = $_POST['end_date'];
        // Get sales
        $sales = $wpdb->get_var( "SELECT SUM( postmeta.meta_value ) FROM {$wpdb->posts} as posts
                LEFT JOIN {$wpdb->term_relationships} AS rel ON posts.ID=rel.object_ID
                LEFT JOIN {$wpdb->term_taxonomy} AS tax USING( term_taxonomy_id )
                LEFT JOIN {$wpdb->terms} AS term USING( term_id )
                LEFT JOIN {$wpdb->postmeta} AS postmeta ON posts.ID = postmeta.post_id
                WHERE 	posts.post_type 	= 'shop_order'
                AND 	posts.post_status 	= 'publish'
                AND 	tax.taxonomy		= 'shop_order_status'
                AND		term.slug			IN ( '" . implode( "','", apply_filters( 'woocommerce_reports_order_statuses', array( 'completed', 'processing', 'on-hold' ) ) ) . "' )
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
                AND		term.slug			IN ( '" . implode( "','", apply_filters( 'woocommerce_reports_order_statuses', array( 'completed', 'processing', 'on-hold' ) ) ) . "' )
                AND 	order_item_meta.meta_key = '_qty'
                AND 	order_item_meta_2.meta_key = '_product_id'
                AND 	posts.post_date >= '" . date( 'Y-m-01', $start_date ) . "'
                AND 	posts.post_date <= '" . date( 'Y-m-d H:i:s', $end_date ) . "'
                GROUP BY product_id
                ORDER BY qty DESC
                LIMIT   1
        " );
                
        if (!empty($top_seller))
            $top_seller->name = get_the_title( $top_seller->product_id );
         
        // Counts
        $on_hold_count      = get_term_by( 'slug', 'on-hold', 'shop_order_status' )->count;
        $processing_count   = get_term_by( 'slug', 'processing', 'shop_order_status' )->count;

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
                         
	$data = array('sales' => $sales,
                'formated_sales' => wc_price( $sales ),
                'top_seller' => $top_seller,  
                'onhold' => $on_hold_count,  
                'awaiting' => $processing_count,  
                'stock' => $stock,  
                'nostock' => $nostock,
                'lowstock' => $lowinstock_count,
                'outstock' => $outofstock_count,
            );	
        $information['data'] = $data;
        return $information;
    }
    
    
}

