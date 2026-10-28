<?php
/**
 * Post image import move failures.
 *
 * @package Mainwp_Child
 */

use MainWP\Child\MainWP_Child_Posts;
use MainWP\Child\MainWP_Child_Keys_Manager;
use MainWP\Child\MainWP_Custom_Post_Type;

class PostImageUploadFailureTest extends WP_UnitTestCase {
    private $filesystem;
    private $upload;
    private $source;
    private $downloads = array();
    private $temporary = array();
    private $warnings = array();
    private $created_attachments = array();

    public function set_up() {
        parent::set_up();
        wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
        require_once ABSPATH . 'wp-admin/includes/file.php';
        $this->filesystem = $GLOBALS['wp_filesystem'] ?? null;
        WP_Filesystem();
        $direct = $GLOBALS['wp_filesystem'];
        $mock = $this->getMockBuilder( WP_Filesystem_Direct::class )->setConstructorArgs( array( null ) )->onlyMethods( array( 'move' ) )->getMock();
        $mock->method( 'move' )->willReturnCallback(
            static function ( $from, $to, $overwrite = false ) use ( $direct ) {
                return 'failed.png' === basename( $to ) ? false : $direct->move( $from, $to, $overwrite );
            }
        );
        $GLOBALS['wp_filesystem'] = $mock;
        $base = wp_upload_dir();
        $name = '/mwp-1990-' . wp_generate_uuid4();
        $this->upload = array_merge( $base, array( 'path' => $base['basedir'] . $name, 'url' => $base['baseurl'] . $name, 'subdir' => $name ) );
        wp_mkdir_p( $this->upload['path'] );
        add_filter( 'upload_dir', array( $this, 'uploads' ) );
        add_filter( 'pre_http_request', array( $this, 'download' ), PHP_INT_MAX, 3 );
        add_filter( 'pre_wp_mail', '__return_true' );
        add_action( 'add_attachment', array( $this, 'record_attachment' ) );
        MainWP_Child_Keys_Manager::update_encrypted_option( 'mainwp_child_server', 'https://dashboard.example.org' );
    }

    public function tear_down() {
        remove_filter( 'pre_http_request', array( $this, 'download' ), PHP_INT_MAX );
        remove_filter( 'pre_wp_mail', '__return_true' );
        remove_action( 'add_attachment', array( $this, 'record_attachment' ) );
        foreach ( $this->created_attachments as $id ) {
            wp_delete_attachment( $id, true );
        }
        foreach ( array_merge( $this->temporary, glob( $this->upload['path'] . '/*' ) ) as $file ) {
            if ( is_file( $file ) ) {
                unlink( $file );
            }
        }
        rmdir( $this->upload['path'] );
        remove_filter( 'upload_dir', array( $this, 'uploads' ) );
        $GLOBALS['wp_filesystem'] = $this->filesystem;
        parent::tear_down();
    }

    public function uploads() {
        return $this->upload;
    }

    public function record_attachment( $id ) {
        $this->created_attachments[] = $id;
    }

    public function download( $pre, $args, $url ) {
        if ( 0 !== strpos( $url, $this->source ) ) {
            return new WP_Error( 'unexpected_http', 'Unexpected image request.' );
        }
        $this->downloads[ $url ] = ( $this->downloads[ $url ] ?? 0 ) + 1;
        $this->temporary[] = $args['filename'];
        copy( DIR_TESTDATA . '/images/codeispoetry.png', $args['filename'] );
        return array( 'headers' => array(), 'body' => '', 'response' => array( 'code' => 200, 'message' => 'OK' ), 'cookies' => array() );
    }

    public static function paths() {
        $cases = array();
        foreach ( array( 'create', 'create-dashboard', 'advanced', 'escaped' ) as $path ) {
            foreach ( array( '2026/10', '2026.10' ) as $folder ) {
                $cases[ $path . '-' . $folder ] = array( $path, $folder );
            }
        }
        return $cases;
    }

    private function content( $path, $folder, $sibling = true ) {
        $this->source = ( 'create-dashboard' === $path ? 'https://dashboard.example.org' : get_site_url() ) . '/uploads/' . $folder . '/';
        $content = '<a href="' . $this->source . 'failed.png"><img src="' . $this->source . 'failed-300x200.png" srcset="' . $this->source . 'failed-1024x768.png 1024w" /></a><img src="' . $this->source . 'failed.png" />';
        if ( $sibling ) {
            $content .= '<img src="' . $this->source . 'success.png" />';
        }
        // Both advanced passes must share a failure discovered in ordinary image content.
        if ( 0 === strpos( $path, 'create' ) ) {
            $content .= '<!-- wp:image {"url":"' . str_replace( '/', '\/', $this->source . 'failed.png' ) . '"} -->';
        } elseif ( 'escaped' === $path ) {
            $content = str_replace( '/', '\/', $content );
        }
        if ( 'create-dashboard' === $path ) {
            $content .= '<!-- ' . get_site_url() . '/outside.png -->';
        }
        return $content;
    }

    private function import( $path, $content ) {
        set_error_handler(
            function ( $severity, $message ) {
                $this->warnings[] = $message;
                return true;
            },
            E_WARNING | E_NOTICE | E_DEPRECATED
        );
        try {
            if ( 0 === strpos( $path, 'create' ) ) {
                $method = new ReflectionMethod( MainWP_Child_Posts::class, 'create_post' );
                $method->setAccessible( true );
                $result = $method->invoke( MainWP_Child_Posts::get_instance(), array( 'post_title' => 'Image failure', 'post_type' => 'post', 'post_status' => 'draft', 'post_content' => $content ), array( '_mainwp_replace_advance_img' => array( 1 ) ), '', '', array( 'baseurl' => $this->source ), '' );
                $this->assertTrue( $result['success'] );
                return get_post( $result['added_id'] )->post_content;
            }
            return MainWP_Child_Posts::replace_advanced_image( $content, array( 'baseurl' => $this->source ), 'escaped' === $path );
        } finally {
            restore_error_handler();
        }
    }

    /** @dataProvider paths */
    public function test_failed_image_and_resized_references_stay_while_sibling_imports( $path, $folder ) {
        $content = $this->content( $path, $folder );
        $result = $this->import( $path, $content );
        $expected = 0 === strpos( $path, 'create' ) ? wp_unslash( $content ) : $content;
        $source = $this->source . 'success.png';
        if ( 'escaped' === $path ) {
            $source = str_replace( '/', '\/', $source );
        }
        $expected = str_replace( $source, $this->upload['url'] . '/success.png', $expected );
        $this->assertSame( $expected, $result );
        $this->assertFileExists( $this->upload['path'] . '/success.png' );
        $this->assertSame( file_get_contents( DIR_TESTDATA . '/images/codeispoetry.png' ), file_get_contents( $this->upload['path'] . '/success.png' ) );
        $this->assertSame( array( $this->source . 'failed.png' => 1, $this->source . 'success.png' => 1 ), $this->downloads );
        $this->assertSame( array(), $this->warnings );
    }

    /** @dataProvider paths */
    public function test_failed_download_is_remembered_until_next_import( $path, $folder ) {
        $content = $this->content( $path, $folder, false );
        $this->import( $path, $content );
        $this->assertSame( array( $this->source . 'failed.png' => 1 ), $this->downloads );
        $this->import( $path, $content );
        $this->assertSame( array( $this->source . 'failed.png' => 2 ), $this->downloads );
    }

    /** @dataProvider paths */
    public function test_failed_move_does_not_emit_php_warning( $path, $folder ) {
        $this->import( $path, $this->content( $path, $folder, false ) );
        $this->assertSame( array(), $this->warnings );
        $this->assertSame( array( $this->source . 'failed.png' => 1 ), $this->downloads );
    }

    public function test_legacy_custom_post_failed_move_keeps_original_url() {
        $this->source = 'https://dashboard.example.org/uploads/2026/10/';
        $content = '<img src="' . $this->source . 'failed.png" />';
        $method = new ReflectionMethod( MainWP_Custom_Post_Type::class, 'search_images' );
        $method->setAccessible( true );
        set_error_handler(
            function ( $severity, $message ) {
                $this->warnings[] = $message;
                return true;
            },
            E_WARNING | E_NOTICE | E_DEPRECATED
        );
        try {
            $result = $method->invoke( MainWP_Custom_Post_Type::instance(), $content, array( 'baseurl' => $this->source ) );
        } finally {
            restore_error_handler();
        }
        $this->assertSame( $content, $result );
        $this->assertSame( array(), $this->warnings );
        $this->assertSame( array( $this->source . 'failed.png' => 1 ), $this->downloads );
    }

    public function test_public_advanced_signature_still_has_three_parameters() {
        $method = new ReflectionMethod( MainWP_Child_Posts::class, 'replace_advanced_image' );
        $this->assertSame( 3, $method->getNumberOfParameters() );
        $this->assertSame( 'content', MainWP_Child_Posts::replace_advanced_image( 'content', array(), true ) );
    }
}
