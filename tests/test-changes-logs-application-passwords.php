<?php
/**
 * Regression coverage for application password logging on user meta updates.
 *
 * @package Mainwp_Child
 */

namespace MainWP\Child\Changes {

	if ( ! function_exists( __NAMESPACE__ . '\filter_input_array' ) ) {
		/**
		 * Supplies request server fields, which CLI filter_input_array() never reads from $_SERVER.
		 *
		 * @param int   $type      Input type.
		 * @param mixed $options   Filter options.
		 * @param bool  $add_empty Whether to add missing keys.
		 * @return mixed Filtered input.
		 */
		function filter_input_array( $type, $options = FILTER_DEFAULT, $add_empty = true ) {
			if ( INPUT_SERVER === $type && isset( $GLOBALS['mainwp_test_changes_server'] ) ) {
				return $GLOBALS['mainwp_test_changes_server'];
			}
			return \filter_input_array( $type, $options, $add_empty );
		}
	}
}

namespace {

	use MainWP\Child\Changes\Changes_Handle_WP_User_Profile;
	use MainWP\Child\Changes\Changes_Helper;
	use MainWP\Child\Changes\Changes_Logs_DB_Log;
	use MainWP\Child\Changes\Changes_Logs_Logger;

	/**
	 * Avoids the absent DB version cached before the test installation.
	 */
	class MainWP_Changes_App_Passwords_Test_DB extends Changes_Logs_DB_Log {

		/**
		 * Reads the version without the production request cache.
		 *
		 * @return string|false Installed version.
		 */
		public function get_db_version() {
			return get_option( Changes_Helper::CHANGES_LOGS_DB_OPTION_NAME );
		}
	}

	/**
	 * Drives application password requests through the real update_user_meta hook and log tables.
	 */
	class Test_Changes_Logs_Application_Passwords extends WP_UnitTestCase {

		/**
		 * Original DB singleton.
		 *
		 * @var object
		 */
		private $original_db;

		/**
		 * Original request fields.
		 *
		 * @var array
		 */
		private $original_post;

		/**
		 * User whose application passwords are changed.
		 *
		 * @var int
		 */
		private $user_id;

		/**
		 * Prepares real storage, a user with two application passwords and a profile-screen REST request.
		 */
		public function set_up() {
			parent::set_up();
			$this->original_post = $_POST;
			$_POST               = array();
			$instance            = new ReflectionProperty( Changes_Logs_DB_Log::class, 'instance' );
			$instance->setAccessible( true );
			$this->original_db = $instance->getValue();
			$instance->setValue( null, new MainWP_Changes_App_Passwords_Test_DB() );
			Changes_Logs_DB_Log::instance()->install();
			Changes_Handle_WP_User_Profile::init_hooks();

			$this->user_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
			wp_set_current_user( $this->user_id );
			WP_Application_Passwords::create_new_application_password( $this->user_id, array( 'name' => 'First key' ) );
			WP_Application_Passwords::create_new_application_password( $this->user_id, array( 'name' => 'Second key' ) );

			$GLOBALS['mainwp_test_changes_server'] = array(
				'HTTP_REFERER' => admin_url( 'profile.php' ),
				'REQUEST_URI'  => '/wp-json/wp/v2/users/' . $this->user_id . '/application-passwords',
			);
			$this->clear_logs();
		}

		/**
		 * Restores the DB singleton and request fields.
		 */
		public function tear_down() {
			unset( $GLOBALS['mainwp_test_changes_server'] );
			$this->reset_logger();
			$instance = new ReflectionProperty( Changes_Logs_DB_Log::class, 'instance' );
			$instance->setAccessible( true );
			$instance->setValue( null, $this->original_db );
			$_POST = $this->original_post;
			wp_set_current_user( 0 );
			parent::tear_down();
		}

		/**
		 * Clears delayed events between simulated requests.
		 */
		private function reset_logger() {
			foreach ( array( 'delay_logs_items', 'logs_type_queue', 'logs_type_handled', 'cached_log_checks', 'wp_users' ) as $name ) {
				$property = new ReflectionProperty( Changes_Logs_Logger::class, $name );
				$property->setAccessible( true );
				$property->setValue( null, array() );
			}
		}

		/**
		 * Removes fixture setup events from real storage.
		 */
		private function clear_logs() {
			global $wpdb;
			$this->reset_logger();
			$wpdb->query( $wpdb->prepare( 'DELETE FROM %i', Changes_Logs_DB_Log::table_name( 'changes_meta' ) ) );
			$wpdb->query( $wpdb->prepare( 'DELETE FROM %i', Changes_Logs_DB_Log::table_name( 'changes_logs' ) ) );
		}

		/**
		 * Returns stored application password events with their metadata, keyed by event ID.
		 *
		 * @return array Stored rows.
		 */
		private function events() {
			global $wpdb;
			Changes_Logs_Logger::change_process_delay_logs_items();
			$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM %i WHERE log_type_id IN (1720, 1721, 1730) ORDER BY id', Changes_Logs_DB_Log::table_name( 'changes_logs' ) ), ARRAY_A );
			foreach ( $rows as &$row ) {
				$metadata    = $wpdb->get_results( $wpdb->prepare( 'SELECT name, value FROM %i WHERE log_id = %d', Changes_Logs_DB_Log::table_name( 'changes_meta' ), $row['id'] ), ARRAY_A );
				$row['meta'] = array();
				foreach ( $metadata as $item ) {
					$row['meta'][ $item['name'] ] = maybe_unserialize( $item['value'] );
				}
			}
			$this->assertSame( '', $wpdb->last_error );
			return $rows;
		}

		/**
		 * Returns the UUID of a stored application password by name.
		 *
		 * @param string $name Application password name.
		 * @return string UUID.
		 */
		private function uuid( $name ) {
			foreach ( WP_Application_Passwords::get_user_application_passwords( $this->user_id ) as $item ) {
				if ( $name === $item['name'] ) {
					return $item['uuid'];
				}
			}
			$this->fail( 'Missing application password ' . $name );
		}

		/**
		 * WP Force Logout writes last_login as an int on the same revoke request (community topic 10867).
		 */
		public function test_scalar_meta_on_revoke_request_is_ignored() {
			add_user_meta( $this->user_id, 'last_login', 1700000000 );
			$this->clear_logs();
			$GLOBALS['mainwp_test_changes_server']['REQUEST_URI'] .= '/' . $this->uuid( 'First key' );

			update_user_meta( $this->user_id, 'last_login', 1800000000 );

			$this->assertSame( array(), $this->events() );
		}

		/**
		 * Unrelated array meta must not be logged as a revoked application password.
		 */
		public function test_unrelated_array_meta_is_not_logged_as_revocation() {
			add_user_meta(
				$this->user_id,
				'session_tokens_backup',
				array(
					'a' => 1,
					'b' => 2,
					'c' => 3,
				)
			);
			$this->clear_logs();

			update_user_meta( $this->user_id, 'session_tokens_backup', array( 'a' => 1 ) );

			$this->assertSame( array(), $this->events() );
		}

		/**
		 * A non-array value written to the application password key is not counted.
		 */
		public function test_non_array_application_password_value_is_ignored() {
			update_user_meta( $this->user_id, '_application_passwords', 'invalid' );

			$this->assertSame( array(), $this->events() );
		}

		/**
		 * Unrelated meta written during a create request must not add a second created event.
		 */
		public function test_create_logs_one_event() {
			add_user_meta( $this->user_id, 'last_login', 1700000000 );
			$this->clear_logs();
			$_POST['name'] = 'Third key';

			WP_Application_Passwords::create_new_application_password( $this->user_id, array( 'name' => 'Third key' ) );
			update_user_meta( $this->user_id, 'last_login', 1800000000 );

			$events = $this->events();
			$this->assertCount( 1, $events );
			$this->assertSame( '1720', (string) $events[0]['log_type_id'] );
			$this->assertSame( 'Third key', $events[0]['meta']['friendly_name'] );
			$this->assertSame( 'added', $events[0]['action_name'] );
		}

		/**
		 * Revoking one application password still logs its name.
		 */
		public function test_single_revoke_is_logged() {
			$uuid = $this->uuid( 'First key' );
			$GLOBALS['mainwp_test_changes_server']['REQUEST_URI'] .= '/' . $uuid;

			$this->assertTrue( WP_Application_Passwords::delete_application_password( $this->user_id, $uuid ) );

			$events = $this->events();
			$this->assertCount( 1, $events );
			$this->assertSame( '1721', (string) $events[0]['log_type_id'] );
			$this->assertSame( 'First key', $events[0]['meta']['friendly_name'] );
			$this->assertSame( 'revoked', $events[0]['action_name'] );
		}

		/**
		 * Revoking all application passwords still logs the revoke-all event.
		 */
		public function test_revoke_all_is_logged() {
			$this->assertSame( 2, WP_Application_Passwords::delete_all_application_passwords( $this->user_id ) );

			$events = $this->events();
			$this->assertCount( 1, $events );
			$this->assertSame( '1730', (string) $events[0]['log_type_id'] );
			$this->assertSame( 'revoked', $events[0]['action_name'] );
		}
	}
}
