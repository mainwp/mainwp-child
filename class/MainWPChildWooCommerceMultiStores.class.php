<?php

class MainWPChildWooCommerceMultiStores
{   
    public static $instance = null;   
    private $posts_where_suffix;
    public $is_mainstore = false;    
    protected $server;
    static function Instance() {        
        if (MainWPChildWooCommerceMultiStores::$instance == null) {
            MainWPChildWooCommerceMultiStores::$instance = new MainWPChildWooCommerceMultiStores();
        }
        return MainWPChildWooCommerceMultiStores::$instance;
    }    
    
    public function __construct() {        
        $this->is_mainstore = (get_option('mainwp_woocom_is_mainstore') == 'yes') ? true : false;        
        $this->posts_where_suffix = '';
        $this->server = get_option('mainwp_child_server');
        add_action('mainwp_child_deactivation', array($this, 'deactivation'));  
        add_action('mainwp-site-sync-others-data', array($this, 'syncChildSitesData'));
        add_action( 'woocommerce_reduce_order_stock', array($this, 'reduceProductStock') );  
        add_filter('cron_schedules', array($this, 'getCronSchedules'));        
        add_action('wp_ajax_multiStoreSendDataSync', array($this, 'retrieveDataSync'));
        add_action('wp_ajax_nopriv_multiStoreSendDataSync', array($this, 'retrieveDataSync'));   
        $this->server = get_option('mainwp_child_server');          
    }
     
    function syncChildSitesData($data) { 
        
        if (isset($data['is_main_store']) && $data['is_main_store']) {
            MainWPHelper::update_option('mainwp_woocom_is_mainstore', 'yes'); 
            $this->is_mainstore = true;
        } else {
            delete_option('mainwp_woocom_is_mainstore'); 
            $this->is_mainstore = false;
        }
        
        $old_mainstore_pubkey = get_option('mainwp_multistore_mainstore_pubkey');        
        if (is_array($data) && isset($data['mainStorePubKey']) && (!empty($data['mainStorePubKey']))) {
            MainWPHelper::update_option('mainwp_multistore_mainstore_pubkey', $data['mainStorePubKey']);            
        } else if (!empty($old_mainstore_pubkey)){
            delete_option('mainwp_multistore_mainstore_pubkey');             
        }  
        
        if ($this->is_mainstore && isset($data['childStoreUrls']) && (count($data['childStoreUrls']) > 0)) {
            $old_storeSync = get_option('mainwp_multistore_childstores_sync');
            
            if (!is_array($old_storeSync))
                $old_storeSync = array();
            
            $storeSync = array();
            foreach($data['childStoreUrls'] as $url) {
                if (isset($old_storeSync[$url]))
                    $storeSync[$url] = $old_storeSync[$url];
                else
                    $storeSync[$url] = 0;
            }
            
            MainWPHelper::update_option('mainwp_multistore_childstores_sync', $storeSync); 
            
            $old_reduceSync = get_option('mainwp_multistore_reduceproducts_sync');
            if (!is_array($old_reduceSync))
                $old_reduceSync = array();
            
            $reduceSync = array();
            foreach($data['childStoreUrls'] as $url) {
                if (isset($old_reduceSync[$url]))
                    $reduceSync[$url] = $old_reduceSync[$url];
                else
                    $reduceSync[$url] = 0;
            }            
            MainWPHelper::update_option('mainwp_multistore_reduceproducts_sync', $reduceSync);
        } else {
            delete_option('mainwp_multistore_childstores_sync');
            delete_option('mainwp_multistore_reduceproducts_sync');
        }
       
    }
    
    function getCronSchedules() {
        $schedules['5minutely'] = array(
            'interval' => 5 * 60, // 5minutes in seconds
            'display' => __('Once every 5 minutes', 'mainwp'),
        );
        $schedules['minutely'] = array(
            'interval' => 1 * 60, // 1minute in seconds
            'display' => __('Once every minute', 'mainwp'),
        );
        return $schedules;
    }

    public function init()
    {  
        $this->init_cron();        
    } 
    
    public function deactivation()
    {
        if ($sched = wp_next_scheduled('mainwp_multistore_cron_sync_data')) {
            wp_unschedule_event($sched, 'mainwp_multistore_cron_sync_data');
        }
    }
    
    public function init_cron() {        
        add_action('mainwp_multistore_cron_sync_data', array($this, 'syncProductsData'));            
        if (($sched = wp_next_scheduled('mainwp_multistore_cron_sync_data')) == false)
        {                                
            wp_schedule_event(time(), 'minutely', 'mainwp_multistore_cron_sync_data');
        } else {
            if (!$this->is_mainstore) {
                wp_unschedule_event($sched, 'mainwp_multistore_cron_sync_data');
            }
        } 
    }    
    
    // save stock change when sold item
    public function reduceProductStock($order) {        
        if ($this->is_mainstore) {    
            if ( 'yes' == get_option('woocommerce_manage_stock') && sizeof( $order->get_items() ) > 0 ) {
                $reduceProducts = array();
                foreach ( $order->get_items() as $item ) {
                    if ( $item['product_id'] > 0) {
                        $_product = $order->get_product_from_item( $item );
                        if ( $_product && $_product->exists() && $_product->managing_stock() ) {                                                         
                            $reduceProducts[$_product->sku] = $_product->stock;
                        }
                    }

                }   

                if (count($reduceProducts) > 0) {
                    $this->updateReduceProducts($reduceProducts);   
                }             
            }
            return;  
        }
        
        if ( 'yes' == get_option('woocommerce_manage_stock') && sizeof( $order->get_items() ) > 0 ) {
            $reduceProducts = get_option('mainwp_multistore_reducestock_products');  
            if (!is_array($reduceProducts)) {
                $reduceProducts = array();
            }
            foreach ( $order->get_items() as $item ) {
                if ( $item['product_id'] > 0) {
                    $_product = $order->get_product_from_item( $item );
                    if ( $_product && $_product->exists() && $_product->managing_stock() ) {                                                         
                        $reduceProducts[$_product->sku] = $_product->stock;
                    }
                }

            }                     
            MainWPHelper::update_option('mainwp_multistore_reducestock_products', $reduceProducts); 
        }
    }
    
    // schedule store process 
    function syncProductsData() {  
        
        if (!class_exists('WooCommerce')) 
            return;        
        
        if (!$this->is_mainstore) 
            return;
        
        $childStoresSync = get_option('mainwp_multistore_childstores_sync');     
        
        if (!is_array($childStoresSync) || count($childStoresSync) == 0) {
            return;
        }
        
        if (get_option('mainwp_multistore_update_reduceproducts_tosync') == 'yes') {
            $this->setReduceProductsToSync(); 
            delete_option('mainwp_multistore_update_reduceproducts_tosync');
            delete_option('mainwp_multistore_reduce_products_ids');
        }
        
        $reduceProductsIds = get_option('mainwp_multistore_reduce_products_ids');    
        
        $modifiedMSProducts = get_option('mainwp_multistore_modified_mainstore_products');  
        
        $sitesSyncNow = array();
        if (is_array($modifiedMSProducts) && (count($modifiedMSProducts) > 0) && is_array($childStoresSync) && (count($childStoresSync) > 0)) {            
            $i = 0;
            foreach($childStoresSync as $store_url => $lastsync) {
                 foreach($modifiedMSProducts as $product_id => $lastchange) {
                     // update reduced products later
                     if (isset($reduceProductsIds[$product_id])) 
                         continue;
                     
                     if ($lastsync < $lastchange) {
                         $sitesSyncNow[$store_url][] = $product_id;
                     }
                 }
                 $i++;
                 if ($i >= 4) {
                     break;
                 }
            }
        } 
        
        $update = false;        
        if (count($sitesSyncNow) > 0) {
            foreach($sitesSyncNow as $url => $product_ids) {
                $syncData = array();
                $childStoresSync[$url] = time();
                foreach($product_ids as $product_id) {
                    $_product =  wc_get_product( $product_id );
                    if (empty($_product)) {
                        unset($modifiedMSProducts[$product_id]);
                        $update = true;
                    } else if (!empty($_product->sku)) {

                        $data = array(
                            '_id' => $product_id,
                            '_sku' => $_product->sku,
                            '_regular_price' => $_product->regular_price,            
                            '_sale_price' => $_product->sale_price,
                            '_manage_stock' => $_product->manage_stock,
                            '_stock_status' => $_product->stock_status,
                            '_stock' => $_product->stock,                            
                            '_backorders' => $_product->backorders,
                        );
                        $syncData[$_product->sku] = $data;
                    }
                }                
                if (!empty($syncData)) {
                    $this->sendDataSync($url, $syncData);                         
                }
            }                      
            MainWPHelper::update_option('mainwp_multistore_childstores_sync', $childStoresSync); 
            if ($update)
                MainWPHelper::update_option('mainwp_multistore_modified_mainstore_products', $modifiedMSProducts);             
        } else { 
            // do sync reduce products
            $this->syncReduceProducts(); 
        }        
    }
    
    
    function syncReduceProducts() { 
        $reduceProductsSync = get_option('mainwp_multistore_reduceproducts_sync');
        if (!is_array($reduceProductsSync) || count($reduceProductsSync) == 0) {
            return;
        }
        $lasttimeSyncReduce = get_option('mainwp_multistore_lasttime_reduceproducts_sync');          
        $sitesSyncReduceProductsNow = array();
        
        $i = 0;
        foreach($reduceProductsSync as $store_url => $lastsync) {             
            if ($lastsync < $lasttimeSyncReduce) {
                $sitesSyncReduceProductsNow[] = $store_url;
            }
            $i++;
            if ($i >= 4) {
                break;
            }
        }
                
        if (count($sitesSyncReduceProductsNow) > 0) {
            foreach($sitesSyncReduceProductsNow as $url) {             
                $reduceProductsSync[$url] = time();
                $syncData = array('sync_reduce_products' => 1);
                $information = $this->sendDataSync($url, $syncData);     
                if (is_array($information) && isset($information['reduceUpdate'])) {
                    $reduce_products = $information['reduceUpdate'];
                    if (is_array($reduce_products) && (count($reduce_products) > 0)) {
                        $this->updateReduceProducts($reduce_products);
                    }                                
                }                
            }                      
            MainWPHelper::update_option('mainwp_multistore_reduceproducts_sync', $reduceProductsSync); 
        } else {
            MainWPHelper::update_option('mainwp_multistore_lasttime_reduceproducts_sync', time());
            MainWPHelper::update_option('mainwp_multistore_update_reduceproducts_tosync', 'yes');            
        }
        return true;
    }
    
    
    function setReduceProductsToSync() {
        $reduce_products = get_option('mainwp_multistore_reduce_products_to_sync'); 
         
        if (is_array($reduce_products) && (count($reduce_products) > 0)) {
            foreach ($reduce_products as $data) {
                if (isset($data['product_id']) && ($post_id = $data['product_id'])) {                        
                    $product = wc_get_product( $post_id );  
                    if ($product && $product->manage_stock == 'yes') {
                        $new_stock = $product->stock - $data['reduce_stock'];
                         wc_update_product_stock( $post_id, wc_stock_amount( $new_stock ) );
                         $this->saveMainStoreProductsModified($post_id);
                    }
                    delete_post_meta($post_id, '_old_product_data');
                }                    
            }
            delete_option('mainwp_multistore_reduce_products_to_sync');
        }            
    }
    
    function updateReduceProducts($products) {
        global $wpdb;
        $reducedData = array();
        
        foreach ( $products as $_sku => $_stock)
        {            
            $post_id = $wpdb->get_var( $wpdb->prepare( "SELECT post_id FROM $wpdb->postmeta WHERE meta_key='_sku' AND meta_value='%s' LIMIT 1", $_sku ) );
            if ($post_id) { 
                $product = wc_get_product( $post_id ); 
                
                if (empty($product)) 
                    continue;  
                
                $_old_data = get_post_meta($post_id, '_old_product_data', true);
                if (is_array($_old_data) && isset($_old_data['old_stock'])) {
                    $old_stock = $_old_data['old_stock'];
                } else {
                    $old_stock = $product->stock;
                }

                if ($old_stock < $_stock) { 
                    // To fix bug: do not set stock to value bigger than current value
                    $_stock = $old_stock;                         
                }

                if ($product->manage_stock == 'yes') {
                    //wc_update_product_stock( $post_id, wc_stock_amount( $stock ) );
                    $_reduce = $old_stock - $_stock;
                    $_data = array(
                        'product_id' => $post_id,
                        'reduce_stock' => $_reduce
                    );
                    $reducedData[$post_id] = $_data;
                }    
            }
        }
        
        if (count($reducedData) > 0)
             $this->saveReduceProductsToSync($reducedData);   
    }
    
    private function sendDataSync($pUrl, $data)
    {
            $url = $pUrl . 'wp-admin/admin-ajax.php';
            
            if ( ! is_array($data) )
                    return false;
            
            $key = get_option('mainwp_multistore_mainstore_pubkey');
          
            $timestamp = time();
            $signature = $this->createSignature($key, $timestamp, $data);

            $params = array(
                    'headers' => array(
                            'Referer' => site_url()
                    ),
                    'body' => array(
                            'timestamp' => $timestamp,
                            'signature' => $signature,
                            'data' => base64_encode(serialize($data)),
                            'action' => 'multiStoreSendDataSync'
                    ),
                    'timeout' => 60
            );

            if (strpos($url, "https://") === 0)
                  $params['sslverify'] = FALSE; 

            $request = wp_remote_post($url, $params);  
            
            if ( is_array($request) && isset($request['body'])) {   
                if (preg_match('/<mainwp>(.*)<\/mainwp>/', $request['body'], $results) > 0) {
                    $result = $results[1];
                    $information = unserialize(base64_decode($result));                                                    
                    return $information;
                }                               
            }
            return false;
    }

    
    public function checkSignature( $signature, $timestamp, $data )
    {
        $mainstore_pubkey = get_option('mainwp_multistore_mainstore_pubkey'); 
        if ( ! $mainstore_pubkey )
                return false;

        $createSign = $this->createSignature($mainstore_pubkey, $timestamp, $data);
        return ( $signature == $createSign );
    }

    public function createSignature( $key, $timestamp, $data )
    {
            $datamd5 = md5($timestamp.base64_encode(serialize($data)));
            $signature = md5($key.$datamd5);
            return $signature;
    }

    public function retrieveDataSync() {
   
            $data = ( isset($_POST['data']) ) ? unserialize(base64_decode($_POST['data'])) : null;
            
            if ( 
                    ! isset($_POST['signature']) ||
                    ! isset($_POST['timestamp']) ||
                    ! $data ||
                    ! $this->checkSignature($_POST['signature'], $_POST['timestamp'], $data) 
            )
                    die(-1);
            
            if ( ! is_array($data) )
                    die(-1);
            
            global $wpdb;
            
            $reduceProducts = get_option('mainwp_multistore_reducestock_products');
            if(!is_array($reduceProducts))
                $reduceProducts = array();
            
            $information = array();
            if (isset($data['sync_reduce_products'])) {                 
                if (!empty($reduceProducts)) {
                    $information['reduceUpdate'] = $reduceProducts;
                    delete_option('mainwp_multistore_reducestock_products');
                }
            } else {                  
                foreach ( $data as $sku => $item_data )
                {
                    if (isset($reduceProducts[$sku])) {
                        continue; // do not update reduced products, will update next time
                    }
                    
                    $product_id = $wpdb->get_var( $wpdb->prepare( "SELECT post_id FROM $wpdb->postmeta WHERE meta_key='_sku' AND meta_value='%s' LIMIT 1", $sku ) );
                    if ($product_id) {                   
                        $this->updateRetrieveProducts($product_id, $item_data); 
                    }
                }            
            }           
            MainWPHelper::write($information);            
    }
        
    private function updateRetrieveProducts($post_id, $data) {        
        if (!is_array($data)) 
            return;
        
        $product = wc_get_product( $post_id );
        
        if (empty($product)) 
            return false;
        
        $old_regular_price = $product->regular_price;
        $old_sale_price    = $product->sale_price;   
        
        
        if ( $product->is_type('simple') || $product->is_type('external') ) {

                if ( isset( $data['_regular_price'] ) ) {
                        $new_regular_price = $data['_regular_price'] === '' ? '' : wc_format_decimal( $data['_regular_price'] );
                        update_post_meta( $post_id, '_regular_price', $new_regular_price );
                } else {
                        $new_regular_price = null;
                }
                if ( isset( $data['_sale_price'] ) ) {
                        $new_sale_price = $data['_sale_price'] === '' ? '' : wc_format_decimal( $data['_sale_price'] );
                        update_post_meta( $post_id, '_sale_price', $new_sale_price );
                } else {
                        $new_sale_price = null;
                }

                // Handle price - remove dates and set to lowest
                $price_changed = false;

                if ( ! is_null( $new_regular_price ) && $new_regular_price != $old_regular_price ) {
                        $price_changed = true;
                } elseif ( ! is_null( $new_sale_price ) && $new_sale_price != $old_sale_price ) {
                        $price_changed = true;
                }

                if ( $price_changed ) {
                        update_post_meta( $post_id, '_sale_price_dates_from', '' );
                        update_post_meta( $post_id, '_sale_price_dates_to', '' );

                        if ( ! is_null( $new_sale_price ) && $new_sale_price !== '' ) {
                                update_post_meta( $post_id, '_price', $new_sale_price );
                        } else {
                                update_post_meta( $post_id, '_price', $new_regular_price );
                        }
                }
        }
                
        // Handle stock status
        if ( isset( $data['_stock_status'] ) ) {
                wc_update_product_stock_status( $post_id, wc_clean( $data['_stock_status'] ) );                
        }

        // Handle stock
        if ( ! $product->is_type('grouped') ) {
                if ( isset( $data['_manage_stock'] ) && $data['_manage_stock']) {
                        update_post_meta( $post_id, '_manage_stock', 'yes' );
                        wc_update_product_stock( $post_id, wc_stock_amount( $data['_stock'] ) );                        
                } else {
                        update_post_meta( $post_id, '_manage_stock', 'no' );
                        wc_update_product_stock( $post_id, 0 );                                                
                }

                if ( ! empty( $data['_backorders'] ) ) {
                        update_post_meta( $post_id, '_backorders', wc_clean( $data['_backorders'] ) );                        
                }                  
        }  
        wp_update_post( array( 'ID' => $post_id, 
                                'post_modified' => current_time( 'mysql' ),
                                'post_modified_gmt' => current_time( 'mysql', 1 )                                
                            ) );
    }    
    
    
    public function action() {
        $information = array();
        if (!class_exists('WooCommerce')) {
            $information['error'] = 'NO_WOOCOMMERCE';
            MainWPHelper::write($information);
        }  
       if (isset($_POST['mwp_action'])) {
            switch ($_POST['mwp_action']) {    
                case "get_products":
                    $information = $this->get_products();                   
                    break;  
                case "quick_edit_update":
                    $information = $this->updateQuick();                   
                    break;  
            }        
        }
        MainWPHelper::write($information);
    }
    
    function get_products()
    {       
        global $wpdb;
 
        add_filter('posts_where', array(&$this, 'posts_where'));
 
        if (isset($_POST['keyword']))
        {
            $search_ids = array();
            $terms      = explode( ',', $_POST['keyword'] );
            $keyword = sanitize_text_field($_POST['keyword']);
            
            foreach ( $terms as $term ) {
                    if ( is_numeric( $term ) ) {
                            $search_ids[] = $term;
                    }
                    // Attempt to get a SKU
                    $sku_to_id = $wpdb->get_col( $wpdb->prepare( "SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key='_sku' AND meta_value LIKE '%%%s%%';",  sanitize_text_field( $term ) ) );

                    if ( $sku_to_id && sizeof( $sku_to_id ) > 0 ) {
                            $search_ids = array_merge( $search_ids, $sku_to_id );
                    }
            }
            $search_ids = array_filter( array_map( 'absint', $search_ids ) );
            
            $where = '';
            
            if ( sizeof( $search_ids ) > 0 ) {
                    $where = " OR ({$wpdb->posts}.ID IN (" . implode( ',', $search_ids ) . "))";
            }
            $this->posts_where_suffix .= " AND ($wpdb->posts.post_content LIKE '%" . $keyword . "%' OR $wpdb->posts.post_title LIKE '%" . $keyword . "%' " . $where . ")";
        }           
       
        $maxPages = MAINWP_CHILD_NR_OF_PAGES;
        if (isset($_POST['maxRecords']))
        {
            $maxPages = $_POST['maxRecords'];
        }
        if ($maxPages == 0)
        {
            $maxPages = 99999;
        }
        
        $args = array('post_status' => 'any',
                    'suppress_filters' => false,
                    'post_type' => 'product',
                    'numberposts' => $maxPages
                );
       
        $posts = get_posts($args);
        $allPosts = array();
        if (is_array($posts))
        {
            foreach ($posts as $post)
            {
                
                $the_product = wc_get_product( $post );
                $outPost = array();
                $outPost['id'] = $post->ID;              
                $outPost['title'] = $post->post_title;
                $outPost['status'] = $post->post_status;
                $outPost['dts'] = strtotime($post->post_modified_gmt);    
                
                $outPost['sku'] = $the_product->get_sku();
                $outPost['regular_price'] = $the_product->regular_price;
                $outPost['formatted_price'] = $the_product->get_price_html() ? $the_product->get_price_html() : '<span class="na">&ndash;</span>'; 
                $outPost['is_in_stock'] = $the_product->is_in_stock() ? 1 : 0;
                $outPost['manage_stock'] = $the_product->manage_stock;
                $outPost['stock_status'] = $the_product->stock_status;
                $outPost['backorders'] = $the_product->backorders;
                $outPost['sale_price'] = $the_product->sale_price;
                $outPost['stock'] = $the_product->stock;
                
//                $categoryObjects = get_the_category($post->ID);
//                $categories = "";
//                foreach ($categoryObjects as $cat)
//                {
//                    if ($categories != "") $categories .= ", ";
//                    $categories .= $cat->name;
//                }
//                $outPost['categories'] = $categories;
//                
//                $tagObjects = get_the_tags($post->ID);
//                $tags = "";
//                if (is_array($tagObjects))
//                {
//                    foreach ($tagObjects as $tag)
//                    {
//                        if ($tags != "") $tags .= ", ";
//                        $tags .= $tag->name;
//                    }
//                }
//                $outPost['tags'] = $tags;                 
                $allPosts[] = $outPost;
            }
        }
        
        return array('products' => $allPosts);
    }
    
    public function posts_where($where)
    {
        if ($this->posts_where_suffix) $where .= ' ' . $this->posts_where_suffix;
        return $where;
    }
    
    private function updateQuick() {
        $data = isset($_POST['product_data']) ? $_POST['product_data'] : null;
        $data = unserialize(base64_decode($data));
        
        if (!is_array($data)) {
            return array('error' => __("Error data."));
        }
        
        $post_id = isset($data['post_id']) ? $data['post_id'] : 0;
        $product = wc_get_product( $post_id );
        
        if (empty($product)) {
            return array('error' => __("Not found product."));
        }
        $current_stock =  $product->stock;
        // quick change stock
        if ($data['_stock'] != $data['_current_stock']) {
            if ($data['_current_stock'] !== $current_stock) {
                return array('error' => __('Error: Stock value was modified, the product is not updated.'));
            }
        }
           
        $old_regular_price = $product->regular_price;
        $old_sale_price    = $product->sale_price;
        
        
        if ( $product->is_type('simple') || $product->is_type('external') ) {
                if ( isset( $data['_regular_price'] ) ) {
                        $new_regular_price = $data['_regular_price'] === '' ? '' : wc_format_decimal( $data['_regular_price'] );
                        update_post_meta( $post_id, '_regular_price', $new_regular_price );
                } else {
                        $new_regular_price = null;
                }
                if ( isset( $data['_sale_price'] ) ) {
                        $new_sale_price = $data['_sale_price'] === '' ? '' : wc_format_decimal( $data['_sale_price'] );
                        update_post_meta( $post_id, '_sale_price', $new_sale_price );
                } else {
                        $new_sale_price = null;
                }

                // Handle price - remove dates and set to lowest
                $price_changed = false;

                if ( ! is_null( $new_regular_price ) && $new_regular_price != $old_regular_price ) {
                        $price_changed = true;
                } elseif ( ! is_null( $new_sale_price ) && $new_sale_price != $old_sale_price ) {
                        $price_changed = true;
                }

                if ( $price_changed ) {
                        update_post_meta( $post_id, '_sale_price_dates_from', '' );
                        update_post_meta( $post_id, '_sale_price_dates_to', '' );

                        if ( ! is_null( $new_sale_price ) && $new_sale_price !== '' ) {
                                update_post_meta( $post_id, '_price', $new_sale_price );
                        } else {
                                update_post_meta( $post_id, '_price', $new_regular_price );
                        }
                }
        }
                
        // Handle stock status
        if ( isset( $data['_stock_status'] ) ) {
                wc_update_product_stock_status( $post_id, wc_clean( $data['_stock_status'] ) );                
        }

        // Handle stock
        if ( ! $product->is_type('grouped') ) {
                if ( isset( $data['_manage_stock'] ) && $data['_manage_stock']) {
                        update_post_meta( $post_id, '_manage_stock', 'yes' );
                        wc_update_product_stock( $post_id, wc_stock_amount( $data['_stock'] ) );                        
                } else {
                        update_post_meta( $post_id, '_manage_stock', 'no' );
                        wc_update_product_stock( $post_id, 0 );                                                
                }

                if ( ! empty( $data['_backorders'] ) ) {
                        update_post_meta( $post_id, '_backorders', wc_clean( $data['_backorders'] ) );                        
                }                  
        }  
        wp_update_post( array( 'ID' => $post_id, 
                                'post_modified' => current_time( 'mysql' ),
                                'post_modified_gmt' => current_time( 'mysql', 1 )                                
                            ) );
        
        if ($this->saveMainStoreProductsModified($post_id)) {            
            if (!get_post_meta($post_id, '_old_product_data', true)) {
                $old_data = array(
                    'old_stock' => $current_stock
                );
                update_post_meta($post_id, '_old_product_data', $old_data); 
            }
        }
            
        return array('result' => 'success');
    }
    
    function saveMainStoreProductsModified($post_id) {        
        
        $modifiedMSProducts = get_option('mainwp_multistore_modified_mainstore_products');  
        if (!is_array($modifiedMSProducts)) {
            $modifiedMSProducts = array();
        }
        $modifiedMSProducts[$post_id] = time();                     
        MainWPHelper::update_option('mainwp_multistore_modified_mainstore_products', $modifiedMSProducts); 
        return true;
    }
    
    function saveReduceProductsToSync($data) {   
        $reduceProductsToSync = get_option('mainwp_multistore_reduce_products_to_sync');
        $reduceProductsIds = get_option('mainwp_multistore_reduce_products_ids'); 
        
        if (!is_array($reduceProductsToSync)) {
            $reduceProductsToSync = array();
        }
        if (!is_array($reduceProductsIds)) {
            $reduceProductsIds = array();
        }  
        
        foreach ($data as $post_id => $reduce_data) {
            $reduceProductsToSync[] = $reduce_data;     
            $reduceProductsIds[$post_id] = time();  
        }
        
        MainWPHelper::update_option('mainwp_multistore_reduce_products_to_sync', $reduceProductsToSync);         
        MainWPHelper::update_option('mainwp_multistore_reduce_products_ids', $reduceProductsIds);         
        return true;
    }
    
}

