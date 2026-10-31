<?php
/**
 * Qualified v2 deletion coverage and diff parity.
 *
 * @package Mainwp_Child
 */

use MainWP\Child\MainWP_Child_Backup_Layout;
use MainWP\Child\MainWP_Child_Backup_Layout_List;
use MainWP\Child\MainWP_Exception;

class BackupV2QualifiedDiffTest extends WP_UnitTestCase {
    private $dir;
    private $digest;
    private $list;

    public function set_up() {
        parent::set_up();
        $this->dir = sys_get_temp_dir() . '/mwp-v2-diff-' . uniqid();
        mkdir( $this->dir );
        $descriptor = MainWP_Child_Backup_Layout::descriptor(
            array(
                array( 'role' => 'site', 'coverage' => 'preserved' ),
                array( 'role' => 'content', 'coverage' => 'restore' ),
                array( 'role' => 'uploads', 'coverage' => 'restore' ),
                array( 'role' => 'plugins', 'coverage' => 'restore' ),
            ),
            str_repeat( 'a', 64 )
        );
        $this->digest = MainWP_Child_Backup_Layout::digest( $descriptor );
        $this->list = new MainWP_Child_Backup_Layout_List();
    }

    public function tear_down() {
        foreach ( glob( $this->dir . '/*' ) as $path ) {
            unlink( $path );
        }
        rmdir( $this->dir );
        parent::tear_down();
    }

    private function compare( $walk, $merge = false ) {
        $method = new ReflectionMethod( MainWP_Child_Backup_Layout_List::class, 'diff_walk' );
        $method->setAccessible( true );
        return $method->invoke( $this->list, $this->dir . '/parent', $this->dir . '/current', $this->dir . '/changes', $this->dir . '/tombs', $this->digest, $walk, $merge );
    }

    private function seed( $parent, $current ) {
        $this->list->write( $this->dir . '/parent', $this->digest, $parent );
        $this->list->write( $this->dir . '/current', $this->digest, $current );
    }

    public function test_both_engines_compare_each_role_and_sort_bytes_identically() {
        $this->seed(
            array(
                'roots/content/same.txt' => array( 1, 10 ),
                'roots/plugins/same.txt' => array( 2, 10 ),
                'roots/uploads/same.txt' => array( 3, 10 ),
                'roots/uploads/z.txt' => array( 1, 10 ),
            ),
            array(
                'roots/content/same.txt' => array( 1, 10 ),
                'roots/plugins/same.txt' => array( 2, 11 ),
                'roots/uploads/A.txt' => array( 4, 10 ),
                'roots/uploads/a.txt' => array( 5, 10 ),
            )
        );
        $outputs = array();
        foreach ( array( false, true ) as $merge ) {
            $counts = $this->compare( array( 'backup_format' => 2, 'complete' => true ), $merge );
            $this->assertSame( array( 'changes' => 3, 'tombstones' => 2, 'carried' => 1 ), $counts );
            $this->assertSame( array( 'roots/plugins/same.txt', 'roots/uploads/A.txt', 'roots/uploads/a.txt' ), array_keys( $this->list->read( $this->dir . '/changes', $this->digest ) ) );
            $this->assertSame( array( 'roots/uploads/same.txt', 'roots/uploads/z.txt' ), array_keys( $this->list->read( $this->dir . '/tombs', $this->digest, true ) ) );
            $outputs[] = array( file_get_contents( $this->dir . '/changes' ), file_get_contents( $this->dir . '/tombs' ) );
        }
        $this->assertSame( $outputs[0], $outputs[1] );
    }

    public static function incomplete_walks() {
        return array(
            'partial' => array( array( 'complete' => false ) ),
            'failed' => array( array( 'complete' => true, 'failed' => true ) ),
            'overflow' => array( array( 'complete' => true, 'unarchived_overflow' => true ) ),
            'missing-completion' => array( array() ),
        );
    }

    /** @dataProvider incomplete_walks */
    public function test_failed_partial_and_overflowing_walks_emit_no_tombstones( $coverage ) {
        $this->seed(
            array( 'roots/content/same' => array( 1, 10 ), 'roots/plugins/same' => array( 2, 10 ), 'roots/uploads/same' => array( 3, 10 ) ),
            array()
        );
        foreach ( array( false, true ) as $merge ) {
            $counts = $this->compare( array_merge( array( 'backup_format' => 2 ), $coverage ), $merge );
            $this->assertSame( 0, $counts['tombstones'] );
            $this->assertSame( 3, $counts['carried'] );
            $this->assertSame( array(), $this->list->read( $this->dir . '/tombs', $this->digest, true ) );
        }
    }

    public static function coverage_keys() {
        return array( array( 'unarchived' ), array( 'skipped_paths' ), array( 'excludes' ) );
    }

    /** @dataProvider coverage_keys */
    public function test_qualified_coverage_has_slash_boundaries_and_does_not_hide_other_roots( $key ) {
        $this->seed(
            array(
                'roots/content/same' => array( 1, 10 ),
                'roots/plugins/same' => array( 1, 10 ),
                'roots/uploads/cache/old' => array( 1, 10 ),
                'roots/uploads/cache-other/old' => array( 1, 10 ),
            ),
            array()
        );
        foreach ( array( false, true ) as $merge ) {
            $counts = $this->compare( array( 'backup_format' => 2, 'complete' => true, $key => array( 'roots/content', 'roots/uploads/cache' ) ), $merge );
            $this->assertSame( 2, $counts['tombstones'] );
            $this->assertSame( 2, $counts['carried'] );
            $this->assertSame( array( 'roots/plugins/same', 'roots/uploads/cache-other/old' ), array_keys( $this->list->read( $this->dir . '/tombs', $this->digest, true ) ) );
        }
    }

    public function test_parent_digest_mismatch_refuses_append_before_output() {
        $this->seed( array( 'roots/uploads/old' => array( 1, 10 ) ), array() );
        $this->list->write( $this->dir . '/parent', str_repeat( 'b', 64 ), array( 'roots/uploads/old' => array( 1, 10 ) ) );
        foreach ( array( false, true ) as $merge ) {
            file_put_contents( $this->dir . '/changes', 'unchanged changes' );
            file_put_contents( $this->dir . '/tombs', 'unchanged tombstones' );
            $caught = false;
            try {
                $this->compare( array( 'backup_format' => 2, 'complete' => true ), $merge );
            } catch ( MainWP_Exception $e ) {
                $caught = true;
            }
            $this->assertTrue( $caught );
            $this->assertSame( 'unchanged changes', file_get_contents( $this->dir . '/changes' ) );
            $this->assertSame( 'unchanged tombstones', file_get_contents( $this->dir . '/tombs' ) );
        }
    }

    public function test_headerless_parent_refuses_append_instead_of_reparenting() {
        $this->seed( array(), array() );
        file_put_contents( $this->dir . '/parent', "wp-content/uploads/old\t1\t10\n" );
        $this->expectException( MainWP_Exception::class );
        $this->compare( array( 'backup_format' => 2, 'complete' => true ) );
    }

    public function test_v2_entry_is_private_and_refuses_unversioned_coverage() {
        $method = new ReflectionMethod( MainWP_Child_Backup_Layout_List::class, 'diff_walk' );
        $this->assertTrue( $method->isPrivate() );
        $this->seed( array(), array() );
        $this->expectException( MainWP_Exception::class );
        $this->compare( array( 'complete' => true ) );
    }
}
