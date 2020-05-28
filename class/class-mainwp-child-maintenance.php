<?php
/**
 * MainWP Child Maintenance.
 *
 * This file handles all of the Child Site maintenance functions.
 */
namespace MainWP\Child;

// phpcs:disable WordPress.WP.AlternativeFunctions --  to use external code, third party credit.

/**
 * Class MainWP_Child_Maintenance
 * @package MainWP\Child
 */
class MainWP_Child_Maintenance {

    /**
     * @static
     * @var null Holds the Public static instance of MainWP_Child_Maintenance.
     */
    protected static $instance = null;

    /**
     * Get Class Name.
     *
     * @return string
     */
	public static function get_class_name() {
		return __CLASS__;
	}

    /**
     * MainWP_Child_Maintenance constructor.
     */
    public function __construct() {
	}

    /**
     * Create a public static instance of MainWP_Child_Maintenance.
     *
     * @return MainWP_Child_Maintenance|null
     */
    public static function get_instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

    /**
     * Fire off Child Site maintenance.
     */
    public function maintenance_site() {

		if ( isset( $_POST['action'] ) ) {
			$this->maintenance_action( $_POST['action'] ); // exit.
		}

		$maint_options = $_POST['options'];
		if ( ! is_array( $maint_options ) ) {
			MainWP_Helper::write( array( 'status' => 'FAIL' ) ); // exit.
		}

		$max_revisions = isset( $_POST['revisions'] ) ? intval( $_POST['revisions'] ) : 0;
		$information   = $this->maintenance_db( $maint_options, $max_revisions );
		MainWP_Helper::write( $information );
	}

    /**
     * Child Site DB maintenance.
     *
     * @param $maint_options Maintenance options.
     * @param $max_revisions Maximum revisions to keep.
     *
     * @return string[] Return SUCCESS.
     */
    private function maintenance_db($maint_options, $max_revisions ) {
		global $wpdb;

		$performed_what = array();

		if ( in_array( 'revisions', $maint_options ) ) {
			if ( empty( $max_revisions ) ) {
				$sql_clean = "DELETE FROM $wpdb->posts WHERE post_type = 'revision'";
				$wpdb->query( $sql_clean ); // phpcs:ignore -- safe sql.
				// to fix issue of meta_value short length.
				$performed_what[] = 'revisions'; // 'Posts revisions deleted'.
			} else {
				$results = $this->maintenance_get_revisions( $max_revisions );
				$this->maintenance_delete_revisions( $results, $max_revisions );
				$performed_what[] = 'revisions_max'; // 'Posts revisions deleted'.
			}
		}

		$maint_sqls = array(
			'autodraft'    => "DELETE FROM $wpdb->posts WHERE post_status = 'auto-draft'",
			'trashpost'    => "DELETE FROM $wpdb->posts WHERE post_status = 'trash'",
			'spam'         => "DELETE FROM $wpdb->comments WHERE comment_approved = 'spam'",
			'pending'      => "DELETE FROM $wpdb->comments WHERE comment_approved = '0'",
			'trashcomment' => "DELETE FROM $wpdb->comments WHERE comment_approved = 'trash'",
		);

		foreach ( $maint_sqls as $act => $sql_clean ) {
			if ( in_array( $act, $maint_options ) ) {
				$wpdb->query( $sql_clean ); // phpcs:ignore -- safe sql.
				$performed_what[] = $act; // 'Auto draft posts deleted'.
			}
		}

		if ( in_array( 'tags', $maint_options ) ) {
			$post_tags = get_terms( 'post_tag', array( 'hide_empty' => false ) );
			if ( is_array( $post_tags ) ) {
				foreach ( $post_tags as $tag ) {
					if ( 0 === $tag->count ) {
						wp_delete_term( $tag->term_id, 'post_tag' );
					}
				}
			}
			$performed_what[] = 'tags'; // 'Tags with 0 posts associated deleted'.
		}

		if ( in_array( 'categories', $maint_options ) ) {
			$post_cats = get_terms( 'category', array( 'hide_empty' => false ) );
			if ( is_array( $post_cats ) ) {
				foreach ( $post_cats as $cat ) {
					if ( 0 === $cat->count ) {
						wp_delete_term( $cat->term_id, 'category' );
					}
				}
			}
			$performed_what[] = 'categories'; // 'Categories with 0 posts associated deleted'.
		}

		if ( in_array( 'optimize', $maint_options ) ) {
			$this->maintenance_optimize();
			$performed_what[] = 'optimize'; // 'Database optimized'.
		}

		if ( ! empty( $performed_what ) && has_action( 'mainwp_reports_maintenance' ) ) {
			$details  = implode( ',', $performed_what );
			$log_time = time();
			$message  = 'Maintenance Performed';
			$result   = 'Maintenance Performed';
			do_action( 'mainwp_reports_maintenance', $message, $log_time, $details, $result, $max_revisions );
		}
		return array( 'status' => 'SUCCESS' );
	}

    /**
     * Get Child post revisions.
     *
     * @param $max_revisions Maximum revisions to keep.
     * @return array|object|null Database query results.
     */
    protected function maintenance_get_revisions($max_revisions ) {
		global $wpdb;
		return $wpdb->get_results( $wpdb->prepare( " SELECT	`post_parent`, COUNT(*) cnt FROM $wpdb->posts WHERE `post_type` = 'revision' GROUP BY `post_parent` HAVING COUNT(*) > %d ", $max_revisions ) );
	}

    /**
     * Delete Child revisions.
     *
     * @param $results Query results.
     * @param $max_revisions Maximum revisions to keep.
     * @return int|void Return number of revisions deleted.
     */
    private function maintenance_delete_revisions($results, $max_revisions ) {
		global $wpdb;

		if ( ! is_array( $results ) || 0 === count( $results ) ) {
			return;
		}
		$count_deleted  = 0;
		$results_length = count( $results );
		for ( $i = 0; $i < $results_length; $i ++ ) {
			$number_to_delete = $results[ $i ]->cnt - $max_revisions;
			$count_deleted   += $number_to_delete;
			$results_posts    = $wpdb->get_results( $wpdb->prepare( "SELECT `ID`, `post_modified` FROM  $wpdb->posts WHERE `post_parent`= %d AND `post_type`='revision' ORDER BY `post_modified` ASC", $results[ $i ]->post_parent ) );
			$delete_ids       = array();
			if ( is_array( $results_posts ) && count( $results_posts ) > 0 ) {
				for ( $j = 0; $j < $number_to_delete; $j ++ ) {
					$delete_ids[] = $results_posts[ $j ]->ID;
				}
			}

			if ( count( $delete_ids ) > 0 ) {
				$sql_delete = " DELETE FROM $wpdb->posts WHERE `ID` IN (" . implode( ',', $delete_ids ) . ")"; // phpcs:ignore -- safe
				$wpdb->get_results( $sql_delete ); // phpcs:ignore -- safe
			}
		}

		return $count_deleted;
	}

    /**
     * Optimise Child database.
     */
    private function maintenance_optimize() {
		global $wpdb, $table_prefix;
		$sql    = 'SHOW TABLE STATUS FROM `' . DB_NAME . '`';
		$result = MainWP_Child_DB::to_query( $sql, $wpdb->dbh );
		if ( MainWP_Child_DB::num_rows( $result ) && MainWP_Child_DB::is_result( $result ) ) {
			while ( $row = MainWP_Child_DB::fetch_array( $result ) ) {
				if ( strpos( $row['Name'], $table_prefix ) !== false ) {
					$sql = 'OPTIMIZE TABLE ' . $row['Name'];
					MainWP_Child_DB::to_query( $sql, $wpdb->dbh );
				}
			}
		}
	}

    /**
     * Maintenance Action.
     *
     * @param $action Action to perform: save_settings, enable_alert, clear_settings.
     */
    private function maintenance_action( $action ) {
		$information = array();
		if ( 'save_settings' === $action ) {
			if ( isset( $_POST['enable_alert'] ) && '1' === $_POST['enable_alert'] ) {
				MainWP_Helper::update_option( 'mainwp_maintenance_opt_alert_404', 1, 'yes' );
			} else {
				delete_option( 'mainwp_maintenance_opt_alert_404' );
			}

			if ( isset( $_POST['email'] ) && ! empty( $_POST['email'] ) ) {
				MainWP_Helper::update_option( 'mainwp_maintenance_opt_alert_404_email', $_POST['email'], 'yes' );
			} else {
				delete_option( 'mainwp_maintenance_opt_alert_404_email' );
			}
			$information['result'] = 'SUCCESS';
			MainWP_Helper::write( $information );

			return;
		} elseif ( 'clear_settings' === $action ) {
			delete_option( 'mainwp_maintenance_opt_alert_404' );
			delete_option( 'mainwp_maintenance_opt_alert_404_email' );
			$information['result'] = 'SUCCESS';
			MainWP_Helper::write( $information );
		}

		MainWP_Helper::write( $information );
	}
}