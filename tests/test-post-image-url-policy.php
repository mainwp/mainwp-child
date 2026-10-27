<?php
/**
 * Post image downloads through the WordPress HTTP API.
 *
 * @package Mainwp_Child
 */

use MainWP\Child\MainWP_Child_Keys_Manager;
use MainWP\Child\MainWP_Custom_Post_Type;
use MainWP\Child\MainWP_Exception;
use MainWP\Child\MainWP_Utility;

class PostImageUrlPolicyTest extends WP_UnitTestCase {
    private const DASHBOARD_HOST = '192.168.1.20';
    private const OTHER_LOCAL_HOST = '127.0.0.1';
    private const PUBLIC_HOST = '8.8.8.8';
    private const OTHER_10_HOST = '10.2.3.4';
    private const OTHER_172_HOST = '172.16.2.3';
    private const OTHER_192_HOST = '192.168.2.3';
    private const OTHER_LINK_LOCAL_HOST = '169.254.169.254';
    private const LOCAL_NAME = 'localhost';
    private const DASHBOARD_LOCAL_NAME = 'dashboard.local';
    private const DASHBOARD_TEST_NAME = 'dashboard.test';
    private const OTHER_IPV6_HOST = '[::1]';
    private $requests = array();
    private $arguments = array();
    private $redirect;
    private $original_post;
    private $original_home;
    private $attachments;

    public function set_up() {
        parent::set_up();
        $this->original_post = $_POST;
        $this->original_home = get_option( 'home' );
        update_option( 'home', 'http://child.example' );
        MainWP_Child_Keys_Manager::update_encrypted_option( 'mainwp_child_server', 'http://' . self::DASHBOARD_HOST . ':8888/wp-admin/' );
        $_POST['wpadmin_user']   = 'fixture-user';
        $_POST['wpadmin_passwd'] = 'fixture-password';
        $_POST['sslVerify']      = '0';
        $this->attachments = get_posts( array( 'post_type' => 'attachment', 'fields' => 'ids', 'posts_per_page' => -1 ) );
        add_filter( 'http_request_args', array( $this, 'capture_arguments' ), 999, 2 );
        add_filter( 'pre_http_request', array( $this, 'image_response' ), 10, 3 );
    }

    public function tear_down() {
        remove_filter( 'http_request_args', array( $this, 'capture_arguments' ), 999 );
        remove_filter( 'pre_http_request', array( $this, 'image_response' ), 10 );
        foreach ( get_posts( array( 'post_type' => 'attachment', 'fields' => 'ids', 'posts_per_page' => -1 ) ) as $id ) {
            if ( ! in_array( $id, $this->attachments, true ) ) {
                wp_delete_attachment( $id, true );
            }
        }
        foreach ( $this->arguments as $request ) {
            if ( ! empty( $request['args']['filename'] ) && is_file( $request['args']['filename'] ) ) {
                unlink( $request['args']['filename'] );
            }
        }
        $_POST = $this->original_post;
        update_option( 'home', $this->original_home );
        parent::tear_down();
    }

    public function capture_arguments( $args, $url ) {
        $this->arguments[] = array( 'url' => $url, 'args' => $args );
        return $args;
    }

    public function image_response( $pre, $args, $url ) {
        if ( $args['reject_unsafe_urls'] && ! wp_http_validate_url( $url ) ) {
            return new WP_Error( 'http_request_failed', 'Default WordPress URL validation declined the image.' );
        }
        $headers = $args['headers'];
        $this->requests[] = array( 'url' => $url, 'headers' => $headers );
        if ( null !== $this->redirect ) {
            try {
                if ( $args['reject_unsafe_urls'] ) {
                    WP_Http::validate_redirects( $this->redirect );
                }
                $target = $this->redirect;
                do_action_ref_array( 'requests-requests.before_redirect', array( $target, &$headers ) );
                $this->requests[] = array( 'url' => $target, 'headers' => $headers );
            } catch ( \WpOrg\Requests\Exception $e ) {
                return new WP_Error( 'http_request_failed', $e->getMessage() );
            }
        }
        $png = base64_decode( 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+aO1sAAAAASUVORK5CYII=' );
        file_put_contents( $args['filename'], $png );
        return array( 'headers' => array( 'content-type' => 'image/png' ), 'body' => '', 'response' => array( 'code' => 200, 'message' => 'OK' ), 'cookies' => array(), 'filename' => $args['filename'] );
    }

    public static function private_urls() {
        return array(
            'loopback' => array( 'http://' . self::OTHER_LOCAL_HOST . '/mwp-2024.png' ),
            'private10' => array( 'http://' . self::OTHER_10_HOST . '/mwp-2024.png' ),
            'private172' => array( 'http://' . self::OTHER_172_HOST . '/mwp-2024.png' ),
            'private192' => array( 'http://' . self::OTHER_192_HOST . '/mwp-2024.png' ),
            'link-local' => array( 'http://' . self::OTHER_LINK_LOCAL_HOST . '/mwp-2024.png' ),
            'localhost' => array( 'http://' . self::LOCAL_NAME . '/mwp-2024.png' ),
            'private-port' => array( 'http://' . self::OTHER_LOCAL_HOST . ':8888/mwp-2024.png' ),
            'dashboard-wrong-port' => array( 'http://' . self::DASHBOARD_HOST . ':8889/mwp-2024.png' ),
            'userinfo' => array( 'http://fixture@' . self::DASHBOARD_HOST . ':8888/mwp-2024.png' ),
            'ipv6' => array( 'http://' . self::OTHER_IPV6_HOST . '/mwp-2024.png' ),
        );
    }

    /** @dataProvider private_urls */
    public function test_non_dashboard_addresses_follow_default_wordpress_validation( $url ) {
        $this->assert_refused( $url, 0 );
    }

    private function assert_refused( $url, $transport_count ) {
        $upload = wp_upload_dir();
        $before = glob( $upload['path'] . '/*' );
        $attachments_before = get_posts( array( 'post_type' => 'attachment', 'fields' => 'ids', 'posts_per_page' => -1 ) );
        $hooks  = $this->download_hooks();
        $caught = false;
        try {
            MainWP_Utility::upload_image( $url );
        } catch ( MainWP_Exception $e ) {
            $caught = true;
        }
        $this->assertTrue( $caught, 'A refused image must use MainWP_Exception.' );
        $this->assertCount( $transport_count, $this->requests, 'No refused target may reach transport.' );
        $this->assertSame( $before, glob( $upload['path'] . '/*' ), 'Uploads must remain unchanged.' );
        $this->assertSame( $attachments_before, get_posts( array( 'post_type' => 'attachment', 'fields' => 'ids', 'posts_per_page' => -1 ) ) );
        $this->assertSame( $hooks, $this->download_hooks(), 'Download filters must be removed.' );
    }

    public static function custom_import_cases() {
        $cases = array();
        foreach ( array( 'import_custom_post', 'import_custom_post_v2' ) as $method ) {
            foreach ( self::private_urls() as $name => $case ) {
                $cases[ $method . '-' . $name ] = array( $method, $case[0] );
            }
        }
        return $cases;
    }

    /** @dataProvider custom_import_cases */
    public function test_custom_import_keeps_post_and_uses_default_image_validation( $method, $url, $imports = false ) {
        register_post_type( 'mwp_image_policy', array( 'public' => true, 'supports' => array( 'title', 'editor', 'thumbnail' ) ) );
        $post = array_fill_keys( array( 'post_date', 'post_date_gmt', 'post_content', 'post_title', 'post_excerpt', 'post_status', 'comment_status', 'ping_status', 'post_password', 'post_name', 'to_ping', 'pinged', 'post_modified', 'post_modified_gmt', 'post_content_filtered', 'post_type' ), '' );
        $post['post_type']    = 'mwp_image_policy';
        $post['post_status']  = 'publish';
        $post['post_title']   = 'Image policy fixture';
        $post['post_content'] = 'A normal custom post.';
        $post['menu_order']   = 0;
        $_POST['data'] = wp_slash( array(
            'post' => $post,
            'postmeta' => array( array( 'meta_key' => '_thumbnail_id', 'meta_value' => '1' ) ),
            'terms' => array(),
            'extras' => array( 'upload_dir' => array( 'baseurl' => 'http://' . self::DASHBOARD_HOST . ':8888/wp-content/uploads' ), 'featured_image' => $url ),
            'categories' => array(),
            'post_only_existing' => 0,
        ) );
        unset( $_POST['post_id'] );
        $upload = wp_upload_dir();
        $before = glob( $upload['path'] . '/*' );
        $invoke = new ReflectionMethod( MainWP_Custom_Post_Type::class, $method );
        $invoke->setAccessible( true );
        try {
            $invoke->invoke( MainWP_Custom_Post_Type::instance() );
            $posts = get_posts( array( 'post_type' => 'mwp_image_policy', 'post_status' => 'publish', 'posts_per_page' => -1 ) );
            $this->assertCount( 1, $posts, 'The custom post must still publish.' );
            $thumbnail = (int) get_post_thumbnail_id( $posts[0]->ID );
            if ( $imports ) {
                $this->assertGreaterThan( 0, $thumbnail );
                $this->assertCount( 1, $this->requests );
                $this->assertFileExists( get_attached_file( $thumbnail ) );
                $this->assertCount( count( $this->attachments ) + 1, get_posts( array( 'post_type' => 'attachment', 'fields' => 'ids', 'posts_per_page' => -1 ) ) );
            } else {
                $this->assertSame( 0, $thumbnail );
                $this->assertCount( 0, $this->requests, 'The image must follow default WordPress validation.' );
                $this->assertSame( $before, glob( $upload['path'] . '/*' ) );
                $this->assertSame( $this->attachments, get_posts( array( 'post_type' => 'attachment', 'fields' => 'ids', 'posts_per_page' => -1 ) ) );
            }
            $this->assertNotFalse( has_filter( 'http_request_host_is_external', '__return_true' ), 'The caller filter remains outside the download.' );
        } finally {
            remove_filter( 'http_request_host_is_external', '__return_true' );
            unregister_post_type( 'mwp_image_policy' );
        }
    }

    public static function custom_import_compatible_cases() {
        return array(
            'legacy-dashboard' => array( 'import_custom_post', 'http://' . self::DASHBOARD_HOST . ':8888/mwp-2024.png' ),
            'legacy-external' => array( 'import_custom_post', 'http://' . self::PUBLIC_HOST . ':8888/mwp-2024.png' ),
            'v2-dashboard' => array( 'import_custom_post_v2', 'http://' . self::DASHBOARD_HOST . ':8888/mwp-2024.png' ),
            'v2-external' => array( 'import_custom_post_v2', 'http://' . self::PUBLIC_HOST . ':8888/mwp-2024.png' ),
        );
    }

    /** @dataProvider custom_import_compatible_cases */
    public function test_custom_import_keeps_dashboard_and_external_images( $method, $url ) {
        $this->test_custom_import_keeps_post_and_uses_default_image_validation( $method, $url, true );
    }

    public static function dashboard_urls() {
        return array(
            'ipv6-dashboard' => array( 'http://' . self::OTHER_IPV6_HOST . '/wp-admin/', 'http://' . self::OTHER_IPV6_HOST . '/mwp-2024.png' ),
            'private-custom-port' => array( 'http://' . self::DASHBOARD_HOST . ':8888/wp-admin/', 'http://' . self::DASHBOARD_HOST . ':8888/mwp-2024.png' ),
            'force-ssl-admin' => array( 'https://' . self::DASHBOARD_HOST . '/wp-admin/', 'http://' . self::DASHBOARD_HOST . '/mwp-2024.png' ),
            'force-ssl-explicit-admin-default' => array( 'https://' . self::DASHBOARD_HOST . ':443/wp-admin/', 'http://' . self::DASHBOARD_HOST . '/mwp-2024.png' ),
            'force-ssl-explicit-media-default' => array( 'https://' . self::DASHBOARD_HOST . '/wp-admin/', 'http://' . self::DASHBOARD_HOST . ':80/mwp-2024.png' ),
            'force-ssl-explicit-both-defaults' => array( 'https://' . self::DASHBOARD_HOST . ':443/wp-admin/', 'http://' . self::DASHBOARD_HOST . ':80/mwp-2024.png' ),
            'force-ssl-custom-port' => array( 'https://' . self::DASHBOARD_HOST . ':8888/wp-admin/', 'http://' . self::DASHBOARD_HOST . ':8888/mwp-2024.png' ),
            'localhost' => array( 'http://' . self::LOCAL_NAME . '/wp-admin/', 'http://' . self::LOCAL_NAME . '/mwp-2024.png' ),
            'case' => array( 'http://LOCALHOST/wp-admin/', 'http://' . self::LOCAL_NAME . '/mwp-2024.png' ),
            'local-name' => array( 'http://' . self::DASHBOARD_LOCAL_NAME . '/wp-admin/', 'http://' . self::DASHBOARD_LOCAL_NAME . '/mwp-2024.png' ),
            'test-name' => array( 'https://' . self::DASHBOARD_TEST_NAME . '/wp-admin/', 'http://' . self::DASHBOARD_TEST_NAME . '/mwp-2024.png' ),
        );
    }

    /** @dataProvider dashboard_urls */
    public function test_dashboard_images_keep_existing_arguments( $dashboard, $url ) {
        MainWP_Child_Keys_Manager::update_encrypted_option( 'mainwp_child_server', $dashboard );
        $hooks  = $this->download_hooks();
        $result = MainWP_Utility::upload_image( $url );
        $this->assertGreaterThan( 0, $result['id'] );
        $this->assertFileExists( get_attached_file( $result['id'] ) );
        $this->assertFalse( $this->arguments[0]['args']['reject_unsafe_urls'] );
        $this->assertSame( 'Basic ' . base64_encode( 'fixture-user:fixture-password' ), $this->requests[0]['headers']['Authorization'] );
        $this->assertFalse( $this->arguments[0]['args']['sslverify'] );
        $this->assertSame( $hooks, $this->download_hooks() );
    }

    public function test_dashboard_import_without_basic_credentials() {
        unset( $_POST['wpadmin_user'], $_POST['wpadmin_passwd'] );
        $result = MainWP_Utility::upload_image( 'http://' . self::DASHBOARD_HOST . ':8888/mwp-2024.png' );
        $this->assertGreaterThan( 0, $result['id'] );
        $this->assertArrayNotHasKey( 'Authorization', $this->requests[0]['headers'] );
        $this->assertFalse( $this->arguments[0]['args']['reject_unsafe_urls'] );
    }

    public static function public_urls() {
        return array( array( 'https://' . self::PUBLIC_HOST . ':443/mwp-2024.png' ), array( 'http://' . self::PUBLIC_HOST . ':8888/mwp-2024.png' ) );
    }

    /** @dataProvider public_urls */
    public function test_public_images_keep_importing_without_dashboard_credentials( $url ) {
        $hooks  = $this->download_hooks();
        $result = MainWP_Utility::upload_image( $url );
        $this->assertGreaterThan( 0, $result['id'] );
        $this->assertTrue( $this->arguments[0]['args']['reject_unsafe_urls'] );
        $this->assertArrayNotHasKey( 'Authorization', $this->requests[0]['headers'] );
        $this->assertFalse( $this->arguments[0]['args']['sslverify'] );
        $this->assertSame( $hooks, $this->download_hooks() );
    }

    public static function unknown_dashboards() {
        return array( array( '' ), array( 'not a URL' ), array( 'https:///missing-host' ) );
    }

    /** @dataProvider unknown_dashboards */
    public function test_unknown_dashboard_uses_default_wordpress_validation( $dashboard ) {
        MainWP_Child_Keys_Manager::update_encrypted_option( 'mainwp_child_server', $dashboard );
        $this->assert_refused( 'http://' . self::OTHER_LOCAL_HOST . '/mwp-2024.png', 0 );
    }

    public function test_external_redirect_target_follows_default_wordpress_validation() {
        $this->redirect = 'http://' . self::OTHER_LINK_LOCAL_HOST . '/mwp-2024.png';
        $this->assert_refused( 'https://' . self::PUBLIC_HOST . '/mwp-2024.png', 1 );
    }

    public function test_dashboard_redirect_target_follows_default_wordpress_validation() {
        $this->redirect = 'http://' . self::OTHER_LOCAL_HOST . '/mwp-2024.png';
        $this->assert_refused( 'http://' . self::DASHBOARD_HOST . ':8888/mwp-2024.png', 1 );
    }

    public function test_dashboard_redirect_to_public_host_drops_basic_header() {
        $this->redirect = 'http://' . self::PUBLIC_HOST . ':8888/mwp-2024.png';
        $result = MainWP_Utility::upload_image( 'http://' . self::DASHBOARD_HOST . ':8888/mwp-2024.png' );
        $this->assertGreaterThan( 0, $result['id'] );
        $this->assertCount( 2, $this->requests );
        $this->assertArrayNotHasKey( 'Authorization', $this->requests[1]['headers'] );
    }

    public function test_external_redirect_to_dashboard_keeps_dashboard_credentials() {
        $this->redirect = 'http://' . self::DASHBOARD_HOST . ':8888/mwp-2024.png';
        $result = MainWP_Utility::upload_image( 'https://' . self::PUBLIC_HOST . '/mwp-2024.png' );
        $this->assertGreaterThan( 0, $result['id'] );
        $this->assertArrayNotHasKey( 'Authorization', $this->requests[0]['headers'] );
        $this->assertSame( 'Basic ' . base64_encode( 'fixture-user:fixture-password' ), $this->requests[1]['headers']['Authorization'] );
    }

    public function test_external_redirect_keeps_external_custom_port() {
        $this->redirect = 'http://' . self::PUBLIC_HOST . ':8889/mwp-2024.png';
        $result = MainWP_Utility::upload_image( 'https://' . self::PUBLIC_HOST . '/mwp-2024.png' );
        $this->assertGreaterThan( 0, $result['id'] );
        $this->assertCount( 2, $this->requests );
        $this->assertArrayNotHasKey( 'Authorization', $this->requests[1]['headers'] );
    }

    public function test_child_host_keeps_core_port_rules() {
        update_option( 'home', 'http://' . self::OTHER_LOCAL_HOST . '' );
        $result = MainWP_Utility::upload_image( 'http://' . self::OTHER_LOCAL_HOST . '/mwp-2024.png' );
        $this->assertGreaterThan( 0, $result['id'] );
        $this->requests = array();
        $this->assert_refused( 'http://' . self::OTHER_LOCAL_HOST . ':8888/mwp-2024.png', 0 );
    }

    public function test_filters_are_removed_when_http_hook_throws() {
        $thrower = function () { throw new RuntimeException( 'Fixture exception' ); };
        add_filter( 'pre_http_request', $thrower );
        $hooks = $this->download_hooks();
        try {
            MainWP_Utility::upload_image( 'https://' . self::PUBLIC_HOST . '/mwp-2024.png' );
            $this->fail( 'The fixture exception must propagate.' );
        } catch ( RuntimeException $e ) {
            $this->assertSame( 'Fixture exception', $e->getMessage() );
        } finally {
            remove_filter( 'pre_http_request', $thrower );
        }
        $this->assertSame( $hooks, $this->download_hooks() );
    }

    private function download_hooks() {
        global $wp_filter;
        $hooks = array();
        foreach ( array( 'http_request_args', 'http_allowed_safe_ports', 'http_request_host_is_external', 'requests-requests.before_redirect' ) as $name ) {
            $hooks[ $name ] = isset( $wp_filter[ $name ] ) ? $wp_filter[ $name ]->callbacks : array();
        }
        return $hooks;
    }
}
