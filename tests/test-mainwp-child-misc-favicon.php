<?php
/**
 * Favicon page-parse regressions.
 *
 * @package MainWP_Child
 */

namespace MainWP\Child;

use WP_UnitTestCase;

class Test_MainWP_Child_Misc_Favicon extends WP_UnitTestCase {

    public function test_page_parse_fetch_uses_short_timeout() {
        if ( file_exists( ABSPATH . 'favicon.ico' ) || file_exists( ABSPATH . 'favicon.png' ) ) {
            $this->markTestSkipped( 'The test WordPress root ships a favicon file, so the page is never parsed.' );
        }
        delete_option( 'site_icon' );

        $requests = array();
        $capture  = function ( $preempt, $args, $url ) use ( &$requests ) {
            $requests[] = array(
                'url'     => $url,
                'timeout' => $args['timeout'],
            );
            return array(
                'headers'  => array(),
                'body'     => '<html><head><link rel="icon" href="https://example.org/icon.png"></head></html>',
                'response' => array(
                    'code'    => 200,
                    'message' => 'OK',
                ),
                'cookies'  => array(),
            );
        };
        add_filter( 'pre_http_request', $capture, 10, 3 );

        try {
            $favicon = MainWP_Child_Misc::get_instance()->get_favicon( true );
        } finally {
            remove_filter( 'pre_http_request', $capture, 10 );
        }

        $this->assertSame( 'https://example.org/icon.png', $favicon );
        $this->assertCount( 1, $requests );
        $this->assertSame( trailingslashit( get_option( 'siteurl' ) ), $requests[0]['url'] );
        $this->assertSame( 15, $requests[0]['timeout'] );
    }
}
