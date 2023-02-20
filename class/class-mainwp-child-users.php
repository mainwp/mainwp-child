<?php
/**
 * MainWP Child Users.
 *
 * Manage users on the site.
 *
 * @package MainWP\Child
 */

namespace MainWP\Child;

/**
 * Class MainWP_Child_Users
 *
 * Manage users on the site.
 */
class MainWP_Child_Users {

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
	 * MainWP_Child_Users constructor.
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
	 * User actions: changeRole, update_password, edit, update_user.
	 *
	 * @uses \MainWP\Child\MainWP_Helper::write()
	 */
	public function user_action() { // phpcs:ignore -- Current complexity is the only way to achieve desired results, pull request solutions appreciated.
		$action    = ! empty( $_POST['action'] ) ? sanitize_text_field( wp_unslash( $_POST['action'] ) ) : '';
		$extra     = isset( $_POST['extra'] ) ? wp_unslash( $_POST['extra'] ) : '';
		$userId    = isset( $_POST['id'] ) ? sanitize_text_field( wp_unslash( $_POST['id'] ) ) : '';
		$user_pass = isset( $_POST['user_pass'] ) ? wp_unslash( $_POST['user_pass'] ) : '';
		$failed    = false;

		/**
		 * Current user global.
		 *
		 * @global string
		 */
		global $current_user;

		$reassign = ( isset( $current_user ) && isset( $current_user->ID ) ) ? $current_user->ID : 0;
		include_once ABSPATH . '/wp-admin/includes/user.php';

		if ( 'delete' === $action ) {
			wp_delete_user( $userId, $reassign );
		} elseif ( 'changeRole' === $action ) {
			$my_user         = array();
			$my_user['ID']   = $userId;
			$my_user['role'] = $extra;
			wp_update_user( $my_user );
		} elseif ( 'update_password' === $action ) {
			$my_user              = array();
			$my_user['ID']        = $userId;
			$my_user['user_pass'] = $user_pass;
			wp_update_user( $my_user );
		} elseif ( 'edit' === $action ) {
			$user_data = $this->get_user_to_edit( $userId );
			if ( ! empty( $user_data ) ) {
				$information['user_data'] = $user_data;
			} else {
				$failed = true;
			}
		} elseif ( 'update_user' === $action ) {
			$my_user = $extra;
			if ( is_array( $my_user ) ) {
				foreach ( $my_user as $idx => $val ) {
					if ( 'donotupdate' === $val || ( empty( $val ) && 'role' !== $idx ) ) {
						unset( $my_user[ $idx ] );
					}
				}
				$result = $this->edit_user( $userId, $my_user );
				if ( is_array( $result ) && isset( $result['error'] ) ) {
					$information['error'] = $result['error'];
				}
			} else {
				$failed = true;
			}
		} else {
			$failed = true;
		}

		if ( $failed ) {
			$information['status'] = 'FAIL';
		}

		if ( ! isset( $information['status'] ) && ! isset( $information['error'] ) ) {
			$information['status'] = 'SUCCESS';
			if ( 'update_user' === $action && isset( $_POST['optimize'] ) && ! empty( $_POST['optimize'] ) ) {
				$information['users'] = $this->get_all_users_int( 500 );
			}
		}
		MainWP_Helper::write( $information );
	}

	/**
	 * Get all users.
	 *
	 * @param bool $number Number parameter.
	 *
	 * @return array Return array of $allusers.
	 */
	public function get_all_users_int( $number = false ) {
		$allusers = array();

		$params = array();
		if ( $number ) {
			$params['number'] = $number;
		}

		$users_number = get_option( 'mainwp_child_sync_users_number', 0 );
		if ( ! empty( $users_number ) ) {
			$params['number'] = intval( $users_number );
		}

		$new_users = get_users( $params );
		if ( is_array( $new_users ) ) {
			foreach ( $new_users as $new_user ) {
				$usr                 = array();
				$usr['id']           = $new_user->ID;
				$usr['login']        = $new_user->user_login;
				$usr['nicename']     = $new_user->user_nicename;
				$usr['email']        = $new_user->user_email;
				$usr['registered']   = $new_user->user_registered;
				$usr['status']       = $new_user->user_status;
				$usr['display_name'] = $new_user->display_name;
				$userdata            = get_userdata( $new_user->ID );
				$user_roles          = $userdata->roles;
				$user_role           = array_shift( $user_roles );
				$usr['role']         = $user_role;
				$usr['post_count']   = count_user_posts( $new_user->ID );
				$allusers[]          = $usr;
			}
		}

		return $allusers;
	}

	/**
	 * Get all child site users.
	 *
	 * @param bool $return Whether or not to return. Default: false.
	 *
	 * @return array Return array of $allusers.
	 *
	 * @uses \MainWP\Child\MainWP_Helper::write()
	 */
	public function get_all_users( $return = false ) {
		$roles    = isset( $_POST['role'] ) ? explode( ',', sanitize_text_field( wp_unslash( $_POST['role'] ) ) ) : array();
		$allusers = array();
		if ( is_array( $roles ) ) {
			foreach ( $roles as $role ) {
				$new_users = get_users( 'role=' . $role );
				foreach ( $new_users as $new_user ) {
					$usr                 = array();
					$usr['id']           = $new_user->ID;
					$usr['login']        = $new_user->user_login;
					$usr['nicename']     = $new_user->user_nicename;
					$usr['email']        = $new_user->user_email;
					$usr['registered']   = $new_user->user_registered;
					$usr['status']       = $new_user->user_status;
					$usr['display_name'] = $new_user->display_name;
					$usr['role']         = $role;
					$usr['post_count']   = count_user_posts( $new_user->ID );
					$usr['avatar']       = get_avatar( $new_user->ID, 32 );
					$allusers[]          = $usr;
				}
			}
		}
		if ( $return ) {
			return $allusers;
		}
		MainWP_Helper::write( $allusers );
	}

	/**
	 * Search child site users.
	 *
	 * @uses \MainWP\Child\MainWP_Helper::write()
	 */
	public function search_users() {

		$search_user_role = array();
		$check_users_role = false;

		if ( isset( $_POST['role'] ) && ! empty( $_POST['role'] ) ) {
			$check_users_role = true;
			$all_users_role   = $this->get_all_users( true );
			foreach ( $all_users_role as $user ) {
				$search_user_role[] = $user['id'];
			}
			unset( $all_users_role );
		}

		$columns  = isset( $_POST['search_columns'] ) ? explode( ',', wp_unslash( $_POST['search_columns'] ) ) : array();
		$allusers = array();
		$exclude  = array();

		foreach ( $columns as $col ) {
			if ( empty( $col ) ) {
				continue;
			}

			$user_query = new \WP_User_Query(
				array(
					'search'         => isset( $_POST['search'] ) ? wp_unslash( $_POST['search'] ) : '',
					'fields'         => 'all_with_meta',
					'search_columns' => array( $col ),
					'query_orderby'  => array( $col ),
					'exclude'        => $exclude,
				)
			);
			if ( ! empty( $user_query->results ) ) {
				foreach ( $user_query->results as $new_user ) {
					if ( $check_users_role ) {
						if ( ! in_array( $new_user->ID, $search_user_role ) ) {
							continue;
						}
					}
					$exclude[]           = $new_user->ID;
					$usr                 = array();
					$usr['id']           = $new_user->ID;
					$usr['login']        = $new_user->user_login;
					$usr['nicename']     = $new_user->user_nicename;
					$usr['email']        = $new_user->user_email;
					$usr['registered']   = $new_user->user_registered;
					$usr['status']       = $new_user->user_status;
					$usr['display_name'] = $new_user->display_name;
					$userdata            = get_userdata( $new_user->ID );
					$user_roles          = $userdata->roles;
					$user_role           = array_shift( $user_roles );
					$usr['role']         = $user_role;
					$usr['post_count']   = count_user_posts( $new_user->ID );
					$usr['avatar']       = get_avatar( $new_user->ID, 32 );
					$allusers[]          = $usr;
				}
			}
		}

		MainWP_Helper::write( $allusers );
	}

	/**
	 * Edit existing user.
	 *
	 * @param int   $user_id User ID.
	 * @param array $data    Data to edit.
	 *
	 * @return string|int Return error string on failure or user ID on success.
	 */
	public function edit_user( $user_id, $data ) { // phpcs:ignore -- ignore complex method notice, see detail at: function edit_user() in the wp/wp-admin/includes/user.php.
		$wp_roles = wp_roles();
		$user     = new \stdClass();

		$update = true;

		if ( $user_id ) {
			$user->ID         = (int) $user_id;
			$userdata         = get_userdata( $user_id );
			$user->user_login = wp_slash( $userdata->user_login );
		} else {
			return array( 'error' => 'ERROR: Empty user id.' );
		}

		$pass1 = '';
		$pass2 = '';

		if ( isset( $data['pass1'] ) ) {
			$pass1 = $data['pass1'];
		}

		if ( isset( $data['pass2'] ) ) {
				$pass2 = $data['pass2'];
		}

		if ( isset( $data['role'] ) && current_user_can( 'edit_users' ) ) {
			$new_role       = sanitize_text_field( $data['role'] );
			$potential_role = isset( $wp_roles->role_objects[ $new_role ] ) ? $wp_roles->role_objects[ $new_role ] : false;
			// Don't let anyone with 'edit_users' (admins) edit their own role to something without it.
			// Multisite super admins can freely edit their blog roles -- they possess all caps.
			if ( ( is_multisite() && current_user_can( 'manage_sites' ) ) || get_current_user_id() != $user_id || ( $potential_role && $potential_role->has_cap( 'edit_users' ) ) ) {
					$user->role = $new_role;
			}
			// If the new role isn't editable by the logged-in user die with error.
			$editable_roles = get_editable_roles();
			if ( ! empty( $new_role ) && empty( $editable_roles[ $new_role ] ) ) {
				return array( 'error' => 'You can&#8217;t give users that role.' );
			}
		}

		$email = '';
		if ( isset( $data['email'] ) ) {
			$email = trim( $data['email'] );
		}

		if ( ! empty( $email ) ) {
			$user->user_email = sanitize_text_field( wp_unslash( $email ) );
		} else {
			$user->user_email = $userdata->user_email;
		}

		if ( isset( $data['url'] ) ) {
			if ( empty( $data['url'] ) || 'http://' == $data['url'] ) {
				$user->user_url = '';
			} else {
				$user->user_url = esc_url_raw( $data['url'] );
				$protocols      = implode( '|', array_map( 'preg_quote', wp_allowed_protocols() ) );
				$user->user_url = preg_match( '/^(' . $protocols . '):/is', $user->user_url ) ? $user->user_url : 'http://' . $user->user_url;
			}
		}

		if ( isset( $data['first_name'] ) ) {
			$user->first_name = sanitize_text_field( $data['first_name'] );
		}
		if ( isset( $data['last_name'] ) ) {
			$user->last_name = sanitize_text_field( $data['last_name'] );
		}
		if ( isset( $data['nickname'] ) && ! empty( $data['nickname'] ) ) {
			$user->nickname = sanitize_text_field( $data['nickname'] );
		}
		if ( isset( $data['display_name'] ) ) {
			$user->display_name = sanitize_text_field( $data['display_name'] );
		}
		if ( isset( $data['description'] ) ) {
			$user->description = trim( $data['description'] );
		}

		$errors = new \WP_Error();

		// checking that username has been typed.
		if ( '' == $user->user_login ) {
			$errors->add( 'user_login', esc_html__( '<strong>ERROR</strong>: Please enter a username.' ) );
		}

		do_action_ref_array( 'check_passwords', array( $user->user_login, &$pass1, &$pass2 ) );

		if ( ! empty( $pass1 ) || ! empty( $pass2 ) ) {
			// Check for blank password when adding a user.
			if ( ! $update && empty( $pass1 ) ) {
				$errors->add( 'pass', esc_html__( '<strong>ERROR</strong>: Please enter a password.' ), array( 'form-field' => 'pass1' ) );
			}
			// Check for "\" in password.
			if ( false !== strpos( wp_unslash( $pass1 ), '\\' ) ) {
				$errors->add( 'pass', esc_html__( '<strong>ERROR</strong>: Passwords may not contain the character "\\".' ), array( 'form-field' => 'pass1' ) );
			}
			// Checking the password has been typed twice the same.
			if ( ( $update || ! empty( $pass1 ) ) && $pass1 != $pass2 ) {
				$errors->add( 'pass', esc_html__( '<strong>ERROR</strong>: Please enter the same password in both password fields.' ), array( 'form-field' => 'pass1' ) );
			}

			if ( ! empty( $pass1 ) ) {
				$user->user_pass = $pass1;
			}
		} else {
			$user->user_pass = $userdata->user_pass;
		}

		$illegal_logins = (array) apply_filters( 'illegal_user_logins', array() );

		if ( in_array( strtolower( $user->user_login ), array_map( 'strtolower', $illegal_logins ) ) ) {
			$errors->add( 'invalid_username', esc_html__( '<strong>ERROR</strong>: Sorry, that username is not allowed.' ) );
		}

		$owner_id = email_exists( $user->user_email );

		if ( empty( $user->user_email ) ) {
			$errors->add( 'empty_email', esc_html__( '<strong>ERROR</strong>: Please enter an email address.' ), array( 'form-field' => 'email' ) );
		} elseif ( ! is_email( $user->user_email ) ) {
			$errors->add( 'invalid_email', esc_html__( '<strong>ERROR</strong>: The email address isn&#8217;t correct.' ), array( 'form-field' => 'email' ) );
		} elseif ( ( $owner_id ) && ( ! $update || ( $owner_id != $user->ID ) ) ) {
			$errors->add( 'email_exists', esc_html__( '<strong>ERROR</strong>: This email is already registered, please choose another one.' ), array( 'form-field' => 'email' ) );
		}

		do_action_ref_array( 'user_profile_update_errors', array( &$errors, $update, &$user ) );

		if ( $errors->get_error_codes() ) {
			$error_str = '';
			foreach ( $errors->get_error_messages() as $message ) {
				if ( is_string( $message ) ) {
					$error_str .= ' ' . esc_html( wp_strip_all_tags( $message ) );
				}
			}
			return array( 'error' => $error_str );
		}

		$user_id = wp_update_user( $user );

		return $user_id;
	}

	/**
	 * Get Child Site user to edit.
	 *
	 * @param int $user_id User ID.
	 *
	 * @return array Return array of $edit_data.
	 */
	public function get_user_to_edit( $user_id ) {
		require_once ABSPATH . 'wp-admin/includes/user.php';
		$profileuser = get_user_to_edit( $user_id );

		$edit_data = array();
		if ( is_object( $profileuser ) ) {
			$user_roles              = array_intersect( array_values( $profileuser->roles ), array_keys( get_editable_roles() ) );
			$user_role               = reset( $user_roles );
			$edit_data['role']       = $user_role;
			$edit_data['first_name'] = $profileuser->first_name;
			$edit_data['last_name']  = $profileuser->last_name;
			$edit_data['nickname']   = $profileuser->nickname;

			$public_display                     = array();
			$public_display['display_nickname'] = $profileuser->nickname;
			$public_display['display_username'] = $profileuser->user_login;

			if ( ! empty( $profileuser->first_name ) ) {
					$public_display['display_firstname'] = $profileuser->first_name;
			}

			if ( ! empty( $profileuser->last_name ) ) {
					$public_display['display_lastname'] = $profileuser->last_name;
			}

			if ( ! empty( $profileuser->first_name ) && ! empty( $profileuser->last_name ) ) {
					$public_display['display_firstlast'] = $profileuser->first_name . ' ' . $profileuser->last_name;
					$public_display['display_lastfirst'] = $profileuser->last_name . ' ' . $profileuser->first_name;
			}

			if ( ! in_array( $profileuser->display_name, $public_display ) ) { // Only add this if it isn't duplicated elsewhere!
					$public_display = array( 'display_displayname' => $profileuser->display_name ) + $public_display;
			}

			$public_display = array_map( 'trim', $public_display );
			$public_display = array_unique( $public_display );

			$edit_data['public_display'] = $public_display;
			$edit_data['display_name']   = $profileuser->display_name;
			$edit_data['user_email']     = $profileuser->user_email;
			$edit_data['user_url']       = $profileuser->user_url;
			foreach ( wp_get_user_contact_methods( $profileuser ) as $name => $desc ) {
				$edit_data['contact_methods'][ $name ] = $profileuser->$name;
			}
			$edit_data['description'] = $profileuser->description;
		}
		return $edit_data;
	}

	/**
	 * Set a new administrator password.
	 *
	 * @uses \MainWP\Child\MainWP_Helper::write()
	 * @uses \MainWP\Child\MainWP_Helper::instance()->error()
	 */
	public function new_admin_password() {
		$new_password = isset( $_POST['new_password'] ) ? base64_decode( wp_unslash( $_POST['new_password'] ) ) : ''; // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions -- base64_encode function is used for http encode compatible..

		$user  = null;
		$uname = isset( $_POST['user'] ) ? wp_unslash( $_POST['user'] ) : '';
		if ( ! empty( $uname ) ) {
			$user = get_user_by( 'login', $uname );
		}

		if ( empty( $user ) ) {
			MainWP_Helper::write( array() );
		}

		require_once ABSPATH . WPINC . '/registration.php';

		$id = wp_update_user(
			array(
				'ID'        => $user->ID,
				'user_pass' => $new_password,
			)
		);
		if ( $id !== $user->ID ) {
			if ( is_wp_error( $id ) ) {
				MainWP_Helper::instance()->error( $id->get_error_message() );
			} else {
				MainWP_Helper::instance()->error( esc_html__( 'Administrator password could not be changed.', 'mainwp-child' ) );
			}
		}

		$information['added'] = true;
		MainWP_Helper::write( $information );
	}

	/**
	 * Create a new user.
	 *
	 * @uses \MainWP\Child\MainWP_Helper::write()
	 * @uses \MainWP\Child\MainWP_Helper::instance()->error()
	 */
	public function new_user() {
		$new_user      = isset( $_POST['new_user'] ) ? json_decode( base64_decode( wp_unslash( $_POST['new_user'] ) ), true ) : ''; // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions -- base64_encode function is used for http encode compatible..
		$send_password = isset( $_POST['send_password'] ) ? sanitize_text_field( wp_unslash( $_POST['send_password'] ) ) : '';
		if ( isset( $new_user['role'] ) ) {
			if ( ! get_role( $new_user['role'] ) ) {
				$new_user['role'] = 'subscriber';
			}
		}

		$new_user_id = wp_insert_user( $new_user );

		if ( is_wp_error( $new_user_id ) ) {
			MainWP_Helper::instance()->error( $new_user_id->get_error_message() );
		}
		if ( 0 === $new_user_id ) {
			MainWP_Helper::instance()->error( esc_html__( 'Undefined error!', 'mainwp-child' ) );
		}

		if ( $send_password ) {
			$user = new \WP_User( $new_user_id );

			$user_login = stripslashes( $user->user_login );
			$user_email = stripslashes( $user->user_email );

			// The blogname option is escaped with esc_html on the way into the database in sanitize_option
			// we want to reverse this for the plain text arena of emails.
			$blogname = wp_specialchars_decode( get_option( 'blogname' ), ENT_QUOTES );

			$message  = sprintf( esc_html__( 'Username: %s' ), $user_login ) . "\r\n";
			$message .= sprintf( esc_html__( 'Password: %s' ), $new_user['user_pass'] ) . "\r\n";
			$message .= wp_login_url() . "\r\n";

			MainWP_Utility::instance()->send_wp_mail( $user_email, sprintf( esc_html__( '[%s] Your username and password' ), $blogname ), $message );
		}
		$information['added'] = true;
		MainWP_Helper::write( $information );
	}

}

