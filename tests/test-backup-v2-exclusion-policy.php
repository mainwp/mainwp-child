<?php
/**
 * Semantic excludes and local runtime policy for private v2 capture.
 *
 * @package Mainwp_Child
 */

use MainWP\Child\MainWP_Child_Backup_File_Walker;
use MainWP\Child\MainWP_Child_Backup_Layout;
use MainWP\Child\MainWP_Child_Backup_Layout_List;
use MainWP\Child\MainWP_Backup_Error;
use MainWP\Child\MainWP_Exception;

class BackupV2ExclusionPolicyTest extends WP_UnitTestCase {
    private $dir;
    private $descriptor;
    private $layout;

    public function set_up() {
        parent::set_up();
        $this->dir = sys_get_temp_dir() . '/mwp-exclusion-policy-' . uniqid();
        mkdir( $this->dir );
        $roots = array();
        $roles = array();
        foreach ( array( 'site', 'content', 'uploads', 'plugins', 'mu_plugins', 'out' ) as $role ) {
            mkdir( $this->dir . '/' . $role );
            if ( 'out' !== $role ) {
                $roots[ $role ] = $this->dir . '/' . $role;
                $roles[] = array( 'role' => $role, 'coverage' => 'site' === $role ? 'preserved' : 'restore' );
            }
        }
        $this->layout = new MainWP_Child_Backup_Layout( $roots );
        $this->descriptor = MainWP_Child_Backup_Layout::descriptor( $roles, str_repeat( 'a', 64 ) );
    }

    public function tear_down() {
        $this->remove_tree( $this->dir );
        parent::tear_down();
    }

    private function remove_tree( $path ) {
        if ( is_dir( $path ) && ! is_link( $path ) ) {
            foreach ( scandir( $path ) as $name ) {
                if ( '.' !== $name && '..' !== $name ) {
                    $this->remove_tree( $path . '/' . $name );
                }
            }
            rmdir( $path );
        } else {
            unlink( $path );
        }
    }

    private function put( $path ) {
        $path = $this->dir . '/' . $path;
        if ( ! is_dir( dirname( $path ) ) ) {
            mkdir( dirname( $path ), 0700, true );
        }
        file_put_contents( $path, 'fixture bytes' );
    }

    private function walk( $excludes = array(), $runtime = array() ) {
        $walker = new MainWP_Child_Backup_File_Walker( $this->dir . '/site', $excludes );
        $method = new ReflectionMethod( $walker, 'emit_v2_policy_slice' );
        $this->assertTrue( $method->isPrivate() );
        $method->setAccessible( true );
        $cursor = null;
        do {
            $out = $method->invoke( $walker, $this->dir . '/out', $this->layout, $this->descriptor, $cursor, PHP_INT_MAX, $runtime );
            $cursor = $out['cursor'];
        } while ( ! $out['done'] );
        return $out;
    }

    private function names() {
        return array_keys( ( new MainWP_Child_Backup_Layout_List() )->read( $this->dir . '/out/files.list.staged', MainWP_Child_Backup_Layout::digest( $this->descriptor ) ) );
    }

    public function test_user_patterns_follow_the_most_specific_moved_root() {
        foreach ( array( 'uploads/private/a', 'uploads/public/a', 'plugins/private/a', 'plugins/public/a', 'mu_plugins/private/a', 'content/private/a', 'content/public/a' ) as $path ) {
            $this->put( $path );
        }
        $this->walk( array( 'wp-content/uploads/private', 'wp-content/plugins/private', 'wp-content/mu-plugins/private', 'wp-content/private' ) );
        $this->assertSame( array( 'roots/content/public/a', 'roots/plugins/public/a', 'roots/uploads/public/a' ), $this->names() );
    }

    public function test_translated_prefix_wildcard_records_literal_coverage() {
        $this->put( 'uploads/private-data/a' );
        $this->put( 'uploads/public/a' );
        $out = $this->walk( array( 'wp-content/uploads/private*' ) );
        $this->assertSame( array( 'roots/uploads/public/a' ), $this->names() );
        $this->assertContains( 'roots/uploads/private-data', $out['walk']['excludes'] );
    }

    public static function spanning_patterns() {
        return array( array( 'wp-content', true ), array( 'wp-content/*', true ), array( 'wp-content/upl*', false ) );
    }

    /** @dataProvider spanning_patterns */
    public function test_parent_patterns_cover_independently_walked_semantic_roots( $pattern, $all ) {
        foreach ( array( 'content/keep', 'uploads/private/a', 'plugins/private/a', 'mu_plugins/private/a' ) as $path ) {
            $this->put( $path );
        }
        $out = $this->walk( array( $pattern ) );
        $this->assertSame( $all ? array() : array( 'roots/content/keep', 'roots/mu_plugins/private/a', 'roots/plugins/private/a' ), $this->names() );
        $this->assertContains( 'roots/uploads/*', $out['walk']['excludes'] );
        if ( $all ) {
            $this->assertContains( 'roots/plugins/*', $out['walk']['excludes'] );
            $this->assertContains( 'roots/mu_plugins/*', $out['walk']['excludes'] );
        }
    }

    public function test_assembled_empty_token_and_preserved_backup_default_are_covered() {
        $this->put( 'content/keep' );
        $this->put( 'content/object-cache.php' );
        $this->put( 'uploads/mainwp/artifact.mwp2' );
        $out = $this->walk( array( '', '/administrator/backups', 'wp-content/object-cache.php', 'wp-content/uploads/mainwp', rtrim( MAINWP_CHILD_PLUGIN_DIR, '/' ) ) );
        $this->assertSame( array( 'roots/content/keep' ), $this->names() );
        $this->assertContains( 'roots/site/administrator/backups', $out['walk']['excludes'] );
    }

    public function test_absolute_external_content_defaults_retain_semantic_ownership() {
        $this->put( 'uploads/backupbuddy_backups/a' );
        $this->put( 'content/cache/wp-rocket/a' );
        $this->put( 'content/keep' );
        $out = $this->walk( array( $this->dir . '/content/uploads/backupbuddy_backups', $this->dir . '/content/cache/wp-rocket' ) );
        $this->assertSame( array( 'roots/content/keep' ), $this->names() );
        $this->assertContains( 'roots/uploads/backupbuddy_backups', $out['walk']['excludes'] );
        $this->assertContains( 'roots/content/cache/wp-rocket', $out['walk']['excludes'] );
    }

    public function test_nested_custom_plugin_root_keeps_its_semantic_ownership() {
        $this->put( 'content/extensions/private/a' );
        $this->put( 'content/extensions/public/a' );
        $this->layout = new MainWP_Child_Backup_Layout( array( 'content' => $this->dir . '/content', 'uploads' => $this->dir . '/uploads', 'plugins' => $this->dir . '/content/extensions', 'mu_plugins' => $this->dir . '/mu_plugins' ) );
        $out = $this->walk( array( 'wp-content/extensions/private' ) );
        $this->assertSame( array( 'roots/plugins/public/a' ), $this->names() );
        $this->assertContains( 'roots/plugins/private', $out['walk']['excludes'] );
    }

    public function test_arbitrary_absolute_exclude_is_still_refused() {
        $this->expectException( MainWP_Exception::class );
        $this->walk( array( '/unmapped-private-folder/secret' ) );
    }

    public function test_ambiguous_exclude_is_refused_before_writes() {
        $this->put( 'content/private/a' );
        $this->put( 'out/keep' );
        try {
            $this->walk( array( 'private' ) );
            $this->fail( 'An untranslatable exclude became an inclusion.' );
        } catch ( MainWP_Exception $error ) {
            $this->assertSame( MainWP_Backup_Error::ERR_UNSUPPORTED_LAYOUT, $error->get_message_error_code() );
            $this->assertStringContainsString( 'Use a path under', $error->getMessage() );
        }
        $this->assertSame( 'fixture bytes', file_get_contents( $this->dir . '/out/keep' ) );
        $this->assertSame( array( $this->dir . '/out/keep' ), glob( $this->dir . '/out/*' ) );
    }

    public function test_runtime_flags_keep_optional_directories_when_disabled() {
        $this->put( 'uploads/backupbuddy_backups/a' );
        $this->put( 'content/cache/wp-rocket/a' );
        $this->put( 'content/object-cache.php' );
        $this->put( 'plugins/' . basename( rtrim( MAINWP_CHILD_PLUGIN_DIR, '/' ) ) . '/a' );
        $this->walk( array(), array( 'excludebackup' => false, 'excludecache' => false ) );
        $this->assertSame( array( 'roots/content/cache/wp-rocket/a', 'roots/uploads/backupbuddy_backups/a' ), $this->names() );
    }

    public function test_runtime_flags_exclude_known_backup_and_cache_locations() {
        $this->put( 'uploads/backupbuddy_backups/a' );
        $this->put( 'content/cache/wp-rocket/a' );
        $this->put( 'content/hyper-cache/cache/a' );
        $this->put( 'content/keep' );
        $this->walk( array(), array( 'excludebackup' => true, 'excludecache' => true ) );
        $this->assertSame( array( 'roots/content/keep' ), $this->names() );
    }

    public function test_resume_refuses_changed_runtime_exclusions() {
        $this->put( 'uploads/backupbuddy_backups/a' );
        $walker = new MainWP_Child_Backup_File_Walker( $this->dir . '/site' );
        $method = new ReflectionMethod( $walker, 'emit_v2_policy_slice' );
        $method->setAccessible( true );
        $initial = $method->invoke( $walker, $this->dir . '/out', $this->layout, $this->descriptor, null, PHP_INT_MAX, array( 'excludebackup' => true ) );
        $this->assertFalse( $initial['done'] );
        try {
            $method->invoke( $walker, $this->dir . '/out', $this->layout, $this->descriptor, $initial['cursor'], PHP_INT_MAX, array( 'excludebackup' => false ) );
            $this->fail( 'A changed exclusion policy reused the cursor.' );
        } catch ( MainWP_Exception $error ) {
            $this->assertSame( MainWP_Backup_Error::ERR_LAYOUT_CHANGED, $error->get_message_error_code() );
        }
    }

    public function test_relocated_storage_and_dump_litter_have_qualified_coverage() {
        $this->put( 'uploads/relocated/mainwp/archive.mwp2' );
        $this->put( 'content/dbBackup-old.sql' );
        $this->put( 'content/dbBackup-old.sql.php' );
        $this->put( 'content/nested/dbBackup-keep.sql' );
        $this->put( 'content/ordinary.sql' );
        $out = $this->walk( array(), array( 'storage_dirs' => array( $this->dir . '/uploads/relocated/mainwp' ) ) );
        $this->assertSame( array( 'roots/content/nested/dbBackup-keep.sql', 'roots/content/ordinary.sql' ), $this->names() );
        $this->assertContains( 'roots/uploads/relocated/mainwp', $out['walk']['excludes'] );
        $this->assertContains( 'roots/content/dbBackup-old.sql', $out['walk']['excludes'] );
        $this->assertContains( 'roots/content/dbBackup-old.sql.php', $out['walk']['excludes'] );
        $this->assertStringNotContainsString( $this->dir, wp_json_encode( $out['walk'] ) );
    }
}
