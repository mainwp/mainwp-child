<?php
/**
 * Static config dependencies captured before restore file changes.
 *
 * @package Mainwp_Child
 */

use MainWP\Child\MainWP_Child_Backup_Config_Includes;

class Testable_Backup_Config_Includes extends MainWP_Child_Backup_Config_Includes {

    public $prepend = '';
    public $failure = '';
    public $messages = array();
    public $reads = 0;

    protected function auto_prepend_file() {
        return $this->prepend;
    }

    protected function read_config( $file ) {
        ++$this->reads;
        if ( 'unreadable' === $this->failure ) {
            return false;
        }
        if ( 'throwable' === $this->failure ) {
            throw new RuntimeException( 'fixture read failure' );
        }
        return parent::read_config( $file );
    }

    protected function tokenize( $source ) {
        if ( 'tokenizer' === $this->failure ) {
            throw new RuntimeException( 'fixture tokenizer unavailable' );
        }
        return parent::tokenize( $source );
    }

    protected function debug( $message ) {
        $this->messages[] = $message;
    }
}

class BackupConfigIncludesTest extends WP_UnitTestCase {

    private $dir;
    private $root;
    private $scanner;

    public function set_up() {
        parent::set_up();
        $this->dir = sys_get_temp_dir() . '/mainwp-config-includes-' . uniqid();
        $this->root = $this->dir . '/site';
        mkdir( $this->root, 0777, true );
        $this->dir = realpath( $this->dir );
        $this->root = $this->dir . '/site';
        $this->scanner = new Testable_Backup_Config_Includes();
    }

    public function tear_down() {
        $this->remove_tree( $this->dir );
        parent::tear_down();
    }

    private function remove_tree( $dir ) {
        foreach ( array_diff( scandir( $dir ), array( '.', '..' ) ) as $entry ) {
            $path = $dir . '/' . $entry;
            if ( is_dir( $path ) && ! is_link( $path ) ) {
                $this->remove_tree( $path );
            } else {
                unlink( $path );
            }
        }
        rmdir( $dir );
    }

    private function manifest( $paths ) {
        $file = $this->dir . '/files.list';
        sort( $paths, SORT_STRING );
        file_put_contents( $file, implode( '', array_map( static function ( $path ) { return $path . "\t1\t1\n"; }, $paths ) ) );
        return $file;
    }

    public function test_locator_follows_wp_load_parent_install_rule() {
        $this->assertNull( $this->scanner->locate( $this->root ) );
        file_put_contents( $this->dir . '/wp-config.php', '<?php' );
        $this->assertSame( $this->dir . '/wp-config.php', $this->scanner->locate( $this->root . '/' ) );
        file_put_contents( $this->dir . '/wp-settings.php', '<?php' );
        $this->assertNull( $this->scanner->locate( $this->root ) );
        file_put_contents( $this->root . '/wp-config.php', '<?php' );
        $this->assertSame( $this->root . '/wp-config.php', $this->scanner->locate( $this->root ) );
    }

    public static function data_static_config_includes() {
        return array(
            'dir' => array( "require __DIR__ . '/x.php';" ),
            'dirname file' => array( "require dirname( __FILE__ ) . '/x.php';" ),
            'root' => array( "require ABSPATH . 'x.php';" ),
            'absolute' => array( "require '@ROOT@/x.php';" ),
            'relative' => array( "require 'x.php';" ),
            'require once' => array( "require_once( ABSPATH . 'x.php' );" ),
            'include' => array( "include ABSPATH . 'x.php';" ),
            'include once' => array( "include_once( __DIR__ . '/x.php' );" ),
            'literal concatenation' => array( "require 'x' . '.php';" ),
            'comments' => array( "require /* anchor */ ABSPATH /* path */ . 'x.php';" ),
            'double quoted' => array( 'require "x.php";' ),
            'binary single quoted' => array( "require b'x.php';" ),
            'binary double quoted' => array( 'require B"x.php";' ),
            'hex escape' => array( 'require "\x78.php";' ),
            'octal escape' => array( 'require "\170.php";' ),
            'unicode escape' => array( 'require "\u{78}.php";' ),
            'closing tag' => array( "require ABSPATH . 'x.php' ?>" ),
        );
    }

    /** @dataProvider data_static_config_includes */
    public function test_scanner_resolves_static_config_includes( $statement ) {
        $file = $this->root . '/wp-config.php';
        file_put_contents( $file, '<?php ' . str_replace( '@ROOT@', $this->root, $statement ) );
        $scan = $this->scanner->scan( $file, $this->root );
        $this->assertSame( 0, $scan['unresolved'] );
        $this->assertSame( array( $this->root . '/x.php' ), array_values( array_unique( $scan['paths'] ) ) );
        $this->assertSame( array( 'x.php' ), $this->scanner->capture( $this->root, null )['paths'] );
    }

    public function test_parent_relative_literal_records_root_and_config_directory_candidates() {
        $file = $this->dir . '/wp-config.php';
        file_put_contents( $file, "<?php require 'x.php'; require __DIR__ . '/site/y.php'; require dirname(__FILE__) . '/site/z.php';" );
        $scan = $this->scanner->scan( $file, $this->root );
        $this->assertSame( array( $this->root . '/x.php', $this->dir . '/x.php', $this->root . '/y.php', $this->root . '/z.php' ), $scan['paths'] );
        $this->assertSame( array( 'x.php', 'y.php', 'z.php' ), $this->scanner->capture( $this->root, null )['paths'] );
    }

    public static function data_symlinked_config_directories() {
        return array( 'inside root' => array( true ), 'outside root' => array( false ) );
    }

    /** @dataProvider data_symlinked_config_directories */
    public function test_symlinked_config_uses_real_directory_for_magic_constants_and_relative_literals( $inside ) {
        $directory = ( $inside ? $this->root : $this->dir ) . '/host-config';
        mkdir( $directory );
        $target = $directory . '/wp-config.php';
        file_put_contents( $target, "<?php require __DIR__ . '/dir.php'; require dirname( __FILE__ ) . '/file.php'; require ABSPATH . 'root.php'; require 'relative.php';" );
        $file = $this->root . '/wp-config.php';
        if ( ! function_exists( 'symlink' ) || ! @symlink( $target, $file ) ) {
            $this->markTestSkipped( 'Filesystem does not support symlinks.' );
        }
        $scan = $this->scanner->scan( $file, $this->root );
        $this->assertSame( 0, $scan['unresolved'] );
        $this->assertSame( array( $directory . '/dir.php', $directory . '/file.php', $this->root . '/root.php', $this->root . '/relative.php', $directory . '/relative.php' ), $scan['paths'] );
        $expected = $inside ? array( 'host-config/dir.php', 'host-config/file.php', 'host-config/relative.php', 'relative.php', 'root.php' ) : array( 'relative.php', 'root.php' );
        $this->assertSame( $expected, $this->scanner->capture( $this->root, null )['paths'] );
    }

    public function test_manifest_read_failure_falls_back_to_live_config_scan() {
        file_put_contents( $this->root . '/wp-config.php', "<?php require ABSPATH . 'host.php';" );
        $manifest = $this->manifest( array( 'wp-config.php' ) );
        unlink( $manifest );
        $this->scanner->prepend = $this->root . '/prepend.php';
        $this->assertSame( array( 'paths' => array( 'host.php', 'prepend.php' ), 'config' => 'live' ), $this->scanner->capture( $this->root, $manifest ) );
        $this->assertSame( 1, $this->scanner->reads );
        $this->assertSame( array( '[MainWP Backup] Config includes: manifest_failed=1' ), $this->scanner->messages );
    }

    public static function data_dynamic_config_includes() {
        return array(
            'variable' => array( 'require $path;' ),
            'constant' => array( "require WP_CONTENT_DIR . '/x.php';" ),
            'function' => array( "require getenv('CONFIG_PATH');" ),
            'interpolation' => array( 'require "$path/x.php";' ),
            'file alone' => array( 'require __FILE__;' ),
            'nested parentheses' => array( "require ((ABSPATH . 'x.php'));" ),
            'heredoc' => array( "require <<<PATH\nx.php\nPATH;" ),
            'nowdoc' => array( "require <<<'PATH'\nx.php\nPATH;" ),
        );
    }

    /** @dataProvider data_dynamic_config_includes */
    public function test_dynamic_config_includes_are_unresolved_and_logged_without_contents( $statement ) {
        $file = $this->root . '/wp-config.php';
        file_put_contents( $file, '<?php ' . $statement );
        $this->assertSame( array( 'paths' => array(), 'unresolved' => 1 ), $this->scanner->scan( $file, $this->root ) );
        $this->assertSame( array(), $this->scanner->capture( $this->root, null )['paths'] );
        $this->assertSame( array( '[MainWP Backup] Config includes: unresolved=1 skipped=0' ), $this->scanner->messages );
    }

    public function test_comments_and_strings_never_become_include_statements() {
        $file = $this->root . '/wp-config.php';
        file_put_contents( $file, '<?php /* require ABSPATH . "secret.php"; */ // include "other.php";' . "\n" . '$text = "require \'x.php\';";' );
        $this->assertSame( array( 'paths' => array(), 'unresolved' => 0 ), $this->scanner->scan( $file, $this->root ) );
    }

    public function test_manifest_config_is_not_read_but_excluded_config_is_scanned() {
        file_put_contents( $this->root . '/wp-config.php', "<?php require ABSPATH . 'x.php';" );
        $this->assertSame( array( 'paths' => array(), 'config' => 'manifest' ), $this->scanner->capture( $this->root, $this->manifest( array( 'wp-config.php' ) ) ) );
        $this->assertSame( 0, $this->scanner->reads );
        $this->assertSame( array( 'paths' => array( 'x.php' ), 'config' => 'live' ), $this->scanner->capture( $this->root, $this->manifest( array( 'snapshot.txt' ) ) ) );
        $this->assertSame( 1, $this->scanner->reads );
    }

    public static function data_prepend_capture() {
        return array(
            'inside live' => array( 'inside', false, array( 'prepend.php', 'x.php' ) ),
            'inside archived' => array( 'inside', true, array( 'prepend.php' ) ),
            'outside live' => array( 'outside', false, array( 'x.php' ) ),
            'outside archived' => array( 'outside', true, array() ),
            'empty live' => array( '', false, array( 'x.php' ) ),
            'empty archived' => array( '', true, array() ),
        );
    }

    /** @dataProvider data_prepend_capture */
    public function test_prepend_capture_is_independent_of_config_manifest_membership( $prepend, $archived, $expected ) {
        file_put_contents( $this->root . '/wp-config.php', "<?php require ABSPATH . 'x.php';" );
        $this->scanner->prepend = 'inside' === $prepend ? $this->root . '/prepend.php' : ( 'outside' === $prepend ? $this->dir . '/outside.php' : '' );
        $state = $this->scanner->capture( $this->root, $this->manifest( $archived ? array( 'wp-config.php' ) : array( 'snapshot.txt' ) ) );
        $this->assertSame( $expected, $state['paths'] );
        $this->assertSame( $archived ? 0 : 1, $this->scanner->reads );
    }

    public static function data_config_scan_failures() {
        return array_map( static function ( $failure ) { return array( $failure ); }, array( 'missing', 'malformed', 'unreadable', 'oversized', 'tokenizer', 'throwable' ) );
    }

    /** @dataProvider data_config_scan_failures */
    public function test_config_scan_failures_never_block_prepend_capture( $failure ) {
        $file = $this->root . '/wp-config.php';
        if ( 'missing' !== $failure ) {
            $bytes = "<?php require ABSPATH . 'x.php';";
            if ( 'malformed' === $failure ) {
                $bytes .= ' function {';
            } elseif ( 'oversized' === $failure ) {
                $bytes .= str_repeat( ' ', MainWP_Child_Backup_Config_Includes::MAX_BYTES );
            }
            file_put_contents( $file, $bytes );
        }
        $this->scanner->failure = $failure;
        $this->scanner->prepend = $this->root . '/prepend.php';
        $this->assertSame( array( 'prepend.php' ), $this->scanner->capture( $this->root, null )['paths'] );
        $this->assertCount( 'missing' === $failure ? 0 : 1, $this->scanner->messages );
        if ( 'missing' !== $failure ) {
            $this->assertSame( '[MainWP Backup] Config includes: scan_failed=1 unresolved=0 skipped=0', $this->scanner->messages[0] );
        }
    }

    public function test_capture_rejects_outside_traversal_sibling_and_linked_ancestors() {
        mkdir( $this->root . '/real' );
        if ( ! function_exists( 'symlink' ) || ! @symlink( $this->root . '/real', $this->root . '/alias' ) ) {
            $this->markTestSkipped( 'Filesystem does not support symlinks.' );
        }
        file_put_contents( $this->root . '/wp-config.php', "<?php require '" . $this->dir . "/outside.php'; require ABSPATH . '../outside.php'; require ABSPATH . '../site-other/x.php'; require ABSPATH . 'alias/x.php'; require ABSPATH . 'real/../safe.php';" );
        $this->assertSame( array( 'safe.php' ), $this->scanner->capture( $this->root, null )['paths'] );
        $this->assertSame( array( '[MainWP Backup] Config includes: unresolved=0 skipped=4' ), $this->scanner->messages );
    }

    public function test_numeric_filename_stays_a_relpath_string() {
        file_put_contents( $this->root . '/wp-config.php', "<?php require '123';" );
        $state = $this->scanner->capture( $this->root, null );
        $this->assertSame( array( '123' ), $state['paths'] );
        $this->assertTrue( MainWP_Child_Backup_Config_Includes::contains( './123', $state ) );
        $this->assertFalse( MainWP_Child_Backup_Config_Includes::contains( '123/child.php', $state ) );
    }

    public function test_capture_bounds_and_sorts_unique_paths_and_reserves_prepend() {
        $source = '<?php ';
        for ( $i = 0; $i < MainWP_Child_Backup_Config_Includes::MAX_PATHS + 5; ++$i ) {
            $source .= "require ABSPATH . 'x-$i.php';";
        }
        $source .= "require ABSPATH . 'x-0.php';";
        file_put_contents( $this->root . '/wp-config.php', $source );
        $this->scanner->prepend = $this->root . '/prepend.php';
        $state = $this->scanner->capture( $this->root, null );
        $this->assertCount( MainWP_Child_Backup_Config_Includes::MAX_PATHS, $state['paths'] );
        $this->assertContains( 'prepend.php', $state['paths'] );
        $sorted = $state['paths'];
        sort( $sorted, SORT_STRING );
        $this->assertSame( $sorted, $state['paths'] );
        $this->assertSame( array( '[MainWP Backup] Config includes: unresolved=0 skipped=6' ), $this->scanner->messages );
    }

    public function test_case_insensitive_alias_also_protects_the_on_disk_spelling() {
        file_put_contents( $this->root . '/host.php', '<?php' );
        if ( ! file_exists( $this->root . '/HOST.PHP' ) ) {
            $this->markTestSkipped( 'Case-sensitive filesystem: the alias cannot load.' );
        }
        file_put_contents( $this->root . '/wp-config.php', "<?php\nrequire ABSPATH . 'Host.php';\n" );
        $state = $this->scanner->capture( $this->root . '/', null );
        $this->assertSame( 'live', $state['config'] );
        $this->assertContains( 'Host.php', $state['paths'] );
        $this->assertContains( 'host.php', $state['paths'], 'Tombstones and reconcile compare the walker spelling, so the on-disk name must be protected too.' );
    }

    public function test_exact_spelling_adds_no_alias() {
        file_put_contents( $this->root . '/host.php', '<?php' );
        file_put_contents( $this->root . '/wp-config.php', "<?php\nrequire ABSPATH . 'host.php';\n" );
        $state = $this->scanner->capture( $this->root . '/', null );
        $this->assertSame( array( 'host.php' ), $state['paths'] );
    }
}
