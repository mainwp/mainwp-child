<?php
/**
 * MainWP Child Maintenance.
 *
 * MainWP Maintenance extension handler.
 * Extension URL: https://mainwp.com/extension/maintenance/
 *
 * @package MainWP\Child
 */

namespace MainWP\Child;

// phpcs:disable WordPress.WP.AlternativeFunctions -- Required to achieve desired results, pull request solutions appreciated.

/**
 * Class MainWP_Child_Maintenance
 *
 * MainWP Maintenance extension handler.
 */
class MainWP_Child_Maintenance {

	/**
	 * Public static variable to hold the single instance of the class.
	 *
	 * @var mixed Default null
	 */
	protected static $instance = null;

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
	 * MainWP_Child_Maintenance constructor.
	 *
	 * Run any time class is called.
	 */
	public function __construct() {
	}

	/**
	 * Method get_instance()
	 *
	 * Create a public static instance.
	 *
	 * @return mixed Class instance.
	 */
	public static function get_instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Method maintenance_site()
	 *
	 * Fire off Child Site maintenance action and get feedback.
	 *
	 * @uses \MainWP\Child\MainWP_Child_Maintenance::maintenance_action() Triggers action to perform, save_settings, enable_alert or clear_settings.
	 * @uses \MainWP\Child\MainWP_Child_Maintenance::maintenance_db() Child site database maintenance.
	 * @uses \MainWP\Child\MainWP_Helper::write() Write response data to be sent to the MainWP Dashboard.
	 */
	public function maintenance_site() {

		if ( isset( $_POST['action'] ) ) {
			$action = ! empty( $_POST['action'] ) ? sanitize_text_field( wp_unslash( $_POST['action'] ) ) : '';
			$this->maintenance_action( $action ); // exit.
		}

		$maint_options = isset( $_POST['options'] ) ? wp_unslash( $_POST['options'] ) : false;

		if ( ! is_array( $maint_options ) ) {
			MainWP_Helper::write( array( 'status' => 'FAIL' ) ); // exit.
		}

		$max_revisions = isset( $_POST['revisions'] ) ? intval( wp_unslash( $_POST['revisions'] ) ) : 0;

		$information = $this->maintenance_db( $maint_options, $max_revisions );

		MainWP_Helper::write( $information );
	}

	/**
	 * Method maintenance_db()
	 *
	 * Child site database maintenance.
	 *
	 * @param array $maint_options An array containing selected maintenance options.
	 * @param int   $max_revisions Maximum revisions to keep.
	 *
	 * @uses MainWP_Child_Maintenance::maintenance_get_revisions() Get child sites post revisions.
	 * @uses MainWP_Child_Maintenance::maintenance_delete_revisions()
	 * @uses MainWP_Child_Maintenance::maintenance_optimize()
	 *
	 * @uses get_terms() Retrieve the terms in a given taxonomy or list of taxonomies.
	 * @see https://developer.wordpress.org/reference/functions/get_terms/
	 *
	 * @uses wp_delete_term() Removes a term from the database.
	 * @see https://developer.wordpress.org/reference/functions/wp_delete_term/
	 *
	 * @used-by MainWP_Child_Maintenance::maintenance_site() Fire off Child Site maintenance action and get feedback.
	 *
	 * @return array An array containing action feedback.
	 */
	private function maintenance_db( $maint_options, $max_revisions ) {

		/**
		 * WordPress Database instance.
		 *
		 * @global object $wpdb
		 */
		global $wpdb;

		$performed_what = array();

		if ( in_array( 'revisions', $maint_options ) ) {
			if ( empty( $max_revisions ) ) {
				$sql_clean = "DELETE FROM $wpdb->posts WHERE post_type = 'revision'";
				$wpdb->query( $sql_clean ); // phpcs:ignore -- safe sql query. required to achieve desired results, pull request solutions appreciated.
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
				$wpdb->query( $sql_clean ); // phpcs:ignore -- safe sql query. required to achieve desired results, pull request solutions appreciated.
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
	 * Method maintenance_get_revisions()
	 *
	 * Get child sites post revisions.
	 *
	 * @param int $max_revisions Maximum revisions to keep.
	 *
	 * @uses wpdb::get_results() Retrieve an entire SQL result set from the database.
	 * @see https://developer.wordpress.org/reference/classes/wpdb/get_results/
	 *
	 * @used-by MainWP_Child_Maintenance::maintenance_db() Child site database maintenance.
	 *
	 * @return array|object|null Database query results.
	 */
	protected function maintenance_get_revisions( $max_revisions ) {

		/**
		 * WordPress Database instance.
		 *
		 * @global object $wpdb
		 */
		global $wpdb;

		return $wpdb->get_results( $wpdb->prepare( " SELECT	`post_parent`, COUNT(*) cnt FROM $wpdb->posts WHERE `post_type` = 'revision' GROUP BY `post_parent` HAVING COUNT(*) > %d ", $max_revisions ) );
	}

	/**
	 * Method maintenance_delete_revisions()
	 *
	 * Delete child site post revisions.
	 *
	 * @param array|object $results       Database query results.
	 * @param int          $max_revisions Maximum revisions to keep.
	 *
	 * @uses wpdb::get_results() Retrieve an entire SQL result set from the database.
	 * @see https://developer.wordpress.org/reference/classes/wpdb/get_results/
	 *
	 * @used-by MainWP_Child_Maintenance::maintenance_db() Child site database maintenance.
	 *
	 * @return int Return number of revisions deleted.
	 */
	private function maintenance_delete_revisions( $results, $max_revisions ) {

		/**
		 * WordPress Database instance.
		 *
		 * @global object $wpdb
		 */
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
	 * Method maintenance_optimize()
	 *
	 * Optimize Child database.
	 *
	 * @uses MainWP_Child_DB::to_query() Get the size of the DB.
	 * @uses MainWP_Child_DB::num_rows() Count the number of rows.
	 * @uses MainWP_Child_DB::is_result() Check if $result is an Instantiated object of \mysqli.
	 * @uses MainWP_Child_DB::fetch_array() Fetch an array.
	 * @uses \MainWP\Child\MainWP_Child_DB::to_query()
	 * @uses \MainWP\Child\MainWP_Child_DB::num_rows()
	 * @uses \MainWP\Child\MainWP_Child_DB::fetch_array()
	 *
	 * @used-by MainWP_Child_Maintenance::maintenance_db() Child site database maintenance.
	 */
	private function maintenance_optimize() {

		/**
		 * Object, providing access to the WordPress database.
		 *
		 * @global object $wpdb WordPress Database instance.
		 */
		global $wpdb;

		/**
		 * WordPress DB table prefix.
		 *
		 * @global string $table_prefix WordPress DB table prefix.
		 */
		global $table_prefix;

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
	 * Method maintenance_action()
	 *
	 * Triggers action to perform, save_settings, enable_alert or clear_settings.
	 *
	 * @param string $action Action to perform.
	 *
	 * @uses delete_option() Removes option by name. Prevents removal of protected WordPress options.
	 * @see https://developer.wordpress.org/reference/functions/delete_option/
	 *
	 * @uses \MainWP\Child\MainWP_Helper::write() Write response data to be sent to the MainWP Dashboard.
	 * @uses \MainWP\Child\MainWP_Helper::update_option() Update option by name.
	 *
	 * @used-by \MainWP\Child\MainWP_Child_Maintenance::maintenance_site() Fire off Child Site maintenance action and get feedback.
	 */
	private function maintenance_action( $action ) {
		$information = array();
		if ( 'save_settings' === $action ) {
			if ( isset( $_POST['enable_alert'] ) && '1' === $_POST['enable_alert'] ) {
				MainWP_Helper::update_option( 'mainwp_maintenance_opt_alert_404', 1, 'yes' );
			} else {
				delete_option( 'mainwp_maintenance_opt_alert_404' );
			}
			$email = ! empty( $_POST['email'] ) ? wp_unslash( $_POST['email'] ) : '';
			if ( ! empty( $email ) ) {
				MainWP_Helper::update_option( 'mainwp_maintenance_opt_alert_404_email', $email, 'yes' );
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
