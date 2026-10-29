<?php
/**
 * Regression coverage for comment actors and persisted profile roles.
 *
 * @package Mainwp_Child
 */

use MainWP\Child\Changes\Changes_Handle_WP_Comments;
use MainWP\Child\Changes\Changes_Handle_WP_User_Profile;
use MainWP\Child\Changes\Changes_Helper;
use MainWP\Child\Changes\Changes_Logs_DB_Log;
use MainWP\Child\Changes\Changes_Logs_Logger;

/**
 * Avoids the absent DB version cached before the test installation.
 */
class MainWP_Changes_Actors_Test_DB extends Changes_Logs_DB_Log {

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
 * Exercises the real hooks, logger, and log tables.
 */
class Test_Changes_Logs_Actors extends WP_UnitTestCase {

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
	 * Members fixture callback.
	 *
	 * @var Closure|null
	 */
	private $members_callback;

	/**
	 * Whether delayed events have been flushed for this simulated request.
	 *
	 * @var bool
	 */
	private $delayed_logs_flushed = false;

	/**
	 * Prepares real storage and isolates request-local logger state.
	 */
	public function set_up() {
		parent::set_up();
		$this->original_post = $_POST;
		$_POST               = array();
		$instance            = new ReflectionProperty( Changes_Logs_DB_Log::class, 'instance' );
		$instance->setAccessible( true );
		$this->original_db = $instance->getValue();
		$instance->setValue( null, new MainWP_Changes_Actors_Test_DB() );
		Changes_Logs_DB_Log::instance()->install();
		Changes_Handle_WP_Comments::init_hooks();
		Changes_Handle_WP_User_Profile::init_hooks();
		$this->clear_logs();
	}

	/**
	 * Restores the DB singleton and request fields.
	 */
	public function tear_down() {
		if ( $this->members_callback ) {
			remove_action( 'profile_update', $this->members_callback, 10 );
		}
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
		$this->delayed_logs_flushed = false;
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
	 * Returns stored events with their metadata.
	 *
	 * @param int $event Event ID.
	 * @return array Stored rows.
	 */
	private function events( $event ) {
		global $wpdb;
		if ( ! $this->delayed_logs_flushed ) {
			Changes_Logs_Logger::change_process_delay_logs_items();
			$this->delayed_logs_flushed = true;
		}
		$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM %i WHERE log_type_id = %d ORDER BY id', Changes_Logs_DB_Log::table_name( 'changes_logs' ), $event ), ARRAY_A );
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
	 * Creates a comment through WordPress's comment_post hook.
	 *
	 * @param array $data Comment overrides.
	 * @return int Comment ID.
	 */
	private function submit_comment( $data = array() ) {
		return wp_new_comment(
			array_merge(
				array(
					'comment_post_ID'      => self::factory()->post->create(),
					'comment_author'       => 'Submitted Name',
					'comment_author_email' => 'submitted@example.org',
					'comment_author_url'   => '',
					'comment_content'      => 'Comment fixture ' . wp_generate_uuid4(),
					'comment_approved'     => 1,
					'user_id'              => 0,
				),
				$data
			),
			true
		);
	}

	/**
	 * The authenticated author can submit another account's email.
	 */
	public function test_authenticated_comment_uses_user_id_despite_alternate_email() {
		$author = self::factory()->user->create_and_get( array( 'role' => 'author' ) );
		$other  = self::factory()->user->create_and_get( array( 'role' => 'editor' ) );
		wp_set_current_user( $author->ID );
		$this->clear_logs();
		$this->submit_comment(
			array(
				'user_id'              => $author->ID,
				'comment_author_email' => $other->user_email,
			)
		);
		$rows = $this->events( 1565 );
		$this->assertCount( 1, $rows );
		$this->assertSame( $author->ID, (int) $rows[0]['user_id'] );
		$this->assertSame( $author->user_login, $rows[0]['username'] );
		$this->assertSame( 'author', $rows[0]['user_roles'] );
	}

	/**
	 * A matching email does not identify a guest as a registered user.
	 */
	public function test_guest_with_registered_email_stays_unverified() {
		$admin = self::factory()->user->create_and_get( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin->ID );
		$this->clear_logs();
		$this->submit_comment( array( 'comment_author_email' => $admin->user_email ) );
		$rows = $this->events( 1565 );
		$this->assertCount( 1, $rows );
		$this->assert_guest( $rows[0], 'Submitted Name', $admin->user_email );
	}

	/**
	 * Unknown guests retain the complete submitted identity in metadata.
	 */
	public function test_unknown_guest_is_logged_with_full_unverified_identity() {
		wp_set_current_user( 0 );
		$name = str_repeat( 'Long submitted name ', 12 ) . 'End';
		$this->submit_comment( array( 'comment_author' => $name ) );
		$rows = $this->events( 1565 );
		$this->assertCount( 1, $rows );
		$this->assert_guest( $rows[0], $name, 'submitted@example.org' );
	}

	/**
	 * Checks the persisted guest actor and existing author metadata.
	 *
	 * @param array  $row Stored event.
	 * @param string $name Submitted name.
	 * @param string $email Submitted email.
	 */
	private function assert_guest( $row, $name, $email ) {
		$this->assertSame( 0, (int) $row['user_id'] );
		$this->assertSame( 'Guest (unverified)', $row['username'] );
		$this->assertSame( '', $row['user_roles'] );
		$this->assertSame( $name . ' <' . $email . '> (unverified)', $row['meta']['author'] );
	}

	/**
	 * Guest creation events exclude pingbacks, trackbacks, and custom records.
	 */
	public function test_guest_events_only_include_real_comments() {
		$submit_typed_comment = function ( $comment_type, $user_id = 0, $email = 'submitted@example.org' ) {
			$data       = array(
				'comment_post_ID'      => self::factory()->post->create(),
				'comment_author'       => 'Submitted Name',
				'comment_author_email' => $email,
				'comment_content'      => 'Typed comment fixture',
				'comment_approved'     => 1,
				'comment_type'         => $comment_type,
				'user_id'              => $user_id,
			);
			$comment_id = wp_insert_comment( $data );
			$this->assertGreaterThan( 0, $comment_id );
			$this->assertSame( $comment_type, get_comment( $comment_id )->comment_type );
			do_action( 'comment_post', $comment_id, 1, $data );
		};
		wp_set_current_user( 0 );
		$this->submit_comment();
		$rows = $this->events( 1565 );
		$this->assertCount( 1, $rows );
		$this->assert_guest( $rows[0], 'Submitted Name', 'submitted@example.org' );
		foreach ( array( 'pingback', 'trackback', 'custom_note' ) as $comment_type ) {
			$this->clear_logs();
			$submit_typed_comment( $comment_type );
			$this->assertCount( 0, $this->events( 1565 ), $comment_type . ' must not produce a guest comment event.' );
		}
		$user = self::factory()->user->create_and_get( array( 'role' => 'author' ) );
		wp_set_current_user( $user->ID );
		foreach ( array( 'pingback', 'trackback', 'custom_note' ) as $comment_type ) {
			$this->clear_logs();
			$submit_typed_comment( $comment_type, $user->ID, $user->user_email );
			$rows = $this->events( 1565 );
			$this->assertCount( 1, $rows, 'Registered non-comment behavior must remain unchanged.' );
			$this->assertSame( $user->ID, (int) $rows[0]['user_id'] );
		}
	}

	/**
	 * Reply creation uses comment identity while edits and moderation use the operator.
	 */
	public function test_replies_use_comment_identity_and_moderation_keeps_operator() {
		$admin  = self::factory()->user->create_and_get( array( 'role' => 'administrator' ) );
		$author = self::factory()->user->create_and_get( array( 'role' => 'author' ) );
		$parent = self::factory()->comment->create();
		wp_set_current_user( $admin->ID );
		$this->clear_logs();
		$reply = $this->submit_comment(
			array(
				'comment_parent' => $parent,
				'user_id'        => $author->ID,
			)
		);
		$rows  = $this->events( 1530 );
		$this->assertCount( 1, $rows );
		$this->assertSame( $author->ID, (int) $rows[0]['user_id'] );
		$this->assertSame( 'author', $rows[0]['user_roles'] );
		$this->assertCount( 0, $this->events( 1565 ) );
		$this->clear_logs();
		$this->submit_comment( array( 'comment_parent' => $parent ) );
		$rows = $this->events( 1530 );
		$this->assertCount( 1, $rows );
		$this->assert_guest( $rows[0], 'Submitted Name', 'submitted@example.org' );
		$this->clear_logs();
		wp_update_comment(
			array(
				'comment_ID'      => $reply,
				'comment_content' => 'Edited reply',
			)
		);
		$rows = $this->events( 1535 );
		$this->assertCount( 1, $rows );
		$this->assertSame( $admin->ID, (int) $rows[0]['user_id'] );
		$this->clear_logs();
		$this->assertTrue( wp_set_comment_status( $reply, 'approve' ) );
		$this->assertSame( 'approved', wp_get_comment_status( $reply ) );
		$this->clear_logs();
		$this->assertTrue( wp_set_comment_status( $reply, 'hold' ) );
		$rows = $this->events( 1525 );
		$this->assertCount( 1, $rows );
		$this->assertSame( $admin->ID, (int) $rows[0]['user_id'] );
		$this->clear_logs();
		do_action( 'transition_comment_status', 'unapproved', 'unapproved', get_comment( $reply ) );
		$this->assertCount( 0, $this->events( 1525 ) );
	}

	/**
	 * Installs the Members save order and mutates the supplied old user object.
	 */
	private function install_members_fixture() {
		remove_action( 'profile_update', array( Changes_Handle_WP_User_Profile::class, 'callback_change_user_updated' ), 10 );
		$this->members_callback = static function ( $user_id, $old_user ) {
			if ( ! current_user_can( 'promote_users' ) || ! current_user_can( 'edit_user', $user_id ) || ! isset( $_POST['members_user_roles'] ) ) {
				return;
			}
			$old_roles = $old_user->roles;
			$new_roles = array_map( 'sanitize_key', wp_unslash( $_POST['members_user_roles'] ) );
			foreach ( array_diff( $new_roles, $old_roles ) as $role ) {
				$old_user->add_role( $role );
			}
			foreach ( array_diff( $old_roles, $new_roles ) as $role ) {
				$old_user->remove_role( $role );
			}
		};
		add_action( 'profile_update', $this->members_callback, 10, 2 );
		add_action( 'profile_update', array( Changes_Handle_WP_User_Profile::class, 'callback_change_user_updated' ), 10, 2 );
	}

	/**
	 * A subscriber's posted roles cannot create an event for an unsaved change.
	 */
	public function test_subscriber_profile_ignores_posted_members_roles() {
		$user = self::factory()->user->create_and_get( array( 'role' => 'subscriber' ) );
		wp_set_current_user( $user->ID );
		$this->install_members_fixture();
		$this->clear_logs();
		$_POST['members_user_roles'] = array( 'administrator' );
		$this->assertSame(
			$user->ID,
			wp_update_user(
				array(
					'ID'           => $user->ID,
					'display_name' => 'Updated subscriber',
				)
			)
		);
		$this->assertSame( array( 'subscriber' ), get_userdata( $user->ID )->roles );
		$this->assertCount( 0, $this->events( 1615 ) );
		$this->assertCount( 1, $this->events( 1710 ) );
		$this->clear_logs();
		Changes_Handle_WP_User_Profile::callback_change_user_role_changed( $user->ID, array( 'administrator' ), array( 'subscriber' ), true );
		$this->assertCount( 0, $this->events( 1615 ) );
	}

	/**
	 * Members changes are compared after persistence and each save gets a fresh snapshot.
	 */
	public function test_members_multi_roles_and_repeated_saves_use_persisted_snapshots() {
		$user  = self::factory()->user->create_and_get( array( 'role' => 'subscriber' ) );
		$admin = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin );
		$this->install_members_fixture();
		$this->clear_logs();
		$_POST['members_user_roles'] = array( 'editor', 'author' );
		wp_update_user(
			array(
				'ID'           => $user->ID,
				'display_name' => 'Members name',
			)
		);
		$this->assertSame( array( 'editor', 'author' ), get_userdata( $user->ID )->roles );
		$rows = $this->events( 1615 );
		$this->assertCount( 1, $rows );
		$this->assert_role_event( $rows[0], $user, 'Subscriber', 'Editor, Author' );
		$this->assertCount( 1, $this->events( 1710 ) );
		$this->clear_logs();
		wp_update_user( array( 'ID' => $user->ID ) );
		$this->assertCount( 0, $this->events( 1615 ) );
		$this->clear_logs();
		$_POST['members_user_roles'] = array( 'author' );
		wp_update_user( array( 'ID' => $user->ID ) );
		$rows = $this->events( 1615 );
		$this->assertCount( 1, $rows );
		$this->assert_role_event( $rows[0], $user, 'Editor, Author', 'Author' );
	}

	/**
	 * Removing all Members roles logs the old role and an empty persisted result.
	 */
	public function test_members_empty_roles_are_logged_once() {
		$user  = self::factory()->user->create_and_get( array( 'role' => 'subscriber' ) );
		$admin = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin );
		$this->install_members_fixture();
		$this->clear_logs();
		$_POST['members_user_roles'] = array();
		wp_update_user( array( 'ID' => $user->ID ) );
		$this->assertSame( array(), get_userdata( $user->ID )->roles );
		$rows = $this->events( 1615 );
		$this->assertCount( 1, $rows );
		$this->assert_role_event( $rows[0], $user, 'Subscriber', '' );
		$this->assertSame( 'none', $rows[0]['meta']['changeuserdata']->userroles );
		$this->clear_logs();
		wp_update_user( array( 'ID' => $user->ID ) );
		$this->assertCount( 0, $this->events( 1615 ) );
	}

	/**
	 * A nested save preserves the outer roles until all outer callbacks finish.
	 */
	public function test_nested_profile_save_keeps_outer_role_snapshot() {
		$user  = self::factory()->user->create_and_get( array( 'role' => 'subscriber' ) );
		$admin = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin );
		$this->install_members_fixture();
		$this->clear_logs();
		$_POST['members_user_roles'] = array( 'editor' );
		$nested_once                 = false;
		$inside_nested               = false;
		$nested_save                 = function ( $user_id ) use ( $user, &$nested_once, &$inside_nested ) {
			if ( $user_id !== $user->ID || $nested_once ) {
				return;
			}
			$nested_once   = true;
			$inside_nested = true;
			try {
				$this->assertSame(
					$user_id,
					wp_update_user(
						array(
							'ID'           => $user_id,
							'display_name' => 'Nested profile name',
						)
					)
				);
			} finally {
				$inside_nested = false;
			}
		};
		$late_role_write             = static function ( $user_id ) use ( $user, &$inside_nested ) {
			if ( $user_id === $user->ID && ! $inside_nested ) {
				get_userdata( $user_id )->add_role( 'author' );
			}
		};
		add_action( 'profile_update', $nested_save, 15, 1 );
		add_action( 'profile_update', $late_role_write, 18, 1 );
		try {
			$this->assertSame( $user->ID, wp_update_user( array( 'ID' => $user->ID ) ) );
			$this->assertTrue( $nested_once );
			$this->assertSame( 'Nested profile name', get_userdata( $user->ID )->display_name );
			$this->assertSame( array( 'editor', 'author' ), get_userdata( $user->ID )->roles );
			$rows = $this->events( 1615 );
			$this->assertCount( 1, $rows );
			$this->assert_role_event( $rows[0], $user, 'Subscriber', 'Editor, Author' );
			$this->assertSame( array_column( $rows, 'id' ), array_column( $this->events( 1615 ), 'id' ), 'Reading events again must not insert another physical row.' );
		} finally {
			remove_action( 'profile_update', $nested_save, 15 );
			remove_action( 'profile_update', $late_role_write, 18 );
			remove_action( 'profile_update', $this->members_callback, 10 );
		}
		$this->clear_logs();
		wp_update_user( array( 'ID' => $user->ID ) );
		$this->assertCount( 0, $this->events( 1615 ), 'An unchanged later save must not reuse the outer snapshot.' );
	}

	/**
	 * Core role changes keep the existing single event contract.
	 */
	public function test_core_role_change_compatibility() {
		$user  = self::factory()->user->create_and_get( array( 'role' => 'subscriber' ) );
		$admin = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin );
		$this->clear_logs();
		$_POST['members_user_roles'] = array( 'author' );
		wp_update_user(
			array(
				'ID'   => $user->ID,
				'role' => 'editor',
			)
		);
		$rows = $this->events( 1615 );
		$this->assertCount( 1, $rows );
		$this->assert_role_event( $rows[0], $user, 'Subscriber', 'Editor' );
		$this->clear_logs();
		wp_update_user(
			array(
				'ID'   => $user->ID,
				'role' => 'editor',
			)
		);
		$this->assertCount( 0, $this->events( 1615 ) );
	}

	/**
	 * Checks the existing role-event metadata contract.
	 *
	 * @param array   $row Stored event.
	 * @param WP_User $user Changed user.
	 * @param string  $old Old role names.
	 * @param string  $expected_new New role names.
	 */
	private function assert_role_event( $row, $user, $old, $expected_new ) {
		$this->assertSame( $user->ID, (int) $row['meta']['changeuserid'] );
		$this->assertSame( $user->user_login, $row['meta']['changeusername'] );
		$this->assertSame( $old, $row['meta']['oldrole'] );
		$this->assertSame( $expected_new, $row['meta']['newrole'] );
	}
}
