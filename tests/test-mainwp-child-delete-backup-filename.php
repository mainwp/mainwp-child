<?php
/**
 * Backup deletion must preserve files outside the legacy archive contract.
 *
 * @package MainWP_Child
 */

namespace MainWP\Child;

use WP_UnitTestCase;

/**
 * An isolated upload root keeps refusal tests away from site files.
 */
class Test_MainWP_Child_Delete_Backup_Filename extends WP_UnitTestCase {

    /**
     * Disposable uploads remain inside this worktree.
     *
     * @var string
     */
    private $fixture_root;

    /**
     * Explicit paths preserve unusual basenames during cleanup.
     *
     * @var array
     */
    private $fixture_files = array();

    /**
     * The real helper supplies the directory boundary under test.
     *
     * @var string
     */
    private $backupdir;

    /**
     * Other tests must retain their request state.
     *
     * @var array
     */
    private $original_request;

    /**
     * Capturing the reply keeps PHPUnit alive across the terminal write.
     *
     * @var \Closure
     */
    private $delete_backup;

    /**
     * Each case needs fresh protected files, including after a failing deletion.
     */
    public function setUp(): void {
        parent::setUp();
        $this->original_request = $GLOBALS['_REQUEST'];
        $this->fixture_root     = __DIR__ . '/delete-backup-fixtures-' . wp_generate_uuid4();
        $this->assertTrue( wp_mkdir_p( $this->fixture_root ) );
        add_filter( 'mainwp_child_get_wp_upload_dir', array( $this, 'isolate_upload_dir' ) );
        $dirs                = MainWP_Helper::get_mainwp_dir( 'backup' );
        $this->backupdir     = $dirs[0];
        $this->delete_backup = $this->capture_callable_reply();
    }

    /**
     * Failed assertions must leave neither fixtures nor request state behind.
     */
    public function tearDown(): void {
        global $wp_filesystem;

        remove_filter( 'mainwp_child_get_wp_upload_dir', array( $this, 'isolate_upload_dir' ) );
        $_REQUEST = $this->original_request;
        foreach ( $this->fixture_files as $path ) {
            wp_delete_file( $path );
        }
        if ( $this->fixture_root && $wp_filesystem->exists( $this->fixture_root ) ) {
            $this->assertTrue( $wp_filesystem->delete( $this->fixture_root, true ) );
        }
        parent::tearDown();
    }

    /**
     * The existing helper filter avoids touching real uploads.
     *
     * @param array $uploads WordPress upload paths.
     * @return array
     */
    public function isolate_upload_dir( $uploads ) {
        $uploads['basedir'] = $this->fixture_root;
        return $uploads;
    }

    /**
     * Traversal must not reach even a harmless file next to the backup directory.
     */
    public function test_traversal_preserves_outside_fixture() {
        $outside = dirname( $this->backupdir ) . '/outside.zip';
        $this->create_fixture( $outside );

        $this->assert_ok_reply( '../outside.zip' );
        $this->assertFileExists( $outside, 'Traversal deleted the outside fixture.' );
    }

    /**
     * Concatenating an absolute path can still delete a matching nested path.
     */
    public function test_absolute_path_is_refused() {
        $outside = dirname( $this->backupdir ) . '/absolute.zip';
        $nested  = $this->backupdir . $outside;
        $this->create_fixture( $outside );
        $this->create_fixture( $nested );

        $this->assert_ok_reply( $outside );
        $this->assertFileExists( $outside );
        $this->assertFileExists( $nested, 'An absolute name deleted the concatenated fixture.' );
    }

    /**
     * Resolving links must enforce the directory boundary, including its separator.
     *
     * @dataProvider outside_symlink_provider
     * @param string $relative_path Outside target relative to the upload root.
     */
    public function test_outside_symlink_is_refused( $relative_path ) {
        if ( ! function_exists( 'symlink' ) ) {
            $this->markTestSkipped( 'symlink() is unavailable; outside-link containment cannot be exercised.' );
        }
        $outside = $this->fixture_root . '/' . $relative_path;
        $link    = $this->backupdir . 'linked.zip';
        $this->create_fixture( $outside );
        $this->assertTrue( symlink( $outside, $link ) );
        $this->fixture_files[] = $link;

        $this->assert_ok_reply( 'linked.zip' );
        $this->assertFileExists( $outside );
        $this->assertTrue( is_link( $link ), 'An outside symlink must remain untouched.' );
    }

    /**
     * A shared prefix is insufficient evidence of containment.
     *
     * @return array
     */
    public static function outside_symlink_provider() {
        return array(
            'outside directory'  => array( 'mainwp/outside.zip' ),
            'shared path prefix' => array( 'mainwp/backup-sibling/outside.zip' ),
        );
    }

    /**
     * A link inside the directory resolves to a different archive than the one requested.
     */
    public function test_inside_symlink_is_refused() {
        if ( ! function_exists( 'symlink' ) ) {
            $this->markTestSkipped( 'symlink() is unavailable; inside-link handling cannot be exercised.' );
        }
        $target = $this->backupdir . 'original.zip';
        $link   = $this->backupdir . 'linked.zip';
        $this->create_fixture( $target );
        $this->assertTrue( symlink( $target, $link ) );
        $this->fixture_files[] = $link;

        $this->assert_ok_reply( 'linked.zip' );
        $this->assertFileExists( $target, 'A link deleted the archive it points to.' );
        $this->assertTrue( is_link( $link ), 'An archive symlink must remain untouched.' );
    }

    /**
     * A missing archive must preserve neighbouring files and the legacy reply.
     */
    public function test_missing_file_returns_ok_without_deletion() {
        $sentinel = $this->backupdir . 'sentinel.zip';
        $this->create_fixture( $sentinel );

        $this->assert_ok_reply( 'missing.zip' );
        $this->assertFileDoesNotExist( $this->backupdir . 'missing.zip' );
        $this->assertFileExists( $sentinel );
    }

    /**
     * Control files and unsafe names must survive even when they exist.
     *
     * @dataProvider refused_filename_provider
     * @param string $name Existing file that is not a deletable backup name.
     */
    public function test_refused_filename_preserves_fixture( $name ) {
        $path = $this->backupdir . $name;
        $this->create_fixture( $path );

        $this->assert_ok_reply( $name );
        $this->assertFileExists( $path, 'A refused name deleted its fixture: ' . $name );
    }

    /**
     * Refusing non-archives protects the directory's control and temporary files.
     *
     * @return array
     */
    public static function refused_filename_provider() {
        return array(
            'index'           => array( 'index.php' ),
            'access rules'    => array( '.htaccess' ),
            'pid marker'      => array( 'backup-123.pid' ),
            'done marker'     => array( 'backup-123.done' ),
            'temporary sql'   => array( 'dbBackup-K7Q2M9XA-1760000000.sql' ),
            'temporary table' => array( 'dbBackup-K7Q2M9XA-1760000000-wp_posts.sql.php' ),
            'unanchored zip'  => array( 'x.zip.php' ),
            'double dots'     => array( 'backup..zip' ),
            'slash'           => array( 'nested/backup.zip' ),
            'backslash'       => array( 'nested\\backup.zip' ),
        );
    }

    /**
     * Older Dashboards must retain every archive name the Child returns.
     *
     * @dataProvider legacy_filename_provider
     * @param string $name Archive basename returned by the legacy backup flow.
     */
    public function test_legacy_archive_name_is_deleted( $name ) {
        $path = $this->backupdir . $name;
        $this->create_fixture( $path );

        $this->assert_ok_reply( $name );
        $this->assertFileDoesNotExist( $path, 'A legacy archive name must remain deletable: ' . $name );
    }

    /**
     * Custom names prevent a restrictive prefix rule from breaking older Dashboards.
     *
     * @return array
     */
    public static function legacy_filename_provider() {
        return array(
            'full zip'        => array( 'backup-K7Q2M9XA-1760000000.zip' ),
            'full tar'        => array( 'backup-K7Q2M9XA-1760000000.tar' ),
            'full gzip'       => array( 'backup-K7Q2M9XA-1760000000.tar.gz' ),
            'full bzip2'      => array( 'backup-K7Q2M9XA-1760000000.tar.bz2' ),
            'empty uid'       => array( 'backup-1760000000.tar.gz' ),
            'custom name'     => array( 'example.com-blog full (10-12-2026) 14h05m09s.tar.gz' ),
            'custom unicode'  => array( 'café_ブログ ~,;:[full] (2026).zip' ),
            'leading dot'     => array( '.custom-backup.tar' ),
            'database zip'    => array( 'dbBackup-K7Q2M9XA-1760000000.sql.zip' ),
            'database gzip'   => array( 'dbBackup-K7Q2M9XA-1760000000.sql.tar.gz' ),
            'database tar'    => array( 'dbBackup-K7Q2M9XA-1760000000.sql.tar' ),
            'database bzip2'  => array( 'dbBackup-K7Q2M9XA-1760000000.sql.tar.bz2' ),
            'database no uid' => array( 'dbBackup-1760000000.sql.zip' ),
        );
    }

    /**
     * Malformed requests must not be coerced into an existing archive name.
     *
     * @dataProvider invalid_request_provider
     * @param mixed $name Request value that cannot identify an archive.
     */
    public function test_invalid_request_preserves_archive( $name ) {
        $path = $this->backupdir . 'sentinel.zip';
        $this->create_fixture( $path );

        $this->assert_ok_reply( $name );
        $this->assertFileExists( $path );
    }

    /**
     * Missing string content cannot establish a safe archive name.
     *
     * @return array
     */
    public static function invalid_request_provider() {
        return array(
            'empty' => array( '' ),
            'nul'   => array( "sentinel\0.zip" ),
            'array' => array( array( 'sentinel.zip' ) ),
        );
    }

    /**
     * An archive suffix alone must never authorize deleting a directory.
     */
    public function test_archive_directory_is_refused() {
        $directory = $this->backupdir . 'directory.zip';
        $this->assertTrue( wp_mkdir_p( $directory ) );
        $attempts = array();
        $record   = static function ( $path ) use ( &$attempts ) {
            $attempts[] = $path;
            return '';
        };
        add_filter( 'wp_delete_file', $record );
        try {
            $this->assert_ok_reply( 'directory.zip' );
        } finally {
            remove_filter( 'wp_delete_file', $record );
        }
        $this->assertSame( array(), $attempts, 'A directory must never reach the deletion API.' );
        $this->assertDirectoryExists( $directory );
    }

    /**
     * Only disposable files may be used as deletion targets.
     *
     * @param string $path Harmless fixture path within the isolated upload root.
     */
    private function create_fixture( $path ) {
        global $wp_filesystem;

        $this->assertTrue( wp_mkdir_p( dirname( $path ) ) );
        $this->assertTrue( $wp_filesystem->put_contents( $path, 'Harmless backup deletion fixture.' ) );
        $this->fixture_files[] = $path;
        $this->assertFileExists( $path );
    }

    /**
     * Both deletion and refusal must preserve the Dashboard reply contract.
     *
     * @param mixed $name Requested basename.
     */
    private function assert_ok_reply( $name ) {
        $_REQUEST['del'] = wp_slash( $name );
        $this->assertSame( array( 'result' => 'ok' ), ( $this->delete_backup )() );
        clearstatcache();
    }

    /**
     * Only the terminal write becomes a return because write() dies inside PHPUnit.
     * Binding to the real callable preserves access to its private validation helpers.
     *
     * @return \Closure
     */
    private function capture_callable_reply() {
        global $wp_filesystem;

        $method = new \ReflectionMethod( MainWP_Child_Callable::class, 'delete_backup' );
        $lines  = explode( "\n", $wp_filesystem->get_contents( $method->getFileName() ) );
        $source = implode( "\n", array_slice( $lines, $method->getStartLine() - 1, $method->getEndLine() - $method->getStartLine() + 1 ) );
        $source = str_replace( 'public function delete_backup()', 'return function()', $source, $declarations );
        $source = preg_replace( '/MainWP_Helper::write\( (.*) \);\s*\}\s*\z/', 'return $1;' . "\n};", $source, 1, $writes );
        $this->assertSame( 1, $declarations );
        $this->assertSame( 1, $writes, 'Capture only the final response; keep the deletion body intact.' );
        $fixture = $this->fixture_root . '/callable-reply.php';
        $this->assertTrue( $wp_filesystem->put_contents( $fixture, '<?php namespace MainWP\\Child;' . "\n" . $source ) );
        $callable = require $fixture;
        return $callable->bindTo( MainWP_Child_Callable::get_instance(), MainWP_Child_Callable::class );
    }
}
