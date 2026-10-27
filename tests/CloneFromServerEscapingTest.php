<?php
/**
 * Restore/Clone directory browser output tests.
 *
 * @package Mainwp_Child
 */

use MainWP\Child\MainWP_Clone;
use MainWP\Child\MainWP_Clone_Page;

// phpcs:disable WordPress.WP.AlternativeFunctions -- Direct operations create and remove isolated filesystem fixtures.
// phpcs:disable WordPress.Security.NonceVerification -- Tests supply request values directly.

/**
 * Render the real section with filesystem names that need HTML escaping.
 */
class CloneFromServerEscapingTest extends WP_UnitTestCase {

    /**
     * Temporary fixture tree.
     *
     * @var string
     */
    private $scratch;

    /**
     * Original request.
     *
     * @var array
     */
    private $request;

    /**
     * Original filesystem global, including its presence.
     *
     * @var array
     */
    private $filesystem;

    /**
     * Original options, including their presence.
     *
     * @var array
     */
    private $options = array();

    /**
     * Isolate uploads, backup metadata and request state.
     */
    public function set_up() {
        parent::set_up();
        $this->request    = $_REQUEST;
        $this->filesystem = array( array_key_exists( 'wp_filesystem', $GLOBALS ), $GLOBALS['wp_filesystem'] ?? null );
        $this->scratch    = sys_get_temp_dir() . '/mwp-clone-output-' . wp_generate_uuid4();
        $this->assertTrue( mkdir( $this->scratch, 0700 ) );

        foreach ( array( 'mainwp_backup_dir_hash', 'mainwp_child_clone_from_server_last_folder' ) as $name ) {
            $missing                = new stdClass();
            $value                  = get_option( $name, $missing );
            $this->options[ $name ] = array( $value !== $missing, $value );
        }
        update_option( 'mainwp_backup_dir_hash', 'FixtureBackup123' );
        add_filter( 'upload_dir', array( $this, 'fixture_uploads' ) );
        require_once ABSPATH . 'wp-admin/includes/file.php';
        $this->assertTrue( WP_Filesystem() );
    }

    /**
     * Restore state even after a failed render assertion.
     */
    public function tear_down() {
        remove_filter( 'upload_dir', array( $this, 'fixture_uploads' ) );
        $_REQUEST = $this->request;
        if ( $this->filesystem[0] ) {
            $GLOBALS['wp_filesystem'] = $this->filesystem[1];
        } else {
            unset( $GLOBALS['wp_filesystem'] );
        }
        foreach ( $this->options as $name => $original ) {
            if ( $original[0] ) {
                update_option( $name, $original[1] );
            } else {
                delete_option( $name );
            }
            wp_cache_delete( $name, 'mainwp' );
        }
        $this->remove_tree( $this->scratch );
        parent::tear_down();
    }

    /**
     * Keep the real helper's backup files inside the fixture tree.
     *
     * @param array $uploads WordPress upload paths.
     * @return array
     */
    public function fixture_uploads( $uploads ) {
        $uploads['basedir'] = $this->scratch . '/uploads-<b>Bob\'s "folder" & notes';
        $uploads['path']    = $uploads['basedir'];
        $uploads['baseurl'] = 'http://example.org/fixture-uploads';
        $uploads['url']     = $uploads['baseurl'];
        $uploads['subdir']  = '';
        $uploads['error']   = false;
        return $uploads;
    }

    /**
     * Literal expectations distinguish changed output from entity compatibility.
     *
     * @return array
     */
    public static function breadcrumb_names() {
        return array(
            'regression: markup and both quotes' => array( '<b>Bob\'s "folder"', '&lt;b&gt;Bob&#039;s &quot;folder&quot;/', '<b>Bob\'s "folder"/' ),
            'regression: bare ampersand'         => array( 'Research & notes', 'Research &amp; notes/', 'Research & notes/' ),
            'guard: existing entity'             => array( 'Research &amp; notes', 'Research &amp; notes/', 'Research & notes/' ),
        );
    }

    /**
     * Breadcrumbs display names as text and encode ampersands once.
     *
     * @dataProvider breadcrumb_names
     * @param string $name Directory name.
     * @param string $escaped Expected raw anchor content.
     * @param string $text Expected browser text.
     */
    public function test_breadcrumb_name_is_text( $name, $escaped, $text ) {
        $path = $this->scratch . '/' . $name;
        $this->assertTrue( mkdir( $path, 0700 ) );
        $html  = $this->render_section( $path );
        $xpath = $this->parse_html( $html );
        $links = $this->breadcrumbs( $xpath );
        $this->assertGreaterThan( 0, $links->length );
        $link = $links->item( $links->length - 1 );
        $this->assertSame( $text, $link->textContent );
        $this->assertSame( 0, $link->getElementsByTagName( '*' )->length, 'A folder name must not add an element.' );
        $this->assertStringContainsString( '>' . $escaped . '</a>', $html );
        $this->assertStringNotContainsString( 'Research &amp;amp; notes/', $html );
    }

    /**
     * Guard: every normal breadcrumb still opens its own directory.
     */
    public function test_guard_normal_breadcrumb_destinations() {
        $path = $this->scratch . '/normal/leaf';
        $this->assertTrue( mkdir( $path, 0700, true ) );
        $links    = $this->breadcrumbs( $this->parse_html( $this->render_section( $path ) ) );
        $expected = array();
        for ( $parent = $path; '/' !== $parent; $parent = dirname( $parent ) ) {
            array_unshift( $expected, $parent );
        }
        $this->assertSame( count( $expected ), $links->length );
        foreach ( $expected as $index => $target ) {
            $link = $links->item( $index );
            $this->assertSame( basename( $target ) . '/', $link->textContent );
            $this->assert_directory_link( $link, ltrim( $target, '/' ) );
        }
    }

    /**
     * Guard: directories, accepted archives and rejected files stay text.
     */
    public function test_guard_file_list_names_are_escaped() {
        $folder   = '<b>Bob\'s "folder" & notes';
        $archive  = '<b>Bob\'s "archive" & notes.zip';
        $rejected = '<b>Bob\'s "file" & notes.txt';
        $path     = $this->scratch . '/listing';
        $this->assertTrue( mkdir( $path . '/' . $folder, 0700, true ) );
        $this->assertSame( 7, file_put_contents( $path . '/' . $archive, 'fixture' ) );
        $this->assertSame( 7, file_put_contents( $path . '/' . $rejected, 'fixture' ) );
        $this->assertSame( 1, MainWP_Clone::is_archive( $archive ) );
        $this->assertSame( 0, MainWP_Clone::is_archive( $rejected ) );

        $html  = $this->render_section( $path );
        $xpath = $this->parse_html( $html );
        $list  = $xpath->query( '//*[@id="mainwp-child_clonesite_select_site"]' )->item( 0 );
        $this->assertNotNull( $list );
        $this->assertSame( 0, $list->getElementsByTagName( 'b' )->length );
        $folders = $xpath->query( './/a', $list );
        $this->assertSame( 2, $folders->length );
        $this->assertSame( $folder . '/', $folders->item( 1 )->textContent );
        $this->assert_directory_link( $folders->item( 1 ), $folder );
        $accepted = $xpath->query( './/div[@class="clonesite_select_site_item"]//span', $list );
        $this->assertSame( 1, $accepted->length );
        $this->assertSame( $archive, $accepted->item( 0 )->textContent );
        $denied = $xpath->query( './/div[@class="mainwp_rejected_files"]//span', $list );
        $this->assertSame( 1, $denied->length );
        $this->assertSame( $rejected, $denied->item( 0 )->textContent );
        foreach ( array( 'folder', 'archive', 'file' ) as $label ) {
            $this->assertStringContainsString( '&lt;b&gt;Bob&#039;s &quot;' . $label . '&quot; &amp; notes', $html );
        }
    }

    /**
     * Guard: paths cannot break the parent title, hidden value or Quick Jump.
     */
    public function test_guard_path_attributes_and_quick_jump() {
        $parent = $this->scratch . '/<b>Bob\'s "parent" & notes';
        $path   = $parent . '/leaf';
        $this->assertTrue( mkdir( $path, 0700, true ) );
        $html  = $this->render_section( $path );
        $xpath = $this->parse_html( $html );
        $up    = $xpath->query( '//a[.="Parent Folder"]' );
        $this->assertSame( 1, $up->length );
        $this->assertSame( $parent, $up->item( 0 )->getAttribute( 'title' ) );
        $this->assertSame( 2, $up->item( 0 )->attributes->length );
        $this->assert_directory_link( $up->item( 0 ), $parent );
        $hidden = $xpath->query( '//input[@id="clonesite_from_server_current_dir"]' );
        $this->assertSame( 1, $hidden->length );
        $this->assertSame( $path, $hidden->item( 0 )->getAttribute( 'value' ) );
        $this->assertSame( 3, $hidden->item( 0 )->attributes->length );
        $this->assertStringContainsString( 'title="' . $this->scratch . '/&lt;b&gt;Bob&#039;s &quot;parent&quot; &amp; notes"', $html );
        $this->assertStringContainsString( 'value="' . $this->scratch . '/&lt;b&gt;Bob&#039;s &quot;parent&quot; &amp; notes/leaf"', $html );

        $quick = $xpath->query( '//div[strong="Quick Jump:"]/a' );
        $this->assertSame( 4, $quick->length );
        $uploads  = $this->fixture_uploads( array() );
        $expected = array(
            'Site Root'      => ABSPATH,
            'Backup'         => $uploads['basedir'] . '/mainwp/backup-FixtureBackup123/',
            'Uploads Folder' => $uploads['path'],
            'Content Folder' => WP_CONTENT_DIR,
        );
        foreach ( $quick as $link ) {
            $this->assertArrayHasKey( $link->textContent, $expected );
            $this->assertSame( 1, $link->attributes->length );
            $this->assertSame( 0, $link->getElementsByTagName( '*' )->length );
            $this->assert_directory_link( $link, ltrim( strtolower( str_replace( '\\', '/', $expected[ $link->textContent ] ) ), '/' ) );
            unset( $expected[ $link->textContent ] );
        }
        $this->assertSame( array(), $expected );
    }

    /**
     * Render only the From Server section through its real entry point.
     *
     * @param string $path Existing readable directory.
     * @return string
     */
    private function render_section( $path ) {
        $_REQUEST = array(
            'page' => 'mainwp_child_tab',
            'dir'  => wp_slash( $path ),
        );
        $method   = new ReflectionMethod( MainWP_Clone_Page::class, 'render_clone_from_server' );
        $method->setAccessible( true );
        ob_start();
        try {
            $method->invoke( null );
            return ob_get_contents();
        } finally {
            ob_end_clean();
        }
    }

    /**
     * Parse rendered HTML without changing libxml's error mode.
     *
     * @param string $html Rendered section.
     * @return DOMXPath
     */
    private function parse_html( $html ) {
        $previous = libxml_use_internal_errors( true );
        try {
            $dom = new DOMDocument();
            $this->assertTrue( $dom->loadHTML( '<!doctype html><html><body>' . $html . '</body></html>' ) );
            return new DOMXPath( $dom );
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors( $previous );
        }
    }

    /**
     * Locate breadcrumb anchors separately from the file list and Quick Jump.
     *
     * @param DOMXPath $xpath Rendered document.
     * @return DOMNodeList
     */
    private function breadcrumbs( $xpath ) {
        return $xpath->query( '//div[strong="Current Directory:"]/span/a' );
    }

    /**
     * Check navigation after the browser decodes the HTML and query string.
     *
     * @param DOMElement $link Rendered anchor.
     * @param string     $directory Expected dir query value.
     */
    private function assert_directory_link( $link, $directory ) {
        $url = wp_parse_url( $link->getAttribute( 'href' ) );
        parse_str( $url['query'], $query );
        $this->assertSame( wp_parse_url( admin_url( 'options-general.php' ), PHP_URL_PATH ), $url['path'] );
        $this->assertSame( 'mainwp_child_tab', $query['page'] );
        $this->assertSame( 'restore-clone', $query['tab'] );
        $this->assertSame( $directory, $query['dir'] );
        $this->assertSame( 'title_03', $url['fragment'] );
    }

    /**
     * Remove only this test's temporary tree, including helper-created files.
     *
     * @param string|null $path Fixture path.
     */
    private function remove_tree( $path ) {
        if ( empty( $path ) || ! file_exists( $path ) ) {
            return;
        }
        if ( is_dir( $path ) ) {
            foreach ( array_diff( scandir( $path ), array( '.', '..' ) ) as $entry ) {
                $this->remove_tree( $path . '/' . $entry );
            }
            rmdir( $path );
        } else {
            unlink( $path );
        }
    }
}
