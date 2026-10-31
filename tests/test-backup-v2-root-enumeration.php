<?php
/**
 * Qualified enumeration through the private capture seam.
 *
 * @package Mainwp_Child
 */

use MainWP\Child\MainWP_Child_Backup_File_Walker;
use MainWP\Child\MainWP_Child_Backup_Layout;
use MainWP\Child\MainWP_Child_Backup_Layout_List;
use MainWP\Child\MainWP_Backup_Error;
use MainWP\Child\MainWP_Exception;

class RootCaptureSliceWalker extends MainWP_Child_Backup_File_Walker {
    private $steps = 0;
    protected function walk_budget_exhausted( $deadline ) { return PHP_INT_MAX !== $deadline && ++$this->steps > 2; }
    protected function chunk_size() { return 2; }
}

class BackupV2RootEnumerationTest extends WP_UnitTestCase {
    private $directory;
    public function set_up() {
        parent::set_up();
        $this->directory = sys_get_temp_dir() . '/mwp-root-capture-' . uniqid();
        foreach ( array( '', '/site', '/content', '/uploads', '/plugins', '/out' ) as $suffix ) {
            mkdir( $this->directory . $suffix );
        }
    }
    public function tear_down() {
        $this->remove_tree( $this->directory );
        parent::tear_down();
    }
    private function remove_tree( $path ) {
        if ( is_dir( $path ) && ! is_link( $path ) ) {
            chmod( $path, 0700 );
            foreach ( scandir( $path ) as $name ) {
                if ( '.' !== $name && '..' !== $name ) { $this->remove_tree( $path . '/' . $name ); }
            }
            rmdir( $path );
        } else { unlink( $path ); }
    }
    private function descriptor( $roles ) {
        $roots = array();
        foreach ( $roles as $role ) { $roots[] = array( 'role' => $role, 'coverage' => 'config' === $role ? 'recovery_only' : 'restore' ); }
        return MainWP_Child_Backup_Layout::descriptor( $roots, str_repeat( 'a', 64 ) );
    }
    private function invoke( $layout, $descriptor, $cursor = null, $sliced = false, $excludes = array(), $backup_dir = null ) {
        $method = new ReflectionMethod( MainWP_Child_Backup_File_Walker::class, 'emit_v2_sidecars_slice' );
        $method->setAccessible( true );
        $walker = new RootCaptureSliceWalker( $this->directory . '/site', $excludes );
        do {
            $out = $method->invoke( $walker, $backup_dir ?? $this->directory . '/out', $layout, $descriptor, $cursor, $sliced ? time() + 60 : PHP_INT_MAX );
            $cursor = $out['cursor'];
        } while ( ! $sliced && ! $out['done'] );
        return $out;
    }
    private function rows( $descriptor ) {
        return ( new MainWP_Child_Backup_Layout_List() )->read( $this->directory . '/out/files.list.staged', MainWP_Child_Backup_Layout::digest( $descriptor ) );
    }
    public function test_initial_cursor_precedes_all_writes() {
        file_put_contents( $this->directory . '/content/a', 'a' );
        file_put_contents( $this->directory . '/out/walk-run-old', 'old run marker' );
        $layout = new MainWP_Child_Backup_Layout( array( 'content' => $this->directory . '/content' ) );
        $descriptor = $this->descriptor( array( 'content' ) );
        $out = $this->invoke( $layout, $descriptor, null, true );
        $this->assertFalse( $out['done'] );
        $this->assertSame( 0, $out['count'] );
        $this->assertSame( 0, $out['cursor']['root_index'] );
        $this->assertSame( MainWP_Child_Backup_Layout::digest( $descriptor ), $out['cursor']['layout_descriptor_sha256'] );
        $this->assertMatchesRegularExpression( '/^[a-f0-9]{64}$/', $out['cursor']['registry_identity'] );
        $this->assertSame( array( $this->directory . '/out/walk-run-old' ), glob( $this->directory . '/out/*' ) );
        $this->assertSame( 'old run marker', file_get_contents( $this->directory . '/out/walk-run-old' ) );
    }

    public function test_legacy_excludes_are_refused_before_writes() {
        file_put_contents( $this->directory . '/out/walk-run-old', 'untouched' );
        $layout = new MainWP_Child_Backup_Layout( array( 'content' => $this->directory . '/content' ) );
        try {
            $this->invoke( $layout, $this->descriptor( array( 'content' ) ), null, false, array( 'wp-content/private' ) );
            $this->fail( 'Unqualified exclusion was accepted.' );
        } catch ( MainWP_Exception $error ) {
            $this->assertSame( MainWP_Backup_Error::ERR_UNSUPPORTED_LAYOUT, $error->get_message_error_code() );
        }
        $this->assertSame( array( $this->directory . '/out/walk-run-old' ), glob( $this->directory . '/out/*' ) );
        $this->assertSame( 'untouched', file_get_contents( $this->directory . '/out/walk-run-old' ) );
    }
    public function test_invalid_scratch_directory_is_refused_before_enumeration() {
        $layout = new MainWP_Child_Backup_Layout( array( 'content' => $this->directory . '/content' ) );
        try {
            $this->invoke( $layout, $this->descriptor( array( 'content' ) ), null, true, array(), $this->directory . '/missing' );
            $this->fail( 'Invalid scratch directory was accepted.' );
        } catch ( MainWP_Exception $error ) {
            $this->assertSame( MainWP_Backup_Error::ERR_DIR_NOT_WRITABLE, $error->get_message_error_code() );
        }
        $this->assertDirectoryDoesNotExist( $this->directory . '/missing' );
    }
    public function test_qualified_exclude_patterns_remain_fixed_and_diff_compatible() {
        mkdir( $this->directory . '/content/private' );
        file_put_contents( $this->directory . '/content/private/secret.txt', 'excluded fixture' );
        file_put_contents( $this->directory . '/content/keep.txt', 'keep' );
        $layout = new MainWP_Child_Backup_Layout( array( 'content' => $this->directory . '/content' ) );
        $descriptor = $this->descriptor( array( 'content' ) );
        $out = $this->invoke( $layout, $descriptor, null, false, array( 'roots/content/private' ) );
        $this->assertSame( array( 'roots/content/keep.txt' ), array_keys( $this->rows( $descriptor ) ) );
        $this->assertSame( 1, count( array_keys( $out['walk']['excludes'], 'roots/content/private', true ) ) );
        $this->assertNotContains( 'roots/content/private/secret.txt', $out['walk']['excludes'] );
        $lists = new MainWP_Child_Backup_Layout_List();
        $digest = MainWP_Child_Backup_Layout::digest( $descriptor );
        $parent = $this->rows( $descriptor );
        $parent['roots/content/private/secret.txt'] = array( 16, 1 );
        $lists->write( $this->directory . '/out/parent.list', $digest, $parent );
        $counts = $lists->diff( $this->directory . '/out/parent.list', $this->directory . '/out/files.list.staged', $this->directory . '/out/changes.list', $this->directory . '/out/tombstones.list', $digest, false, $out['walk']['excludes'] );
        $this->assertSame( 0, $counts['tombstones'] );
    }

    public function test_wildcard_exclusion_records_literal_coverage_for_diff() {
        mkdir( $this->directory . '/content/private-data' );
        file_put_contents( $this->directory . '/content/private-data/secret.txt', 'excluded fixture' );
        $layout = new MainWP_Child_Backup_Layout( array( 'content' => $this->directory . '/content' ) );
        $descriptor = $this->descriptor( array( 'content' ) );
        $out = $this->invoke( $layout, $descriptor, null, false, array( 'roots/content/private*' ) );
        $this->assertSame( 0, $out['count'] );
        $this->assertContains( 'roots/content/private-data', $out['walk']['excludes'] );
        $lists = new MainWP_Child_Backup_Layout_List();
        $digest = MainWP_Child_Backup_Layout::digest( $descriptor );
        $lists->write( $this->directory . '/out/parent.list', $digest, array( 'roots/content/private-data/secret.txt' => array( 16, 1 ) ) );
        $counts = $lists->diff( $this->directory . '/out/parent.list', $this->directory . '/out/files.list.staged', $this->directory . '/out/changes.list', $this->directory . '/out/tombstones.list', $digest, false, $out['walk']['excludes'] );
        $this->assertSame( 0, $counts['tombstones'] );
    }

    public function test_same_basename_in_three_external_roots_is_three_files() {
        $roots = array();
        foreach ( array( 'content', 'uploads', 'plugins' ) as $role ) {
            $roots[ $role ] = $this->directory . '/' . $role;
            file_put_contents( $roots[ $role ] . '/same.txt', $role );
        }
        $descriptor = $this->descriptor( array_keys( $roots ) );
        $out = $this->invoke( new MainWP_Child_Backup_Layout( $roots ), $descriptor );
        $this->assertTrue( $out['done'] );
        $this->assertSame( array( 'roots/content/same.txt', 'roots/plugins/same.txt', 'roots/uploads/same.txt' ), array_keys( $this->rows( $descriptor ) ) );
        $this->assertSame( 3, $out['count'] );
        $legacy = new MainWP_Child_Backup_File_Walker( $this->directory . '/site' );
        mkdir( $this->directory . '/legacy' );
        $this->assertSame( 0, $legacy->emit_sidecars( $this->directory . '/legacy' )['count'] );
    }
    public function test_nested_and_alias_roots_prune_every_non_owner() {
        mkdir( $this->directory . '/content/uploads' );
        file_put_contents( $this->directory . '/content/own.txt', 'content' );
        file_put_contents( $this->directory . '/content/uploads/same.txt', 'uploads' );
        $roots = array( 'site' => $this->directory, 'content' => $this->directory . '/content', 'uploads' => $this->directory . '/content/uploads', 'plugins' => $this->directory . '/content/uploads' );
        // Site is deliberately omitted from capture; it still participates in ownership.
        $descriptor = $this->descriptor( array( 'content', 'uploads', 'plugins' ) );
        $out = $this->invoke( new MainWP_Child_Backup_Layout( $roots ), $descriptor );
        $this->assertSame( 2, $out['count'] );
        $this->assertSame( array( 'roots/content/own.txt', 'roots/uploads/same.txt' ), array_keys( $this->rows( $descriptor ) ) );
        $this->assertSame( 2, substr_count( file_get_contents( $this->directory . '/out/paths.cache.staged' ), "\n" ) );
    }
    public function test_more_specific_semantic_root_wins_in_both_directions() {
        foreach ( array( array( 'plugins', 'uploads' ), array( 'uploads', 'plugins' ) ) as $roles ) {
            $outer = $this->directory . '/' . $roles[0];
            mkdir( $outer . '/nested' );
            file_put_contents( $outer . '/nested/same.txt', 'nested' );
            $roots = array( $roles[0] => $outer, $roles[1] => $outer . '/nested' );
            $descriptor = $this->descriptor( array_keys( $roots ) );
            $out = $this->invoke( new MainWP_Child_Backup_Layout( $roots ), $descriptor );
            $this->assertSame( 1, $out['count'] );
            $this->assertSame( array( 'roots/' . $roles[1] . '/same.txt' ), array_keys( $this->rows( $descriptor ) ) );
        }
    }
    public function test_resume_preserves_registry_and_emits_the_same_list() {
        for ( $i = 0; $i < 7; ++$i ) { file_put_contents( $this->directory . '/content/' . $i, 'x' ); }
        $layout = new MainWP_Child_Backup_Layout( array( 'content' => $this->directory . '/content' ) );
        $descriptor = $this->descriptor( array( 'content' ) );
        $cursor = null;
        $slices = 0;
        do {
            $out = $this->invoke( $layout, $descriptor, $cursor, true );
            $cursor = $out['cursor'];
            if ( null !== $cursor ) {
                $this->assertSame( 2, $cursor['format'] );
                $this->assertSame( MainWP_Child_Backup_Layout::digest( $descriptor ), $cursor['layout_descriptor_sha256'] );
                $this->assertFalse( $out['walk']['done'] );
            }
            $this->assertLessThan( 12, ++$slices );
        } while ( ! $out['done'] );
        $before = file_get_contents( $this->directory . '/out/files.list.staged' );
        $this->invoke( $layout, $descriptor );
        $this->assertSame( $before, file_get_contents( $this->directory . '/out/files.list.staged' ) );
        $this->assertSame( 7, $out['count'] );
    }
    public function test_moved_root_stops_before_any_more_writes() {
        symlink( $this->directory . '/content', $this->directory . '/live' );
        file_put_contents( $this->directory . '/content/a', 'a' );
        file_put_contents( $this->directory . '/content/b', 'b' );
        file_put_contents( $this->directory . '/content/c', 'c' );
        $layout = new MainWP_Child_Backup_Layout( array( 'content' => $this->directory . '/live' ) );
        $descriptor = $this->descriptor( array( 'content' ) );
        $out = $this->invoke( $layout, $descriptor, null, true );
        $out = $this->invoke( $layout, $descriptor, $out['cursor'], true );
        $this->assertFalse( $out['done'] );
        $this->assertGreaterThan( 0, $out['count'] );
        $before = array();
        foreach ( glob( $this->directory . '/out/*' ) as $path ) { $before[ basename( $path ) ] = hash_file( 'sha256', $path ); }
        unlink( $this->directory . '/live' );
        symlink( $this->directory . '/uploads', $this->directory . '/live' );
        try {
            $this->invoke( $layout, $descriptor, $out['cursor'], true );
            $this->fail( 'Moved binding was accepted.' );
        } catch ( MainWP_Exception $error ) {
            $this->assertSame( MainWP_Backup_Error::ERR_LAYOUT_CHANGED, $error->get_message_error_code() );
        }
        $after = array();
        foreach ( glob( $this->directory . '/out/*' ) as $path ) { $after[ basename( $path ) ] = hash_file( 'sha256', $path ); }
        $this->assertSame( $before, $after );
    }
    public function test_required_root_missing_after_pinning_is_not_empty() {
        $layout = new MainWP_Child_Backup_Layout( array( 'content' => $this->directory . '/content' ) );
        rmdir( $this->directory . '/content' );
        $this->expectException( MainWP_Exception::class );
        $this->invoke( $layout, $this->descriptor( array( 'content' ) ) );
    }
    public function test_required_unreadable_root_is_refused() {
        $layout = new MainWP_Child_Backup_Layout( array( 'content' => $this->directory . '/content' ) );
        chmod( $this->directory . '/content', 0000 );
        $this->expectException( MainWP_Exception::class );
        $this->invoke( $layout, $this->descriptor( array( 'content' ) ) );
    }
    public function test_default_exclusions_follow_moved_roots() {
        foreach ( array( '/uploads/mainwp', '/uploads/backupbuddy_backups', '/content/cache', '/content/cache/wp-rocket' ) as $suffix ) {
            mkdir( $this->directory . $suffix );
            if ( '/content/cache' !== $suffix ) {
                file_put_contents( $this->directory . $suffix . '/omit.txt', 'omit' );
            }
        }
        $own_name = basename( rtrim( MAINWP_CHILD_PLUGIN_DIR, '/' ) );
        mkdir( $this->directory . '/plugins/' . $own_name );
        file_put_contents( $this->directory . '/plugins/' . $own_name . '/omit.txt', 'omit' );
        file_put_contents( $this->directory . '/uploads/keep.txt', 'keep' );
        $roots = array( 'content' => $this->directory . '/content', 'uploads' => $this->directory . '/uploads', 'plugins' => $this->directory . '/plugins' );
        $descriptor = $this->descriptor( array_keys( $roots ) );
        $out = $this->invoke( new MainWP_Child_Backup_Layout( $roots ), $descriptor );
        $this->assertSame( 1, $out['count'] );
        $this->assertSame( array( 'roots/uploads/keep.txt' ), array_keys( $this->rows( $descriptor ) ) );
    }

    public function test_exact_config_allowlist_and_qualified_skips() {
        file_put_contents( $this->directory . '/content/wp-config.php', 'fake recovery marker' );
        file_put_contents( $this->directory . '/content/object-cache.php', 'excluded' );
        symlink( $this->directory . '/content/wp-config.php', $this->directory . '/content/link.php' );
        $layout = new MainWP_Child_Backup_Layout( array( 'content' => $this->directory . '/content' ), array( 'wp-config.php' => $this->directory . '/content/wp-config.php' ) );
        $descriptor = $this->descriptor( array( 'content', 'config' ) );
        $out = $this->invoke( $layout, $descriptor );
        $this->assertSame( array( 'roots/config/wp-config.php' ), array_keys( $this->rows( $descriptor ) ) );
        $this->assertSame( array( 'roots/content/link.php' ), $out['walk']['unarchived'] );
        $this->assertContains( 'roots/content/object-cache.php', $out['walk']['excludes'] );
        $this->assertSame( 2, $out['walk']['backup_format'] );
        $this->assertSame( array( 'link' => 1 ), $out['walk']['skipped_by_reason'] );
    }

    public function test_resume_refuses_a_different_registry_before_cleanup() {
        $descriptor = $this->descriptor( array( 'content' ) );
        $layout = new MainWP_Child_Backup_Layout( array( 'content' => $this->directory . '/content' ) );
        $initial = $this->invoke( $layout, $descriptor, null, true );
        $marker = $this->directory . '/out/walk-run-preserved';
        file_put_contents( $marker, 'unchanged' );
        $replacement = new MainWP_Child_Backup_Layout( array( 'content' => $this->directory . '/uploads' ) );
        try {
            $this->invoke( $replacement, $descriptor, $initial['cursor'] );
            $this->fail( 'A different registry reused the cursor.' );
        } catch ( MainWP_Exception $error ) {
            $this->assertSame( MainWP_Backup_Error::ERR_LAYOUT_CHANGED, $error->get_message_error_code() );
        }
        $this->assertSame( 'unchanged', file_get_contents( $marker ) );
    }

}
