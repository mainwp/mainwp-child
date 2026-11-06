<?php
/**
 * Password changes use the original WordPress user data.
 *
 * @package MainWP_Child
 */

namespace MainWP\Child;

class Test_Child_Password_Tracker extends \WP_UnitTestCase {
    public function test_admin_password_does_not_load_deprecated_registration() {
        $method = new \ReflectionMethod( MainWP_Child_Users::class, 'new_admin_password' );
        $source = file( $method->getFileName() );
        $body = implode( '', array_slice( $source, $method->getStartLine() - 1, $method->getEndLine() - $method->getStartLine() + 1 ) );
        $this->assertStringNotContainsString( '/registration.php', $body );
    }

    public function test_update_user_tracks_password_without_transient() {
        $this->assert_password_update_tracked( false );
    }

    public function test_insert_user_update_tracks_password_without_transient() {
        $this->assert_password_update_tracked( true );
    }

    private function assert_password_update_tracked( $insert ) {
        MainWP_Child_Password_Tracker::get_instance();
        $id = self::factory()->user->create();
        delete_user_meta( $id, 'mainwp_last_password_change' );
        $data = array( 'ID' => $id, 'user_pass' => 'new-test-password' );
        if ( $insert ) {
            $data['user_login'] = get_userdata( $id )->user_login;
            $data['user_pass'] = wp_hash_password( $data['user_pass'] );
            $result = wp_insert_user( $data );
        } else {
            $result = wp_update_user( $data );
        }
        $this->assertSame( $id, $result );
        $this->assertNotEmpty( get_user_meta( $id, 'mainwp_last_password_change', true ) );
        $this->assertFalse( get_transient( 'mainwp_old_password_hash_' . $id ) );
        update_user_meta( $id, 'mainwp_last_password_change', 123 );
        wp_update_user( array( 'ID' => $id, 'display_name' => 'Changed name' ) );
        $this->assertSame( '123', get_user_meta( $id, 'mainwp_last_password_change', true ) );
        $this->assertFalse( get_transient( 'mainwp_old_password_hash_' . $id ) );
    }
}
