<?php
/**
 * MainWP Child Woocomerce Status
 *
 * MainWP WooCommerce Status Extension handler.
 *
 * @link https://mainwp.com/extension/woocommerce-status/
 *
 * @package MainWP\Child
 *
 * Credits
 *
 * Plugin-Name: WooCommerce
 * Plugin URI: https://woocommerce.com/
 * Author: Automattic
 * Author URI: https://woocommerce.com
 */

namespace MainWP\Child;

// phpcs:disable PSR1.Classes.ClassDeclaration, WordPress.WP.AlternativeFunctions -- Required to achieve desired results, pull request solutions appreciated.

/**
 * Class MainWP_Child_WooCommerce_Status
 *
 * MainWP WooCommerce Status Extension handler.
 */
class MainWP_Child_WooCommerce_Status {

	/**
	 * Public static variable to hold the single instance of the class.
	 *
	 * @var mixed Default null
	 */
	public static $instance = null;

	/**
	 * Method instance()
	 *
	 * Create a public static instance.
	 *
	 * @return mixed Class instance.
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	/**
	 * MainWP_Child_WooCommerce_Status constructor.
	 *
	 * Run any time class is called.
	 */
	public function __construct() {
		add_action( 'mainwp_child_deactivation', array( $this, 'child_deactivation' ) );
	}

	/**
	 * MainWP Child Plugin deactivation hooks.
	 */
	public function child_deactivation() {
	}

	/**
	 * MainWP Child Woocommerce actions: sync_data, report_data, update_wc_db.
	 *
	 * @uses \MainWP\Child\MainWP_Helper::write()
	 */
	public function action() {
		$information = array();
		if ( ! class_exists( '\WooCommerce' ) || ! defined( 'WC_VERSION' ) ) {
			$information['error'] = 'NO_WOOCOMMERCE';
			MainWP_Helper::write( $information );
		}

		$is_ver220 = $this->is_version_220();
		if ( isset( $_POST['mwp_action'] ) ) {
			$mwp_action = ! empty( $_POST['mwp_action'] ) ? sanitize_text_field( wp_unslash( $_POST['mwp_action'] ) ) : '';
			switch ( $mwp_action ) {
				case 'sync_data':
					$information = ! $is_ver220 ? $this->sync_data() : $this->sync_data_two();
					break;
				case 'report_data':
					$information = ! $is_ver220 ? $this->report_data() : $this->report_data_two();
					break;
				case 'update_wc_db':
					$information = $this->update_wc_db();
					break;
			}
		}
		MainWP_Helper::write( $information );
	}

	/**
	 * Compare woocommerce versions.
	 *
	 * By default, version_compare returns -1 if the first version is lower than the second,
	 *  0 if they are equal, and 1 if the second is lower.
	 *  When using the optional operator argument, the function will return true if the relationship is
	 *  the one specified by the operator, false otherwise.
	 *
	 * @return bool|int Comparison response.
	 */
	public function is_version_220() {
		return version_compare( WC()->version, '2.2.0', '>=' );
	}

	/**
	 * Sync Woocommerce data.
	 *
	 * @return array $information Woocommerce data grabed.
	 */
	public function sync_data() {

		/**
		 * Object, providing access to the WordPress database.
		 *
		 * @global object $wpdb WordPress Database instance.
		 */
		global $wpdb;

		$file = WP_PLUGIN_DIR . '/woocommerce/includes/admin/reports/class-wc-admin-report.php';
		if ( file_exists( $file ) ) {
			include_once $file;
		} else {
			return false;
		}

		// Get sales.
		$sales = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT SUM( postmeta.meta_value ) FROM {$wpdb->posts} as posts
				LEFT JOIN {$wpdb->term_relationships} AS rel ON posts.ID=rel.object_ID
				LEFT JOIN {$wpdb->term_taxonomy} AS tax USING( term_taxonomy_id )
				LEFT JOIN {$wpdb->terms} AS term USING( term_id )
				LEFT JOIN {$wpdb->postmeta} AS postmeta ON posts.ID = postmeta.post_id
				WHERE posts.post_type = 'shop_order'
				AND posts.post_status = 'publish'
				AND tax.taxonomy = 'shop_order_status'
				AND term.slug IN ( '" . implode( "','", apply_filters( 'woocommerce_reports_order_statuses', array( 'completed', 'processing', 'on-hold' ) ) ) . "' ) " . // phpcs:ignore -- safe query.
				" AND postmeta.meta_key = '_order_total'
				AND posts.post_date >= %s
				AND posts.post_date <= %s",
				date( 'Y-m-01' ), // phpcs:ignore -- local time.
				date( 'Y-m-d H:i:s' ) // phpcs:ignore -- local time.
			)
		);

		// Get top seller.
		$top_seller = $wpdb->get_row(
			$wpdb->prepare( // phpcs:ignore -- safe query.
				"SELECT SUM( order_item_meta.meta_value ) as qty, order_item_meta_2.meta_value as product_id
				FROM {$wpdb->posts} as posts
				LEFT JOIN {$wpdb->term_relationships} AS rel ON posts.ID=rel.object_ID
				LEFT JOIN {$wpdb->term_taxonomy} AS tax USING( term_taxonomy_id )
				LEFT JOIN {$wpdb->terms} AS term USING( term_id )
				LEFT JOIN {$wpdb->prefix}woocommerce_order_items AS order_items ON posts.ID = order_id
				LEFT JOIN {$wpdb->prefix}woocommerce_order_itemmeta AS order_item_meta ON order_items.order_item_id = order_item_meta.order_item_id
				LEFT JOIN {$wpdb->prefix}woocommerce_order_itemmeta AS order_item_meta_2 ON order_items.order_item_id = order_item_meta_2.order_item_id
				WHERE posts.post_type = 'shop_order'
				AND posts.post_status = 'publish'
				AND tax.taxonomy = 'shop_order_status'
				AND term.slug IN ( '" . implode( "','", apply_filters( 'woocommerce_reports_order_statuses', array( 'completed', 'processing', 'on-hold' ) ) ) . "' ) " . // phpcs:ignore -- safe query.
				" AND order_item_meta.meta_key = '_qty'
				AND order_item_meta_2.meta_key = '_product_id'
				AND posts.post_date >= %s
				AND posts.post_date <= %s
				GROUP BY product_id
				ORDER BY qty DESC
				LIMIT 1",
				date( 'Y-m-01' ), // phpcs:ignore -- local time.
				date( 'Y-m-d H:i:s' ) // phpcs:ignore -- local time.
			)
		);

		if ( ! empty( $top_seller ) ) {
			$top_seller->name = get_the_title( $top_seller->product_id );
		}

		// Counts.
		$on_hold_count    = get_term_by( 'slug', 'on-hold', 'shop_order_status' )->count;
		$processing_count = get_term_by( 'slug', 'processing', 'shop_order_status' )->count;

		// Get products using a query.
		$stock   = absint( max( get_option( 'woocommerce_notify_low_stock_amount' ), 1 ) );
		$nostock = absint( max( get_option( 'woocommerce_notify_no_stock_amount' ), 0 ) );

		$query_from = "FROM {$wpdb->posts} as posts INNER JOIN {$wpdb->postmeta} AS postmeta ON posts.ID = postmeta.post_id INNER JOIN {$wpdb->postmeta} AS postmeta2 ON posts.ID = postmeta2.post_id WHERE 1=1 AND posts.post_type IN ('product', 'product_variation') AND posts.post_status = 'publish' AND ( postmeta.meta_key = '_stock' AND CAST(postmeta.meta_value AS SIGNED) <= '{$stock}' AND CAST(postmeta.meta_value AS SIGNED) > '{$nostock}' AND postmeta.meta_value != '' ) AND ( ( postmeta2.meta_key = '_manage_stock' AND postmeta2.meta_value = 'yes' ) OR ( posts.post_type = 'product_variation' ) )";

		$lowinstock_count = absint( $wpdb->get_var( "SELECT COUNT( DISTINCT posts.ID ) {$query_from};" ) ); //phpcs:ignore -- safe query.

		$query_from = "FROM {$wpdb->posts} as posts INNER JOIN {$wpdb->postmeta} AS postmeta ON posts.ID = postmeta.post_id INNER JOIN {$wpdb->postmeta} AS postmeta2 ON posts.ID = postmeta2.post_id WHERE 1=1 AND posts.post_type IN ('product', 'product_variation') AND posts.post_status = 'publish' AND ( postmeta.meta_key = '_stock' AND CAST(postmeta.meta_value AS SIGNED) <= '{$nostock}' AND postmeta.meta_value != '' ) AND ( ( postmeta2.meta_key = '_manage_stock' AND postmeta2.meta_value = 'yes' ) OR ( posts.post_type = 'product_variation' ) )";

		$outofstock_count = absint( $wpdb->get_var( "SELECT COUNT( DISTINCT posts.ID ) {$query_from};" ) );  //phpcs:ignore -- safe query.

		$data = array(
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

		$data = apply_filters( 'mainwp_child_woocom_sync_data', $data );

		$information['data'] = $data;

		return $information;
	}

	/**
	 * Woocommerce report data.
	 *
	 * @return array $information Woocommerce data grabed.
	 */
	public function report_data() {

		/**
		 * Object, providing access to the WordPress database.
		 *
		 * @global object $wpdb WordPress Database instance.
		 */
		global $wpdb;

		$file = WP_PLUGIN_DIR . '/woocommerce/includes/admin/reports/class-wc-admin-report.php';
		if ( file_exists( $file ) ) {
			include_once $file;
		} else {
			return false;
		}

		$start_date = isset( $_POST['start_date'] ) ? sanitize_text_field( wp_unslash( $_POST['start_date'] ) ) : '';
		$end_date   = isset( $_POST['end_date'] ) ? sanitize_text_field( wp_unslash( $_POST['end_date'] ) ) : '';

		$start_date = date( 'Y-m-d H:i:s', $start_date ); // phpcs:ignore -- local time.
		$end_date   = date( 'Y-m-d H:i:s', $end_date ); // phpcs:ignore -- local time.

		// Get sales.
		$sales = $wpdb->get_var(
			"SELECT SUM( postmeta.meta_value ) FROM {$wpdb->posts} as posts
			LEFT JOIN {$wpdb->term_relationships} AS rel ON posts.ID=rel.object_ID
			LEFT JOIN {$wpdb->term_taxonomy} AS tax USING( term_taxonomy_id )
			LEFT JOIN {$wpdb->terms} AS term USING( term_id )
			LEFT JOIN {$wpdb->postmeta} AS postmeta ON posts.ID = postmeta.post_id
			WHERE posts.post_type = 'shop_order'
			AND posts.post_status = 'publish'
			AND tax.taxonomy = 'shop_order_status'
			AND term.slug IN ( '" . implode( "','", apply_filters( 'woocommerce_reports_order_statuses', array( 'completed', 'processing', 'on-hold' ) ) ) . "' ) " . // phpcs:ignore -- safe query.
			" AND postmeta.meta_key = '_order_total'
			AND posts.post_date >= STR_TO_DATE(" . $wpdb->prepare( '%s', $start_date ) . ", '%Y-%m-%d %H:%i:%s')
			AND posts.post_date <= STR_TO_DATE(" . $wpdb->prepare( '%s', $end_date ) . ", '%Y-%m-%d %H:%i:%s')"
		);

		// Get top seller.
		$top_seller = $wpdb->get_row(
			"SELECT SUM( order_item_meta.meta_value ) as qty, order_item_meta_2.meta_value as product_id
			FROM {$wpdb->posts} as posts
			LEFT JOIN {$wpdb->term_relationships} AS rel ON posts.ID=rel.object_ID
			LEFT JOIN {$wpdb->term_taxonomy} AS tax USING( term_taxonomy_id )
			LEFT JOIN {$wpdb->terms} AS term USING( term_id )
			LEFT JOIN {$wpdb->prefix}woocommerce_order_items AS order_items ON posts.ID = order_id
			LEFT JOIN {$wpdb->prefix}woocommerce_order_itemmeta AS order_item_meta ON order_items.order_item_id = order_item_meta.order_item_id
			LEFT JOIN {$wpdb->prefix}woocommerce_order_itemmeta AS order_item_meta_2 ON order_items.order_item_id = order_item_meta_2.order_item_id
			WHERE posts.post_type = 'shop_order'
			AND posts.post_status = 'publish'
			AND tax.taxonomy = 'shop_order_status'
			AND term.slug IN ( '" . implode( "','", apply_filters( 'woocommerce_reports_order_statuses', array( 'completed', 'processing', 'on-hold' ) ) ) . "' ) " . // phpcs:ignore -- safe query.
			" AND order_item_meta.meta_key = '_qty'
			AND order_item_meta_2.meta_key = '_product_id'
			AND posts.post_date >= STR_TO_DATE(" . $wpdb->prepare( '%s', $start_date ) . ", '%Y-%m-%d %H:%i:%s' )
			AND posts.post_date <= STR_TO_DATE(" . $wpdb->prepare( '%s', $end_date ) . ", '%Y-%m-%d %H:%i:%s' )
			GROUP BY product_id
			ORDER BY qty DESC
			LIMIT 1"
		);

		if ( ! empty( $top_seller ) ) {
			$top_seller->name = get_the_title( $top_seller->product_id );
		}

		// Counts.
		$on_hold_count    = get_term_by( 'slug', 'on-hold', 'shop_order_status' )->count;
		$processing_count = get_term_by( 'slug', 'processing', 'shop_order_status' )->count;

		// Get products using a query.
		$stock   = absint( max( get_option( 'woocommerce_notify_low_stock_amount' ), 1 ) );
		$nostock = absint( max( get_option( 'woocommerce_notify_no_stock_amount' ), 0 ) );

		$query_from = "FROM {$wpdb->posts} as posts INNER JOIN {$wpdb->postmeta} AS postmeta ON posts.ID = postmeta.post_id INNER JOIN {$wpdb->postmeta} AS postmeta2 ON posts.ID = postmeta2.post_id WHERE 1=1 AND posts.post_type IN ('product', 'product_variation') AND posts.post_status = 'publish' AND ( postmeta.meta_key = '_stock' AND CAST(postmeta.meta_value AS SIGNED) <= '{$stock}' AND CAST(postmeta.meta_value AS SIGNED) > '{$nostock}' AND postmeta.meta_value != '' ) AND ( ( postmeta2.meta_key = '_manage_stock' AND postmeta2.meta_value = 'yes' ) OR ( posts.post_type = 'product_variation' ) )";

		$lowinstock_count = absint( $wpdb->get_var( "SELECT COUNT( DISTINCT posts.ID ) {$query_from};" ) ); //phpcs:ignore -- safe query.

		$query_from = "FROM {$wpdb->posts} as posts INNER JOIN {$wpdb->postmeta} AS postmeta ON posts.ID = postmeta.post_id INNER JOIN {$wpdb->postmeta} AS postmeta2 ON posts.ID = postmeta2.post_id WHERE 1=1 AND posts.post_type IN ('product', 'product_variation') AND posts.post_status = 'publish' AND ( postmeta.meta_key = '_stock' AND CAST(postmeta.meta_value AS SIGNED) <= '{$nostock}' AND postmeta.meta_value != '' ) AND ( ( postmeta2.meta_key = '_manage_stock' AND postmeta2.meta_value = 'yes' ) OR ( posts.post_type = 'product_variation' ) )";

		$outofstock_count = absint( $wpdb->get_var( "SELECT COUNT( DISTINCT posts.ID ) {$query_from};" ) ); //phpcs:ignore -- safe query.

		$data = array(
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

		$data = apply_filters( 'mainwp_child_woocom_report_data', $data );

		$information['data'] = $data;

		return $information;
	}

	/**
	 * Sync Woocommerce data for current month.
	 */
	public function sync_data_two() {
		$start_date = date( 'Y-m-01 00:00:00', time() ); // phpcs:ignore -- local time.
		$end_date   = date( 'Y-m-d H:i:s', time() ); // phpcs:ignore -- local time.

		$start_date = strtotime( $start_date );
		$end_date   = strtotime( $end_date );

		return $this->get_woocom_data( $start_date, $end_date );
	}

	/**
	 * Sync Woocomerce data for specific date range.
	 */
	public function report_data_two() {
		$start_date = isset( $_POST['start_date'] ) ? sanitize_text_field( wp_unslash( $_POST['start_date'] ) ) : '';
		$end_date   = isset( $_POST['end_date'] ) ? sanitize_text_field( wp_unslash( $_POST['end_date'] ) ) : '';

		return $this->get_woocom_data( $start_date, $end_date );
	}

	/**
	 * Check if woocomerce DB needs to be updated.
	 *
	 * @return bool true|false.
	 */
	public function check_db_update() {
		if ( version_compare( get_option( 'woocommerce_db_version' ), WC_VERSION, '<' ) ) {
			return true;
		}
		return false;
	}

	/**
	 * Get Woocommerce data.
	 *
	 * @param string $start_date Start Date.
	 * @param string $end_date End Date.
	 *
	 * @return array $information Woocommerce data grabed.
	 */
	public function get_woocom_data( $start_date, $end_date ) {

		/**
		 * Object, providing access to the WordPress database.
		 *
		 * @global object $wpdb WordPress Database instance.
		 */
		global $wpdb;

		$file = WP_PLUGIN_DIR . '/woocommerce/includes/admin/reports/class-wc-admin-report.php';
		if ( file_exists( $file ) ) {
			include_once $file;
		} else {
			return false;
		}

		$start_date = date( 'Y-m-d H:i:s', $start_date ); // phpcs:ignore -- local time. Required to achieve desired results, pull request solutions appreciated.
		$end_date   = date( 'Y-m-d H:i:s', $end_date ); // phpcs:ignore -- local time. Required to achieve desired results, pull request solutions appreciated.

		// Sales.
		$query           = array();
		$query['fields'] = "SELECT SUM( postmeta.meta_value ) FROM {$wpdb->posts} as posts";
		$query['join']   = "INNER JOIN {$wpdb->postmeta} AS postmeta ON posts.ID = postmeta.post_id ";
		$query['where']  = "WHERE posts.post_type IN ( '" . implode( "','", wc_get_order_types( 'reports' ) ) . "' ) ";
		$query['where'] .= "AND posts.post_status IN ( 'wc-" . implode( "','wc-", apply_filters( 'woocommerce_reports_order_statuses', array( 'completed', 'processing', 'on-hold' ) ) ) . "' ) ";
		$query['where'] .= "AND postmeta.meta_key = '_order_total' ";
		$query['where'] .= 'AND posts.post_date >=  STR_TO_DATE(' . $wpdb->prepare( '%s', $start_date ) . ", '%Y-%m-%d %H:%i:%s' ) ";
		$query['where'] .= 'AND posts.post_date <=  STR_TO_DATE(' . $wpdb->prepare( '%s', $end_date ) . ", '%Y-%m-%d %H:%i:%s' ) ";

		$sales = $wpdb->get_var( implode( ' ', apply_filters( 'woocommerce_dashboard_status_widget_sales_query', $query ) ) ); // phpcs:ignore -- safe query.

		// Get top seller.
		$query            = array();
		$query['fields']  = "SELECT SUM( order_item_meta.meta_value ) as qty, order_item_meta_2.meta_value as product_id FROM {$wpdb->posts} as posts";
		$query['join']    = "INNER JOIN {$wpdb->prefix}woocommerce_order_items AS order_items ON posts.ID = order_id ";
		$query['join']   .= "INNER JOIN {$wpdb->prefix}woocommerce_order_itemmeta AS order_item_meta ON order_items.order_item_id = order_item_meta.order_item_id ";
		$query['join']   .= "INNER JOIN {$wpdb->prefix}woocommerce_order_itemmeta AS order_item_meta_2 ON order_items.order_item_id = order_item_meta_2.order_item_id ";
		$query['where']   = "WHERE posts.post_type IN ( '" . implode( "','", wc_get_order_types( 'order-count' ) ) . "' ) ";
		$query['where']  .= "AND posts.post_status IN ( 'wc-" . implode( "','wc-", apply_filters( 'woocommerce_reports_order_statuses', array( 'completed', 'processing', 'on-hold' ) ) ) . "' ) ";
		$query['where']  .= "AND order_item_meta.meta_key = '_qty' ";
		$query['where']  .= "AND order_item_meta_2.meta_key = '_product_id' ";
		$query['where']  .= 'AND posts.post_date >= STR_TO_DATE(' . $wpdb->prepare( '%s', $start_date ) . ", '%Y-%m-%d %H:%i:%s') ";
		$query['where']  .= 'AND posts.post_date <= STR_TO_DATE(' . $wpdb->prepare( '%s', $end_date ) . ", '%Y-%m-%d %H:%i:%s')  ";
		$query['groupby'] = 'GROUP BY product_id';
		$query['orderby'] = 'ORDER BY qty DESC';
		$query['limits']  = 'LIMIT 1';

		$top_seller = $wpdb->get_row( implode( ' ', $query ) ); // phpcs:ignore -- safe query.

		if ( ! empty( $top_seller ) ) {
			$top_seller->name = get_the_title( $top_seller->product_id );
		}

		// Counts.
		$on_hold_count    = 0;
		$processing_count = 0;

		foreach ( wc_get_order_types( 'order-count' ) as $type ) {
			$counts            = (array) wp_count_posts( $type );
			$on_hold_count    += isset( $counts['wc-on-hold'] ) ? $counts['wc-on-hold'] : 0;
			$processing_count += isset( $counts['wc-processing'] ) ? $counts['wc-processing'] : 0;
		}

		// Get products using a query.
		$stock   = absint( max( get_option( 'woocommerce_notify_low_stock_amount' ), 1 ) );
		$nostock = absint( max( get_option( 'woocommerce_notify_no_stock_amount' ), 0 ) );

		$query_from = "FROM {$wpdb->posts} as posts INNER JOIN {$wpdb->postmeta} AS postmeta ON posts.ID = postmeta.post_id INNER JOIN {$wpdb->postmeta} AS postmeta2 ON posts.ID = postmeta2.post_id WHERE 1=1 AND posts.post_type IN ('product', 'product_variation') AND posts.post_status = 'publish' AND ( postmeta.meta_key = '_stock' AND CAST(postmeta.meta_value AS SIGNED) <= '{$stock}' AND CAST(postmeta.meta_value AS SIGNED) > '{$nostock}' AND postmeta.meta_value != '' ) AND ( ( postmeta2.meta_key = '_manage_stock' AND postmeta2.meta_value = 'yes' ) OR ( posts.post_type = 'product_variation' ) )";

		$lowinstock_count = absint( $wpdb->get_var( "SELECT COUNT( DISTINCT posts.ID ) {$query_from};" ) ); //phpcs:ignore -- safe query.

		$query_from = "FROM {$wpdb->posts} as posts INNER JOIN {$wpdb->postmeta} AS postmeta ON posts.ID = postmeta.post_id INNER JOIN {$wpdb->postmeta} AS postmeta2 ON posts.ID = postmeta2.post_id WHERE 1=1 AND posts.post_type IN ('product', 'product_variation') AND posts.post_status = 'publish' AND ( postmeta.meta_key = '_stock' AND CAST(postmeta.meta_value AS SIGNED) <= '{$nostock}' AND postmeta.meta_value != '' ) AND ( ( postmeta2.meta_key = '_manage_stock' AND postmeta2.meta_value = 'yes' ) OR ( posts.post_type = 'product_variation' ) ) ";

		$outofstock_count = absint( $wpdb->get_var( "SELECT COUNT( DISTINCT posts.ID ) {$query_from};" ) ); //phpcs:ignore -- safe query.

		$data = array(
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

		$data = apply_filters( 'mainwp_child_woocom_get_data', $data );

		$information['data']           = $data;
		$information['need_db_update'] = $this->check_db_update();
		return $information;
	}

	/**
	 * Update Woocommerce Database.
	 *
	 * @return string[] Success.
	 */
	private static function update_wc_db() {
		include_once WC()->plugin_path() . '/includes/class-wc-background-updater.php';
		$background_updater = new \WC_Background_Updater();

		$current_db_version = get_option( 'woocommerce_db_version' );
		$logger             = wc_get_logger();
		$update_queued      = false;

		foreach ( \WC_Install::get_db_update_callbacks() as $version => $update_callbacks ) {
			if ( version_compare( $current_db_version, $version, '<' ) ) {
				foreach ( $update_callbacks as $update_callback ) {
					$logger->info(
						sprintf( 'Queuing %s - %s', $version, $update_callback ),
						array( 'source' => 'wc_db_updates' )
					);
					$background_updater->push_to_queue( $update_callback );
					$update_queued = true;
				}
			}
		}

		if ( $update_queued ) {
			$background_updater->save()->dispatch();
		}

		return array( 'result' => 'success' );
	}

}
