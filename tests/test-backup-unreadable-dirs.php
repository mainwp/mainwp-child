<?php
/**
 * Unreadable coverage and archive traversal regressions.
 *
 * @package Mainwp_Child
 */

namespace MainWP\Child {
    class Backup_Unreadable_Test_FS {
        public static $glob_fail = array();
        public static $scandir_fail = array();
    }

    function glob( $pattern, $flags = 0 ) {
        return isset( Backup_Unreadable_Test_FS::$glob_fail[ $pattern ] ) ? false : \glob( $pattern, $flags );
    }

    function scandir( $directory, $sorting_order = SCANDIR_SORT_ASCENDING, $context = null ) {
        if ( isset( Backup_Unreadable_Test_FS::$scandir_fail[ rtrim( $directory, '/' ) ] ) ) {
            return false;
        }
        return null === $context ? \scandir( $directory, $sorting_order ) : \scandir( $directory, $sorting_order, $context );
    }
}

namespace {
    use MainWP\Child\Backup_Unreadable_Test_FS;
    use MainWP\Child\MainWP_Backup;
    use MainWP\Child\MainWP_Child_Backup_File_Walker;
    use MainWP\Child\MainWP_Child_Backup_Path_Cover;
    use MainWP\Child\MainWP_Child_Backup_Task_Runner;
    use MainWP\Child\Tar_Archiver;

    class Unreadable_Cover_Runner extends MainWP_Child_Backup_Task_Runner {
        public $root;
        protected function site_root() { return $this->root . '/'; }
        protected function dump_heartbeat() { return static function () {}; }
        public function record( &$state, $path, $reason = 'unreadable' ) { $this->merge_skipped_path( $state, $path, $reason ); }
        public function walk_options( $state ) { return $this->manifest_walk_params( array(), array(), false, $state ); }
        public function diff_lists( $parent, $current, $changes, $tombs, $state ) { return $this->run_incremental_diff( $parent, $current, $changes, $tombs, $state['_unreadable'] ?? array() ); }
        public function archive() { return $this->run_file_archiver( array(), 'fixture.zip', false, time() + 60, array(), true ); }
    }

    class Unreadable_Sliced_Walker extends MainWP_Child_Backup_File_Walker {
        public $steps = 3;
        protected function chunk_size() { return 1; }
        protected function walk_budget_exhausted( $deadline ) { return PHP_INT_MAX !== $deadline && --$this->steps < 0; }
        public function list_directory( $path, $root = false ) { return $this->enumerate_children( $path, $root ); }
        public function recover( $dir ) { $this->reset_for_recovery( $dir . '/files.list.staged', $dir . '/paths.cache.staged' ); }
    }

    class BackupUnreadableDirsTest extends \WP_UnitTestCase {
        private $dir;
        private $root;
        private $out;
        private $runner;
        private $skips;
        private $listener;

        public function set_up() {
            parent::set_up();
            $this->dir = sys_get_temp_dir() . '/mainwp-unreadable-' . uniqid();
            $this->root = $this->dir . '/site';
            $this->out = $this->dir . '/out';
            mkdir( $this->root, 0755, true );
            mkdir( $this->out );
            $this->runner = new Unreadable_Cover_Runner();
            $this->runner->root = $this->root;
            $this->skips = array();
            $this->listener = function ( $path, $reason ) { $this->skips[] = array( $path, $reason ); };
            add_action( 'mainwp_child_backup_skipped_path', $this->listener, 10, 2 );
        }

        public function tear_down() {
            Backup_Unreadable_Test_FS::$glob_fail = array();
            Backup_Unreadable_Test_FS::$scandir_fail = array();
            remove_action( 'mainwp_child_backup_skipped_path', $this->listener, 10 );
            MainWP_Backup::reset_skipped_paths();
            $this->remove_tree( $this->dir );
            parent::tear_down();
        }

        private function remove_tree( $dir ) {
            chmod( $dir, 0755 );
            foreach ( scandir( $dir ) as $name ) {
                if ( '.' === $name || '..' === $name ) { continue; }
                $path = $dir . '/' . $name;
                if ( is_dir( $path ) && ! is_link( $path ) ) { $this->remove_tree( $path ); } else { unlink( $path ); }
            }
            rmdir( $dir );
        }

        private function state() {
            return array( '_skip_report' => array( 'seen' => array(), 'skipped_count' => 0, 'skipped_paths' => array(), 'skipped_by_reason' => array() ) );
        }

        private function lock_directory( $path ) {
            if ( function_exists( 'posix_geteuid' ) && 0 === posix_geteuid() ) { $this->markTestSkipped( 'Root can list chmod 000 directories.' ); }
            $this->assertTrue( chmod( $path, 0000 ) );
            clearstatcache( true, $path );
            if ( false !== @scandir( $path ) ) {
                chmod( $path, 0755 );
                $this->markTestSkipped( 'This filesystem still permits listing chmod 000 directories.' );
            }
        }

        public static function prefix_cases() {
            return array(
                'itself' => array( 'a/b', true ),
                'child' => array( 'a/b/x', true ),
                'deep child' => array( 'a/b/c/d', true ),
                'sibling' => array( 'a/bc/x', false ),
                'parent' => array( 'a', false ),
                'case alias' => array( 'A/B/f.txt', true ),
                'case sibling' => array( 'A/BC/f.txt', false ),
                'path aliases' => array( './a//b/x', true ),
            );
        }

        /** @dataProvider prefix_cases */
        public function test_unreadable_prefix_is_slash_anchored_and_case_folded( $path, $covered ) {
            $this->assertSame( $covered, MainWP_Child_Backup_Path_Cover::contains( $path, MainWP_Child_Backup_Path_Cover::prefix_set( array( 'a/B' ) ) ) );
        }

        public function test_walker_chmod_top_level_and_nested_directories_are_skips() {
            mkdir( $this->root . '/top' );
            mkdir( $this->root . '/readable/nested', 0755, true );
            file_put_contents( $this->root . '/top/hidden.txt', 'keep' );
            file_put_contents( $this->root . '/readable/nested/hidden.txt', 'keep' );
            try {
                $this->lock_directory( $this->root . '/top' );
                $this->lock_directory( $this->root . '/readable/nested' );
                $walker = new MainWP_Child_Backup_File_Walker( $this->root );
                $walker->emit_sidecars( $this->out );
                $this->assertSame( array( array( $this->root . '/readable/nested', 'unreadable' ), array( $this->root . '/top', 'unreadable' ) ), $this->skips );
                $this->assertSame( '', file_get_contents( $this->out . '/files.list.staged' ) );
            } finally {
                chmod( $this->root . '/top', 0755 );
                chmod( $this->root . '/readable/nested', 0755 );
            }
        }

        public static function root_failures() { return array( 'glob false' => array( 'glob' ), 'scandir false' => array( 'scandir' ) ); }

        /** @dataProvider root_failures */
        public function test_unreadable_root_records_skip_in_walker_and_native_archivers( $failure ) {
            file_put_contents( $this->root . '/live.txt', 'keep' );
            if ( 'glob' === $failure ) { Backup_Unreadable_Test_FS::$glob_fail[ $this->root . '/*' ] = true; }
            else { Backup_Unreadable_Test_FS::$scandir_fail[ $this->root ] = true; }
            ( new MainWP_Child_Backup_File_Walker( $this->root ) )->emit_sidecars( $this->out );
            $backup = new MainWP_Backup();
            $backup->set_native_task( true );
            $this->assertSame( array(), $backup->glob_backup_nodes( $this->root ) );
            $this->assertSame( array_fill( 0, 2, array( $this->root, 'unreadable' ) ), $this->skips );
            $state = $this->state();
            foreach ( $this->skips as $skip ) { $this->runner->record( $state, $skip[0], $skip[1] ); }
            $this->assertTrue( $state['_unreadable']['unlisted'] );
            $this->assertTrue( $this->runner->walk_options( $state )['unarchived_overflow'] );
            $this->assertSame( 1, $state['_skip_report']['skipped_count'] );
        }

        public static function root_archive_engines() { return array( 'ZIP' => array( 'zip' ), 'tar' => array( 'tar' ) ); }

        /** @dataProvider root_archive_engines */
        public function test_native_archive_root_glob_failure_records_and_completes( $engine ) {
            if ( 'zip' === $engine && ! class_exists( 'ZipArchive' ) ) { $this->markTestSkipped( 'ZipArchive is unavailable.' ); }
            $backup = new class() extends MainWP_Backup {
                public $fixture_root;
                public function glob_backup_nodes( $dir ) { return parent::glob_backup_nodes( $this->fixture_root ); }
                public function get_or_create_db_dump_files( $prefix, &$archiver = null ) { return array(); }
                public function add_config() { $this->zip->addFromString( 'clone/config.txt', 'fixture marker' ); }
            };
            $backup->fixture_root = $this->root;
            $backup->set_native_task( true );
            Backup_Unreadable_Test_FS::$glob_fail[ $this->root . '/*' ] = true;
            if ( 'zip' === $engine ) {
                $this->assertTrue( $backup->create_zip_full_backup( $this->out . '/root.zip', array( '.htaccess' ), true, true, false, false ) );
            } else {
                $tar = new Tar_Archiver( $backup );
                $this->assertTrue( $tar->create_full_backup( $this->out . '/root.tar', array( '.htaccess' ), false, true, false, false ) );
            }
            $this->assertSame( array( array( $this->root, 'unreadable' ) ), $this->skips );
            $this->assertSame( 0, $backup->get_failed_add_count() );
            $this->assertFileExists( $this->out . '/root.' . $engine );
        }

        public function test_walker_vanished_directory_and_readable_empty_root_are_not_skips() {
            $walker = new Unreadable_Sliced_Walker( $this->root );
            Backup_Unreadable_Test_FS::$scandir_fail[ $this->root . '/vanished' ] = true;
            $this->assertSame( array(), $walker->list_directory( $this->root . '/vanished' ) );
            $this->assertSame( array(), $walker->list_directory( $this->root . '/', true ) );
            $this->assertSame( array(), $this->skips );
        }

        public function test_chmod_unreadable_root_is_distinguished_from_empty_root() {
            try {
                $this->lock_directory( $this->root );
                ( new MainWP_Child_Backup_File_Walker( $this->root ) )->emit_sidecars( $this->out );
                $this->assertSame( array( array( $this->root, 'unreadable' ) ), $this->skips );
            } finally {
                chmod( $this->root, 0755 );
            }
        }

        public function test_resumed_walk_transient_unreadability_keeps_earlier_rows_and_recovery_dedupes() {
            mkdir( $this->root . '/locked' );
            foreach ( array( 'a.txt', 'b.txt', 'c.txt' ) as $file ) { file_put_contents( $this->root . '/locked/' . $file, 'live' ); }
            $state = $this->state();
            $bridge = function ( $path, $reason ) use ( &$state ) { $this->runner->record( $state, $path, $reason ); };
            add_action( 'mainwp_child_backup_skipped_path', $bridge, 10, 2 );
            try {
                $walker = new Unreadable_Sliced_Walker( $this->root );
                $walker->steps = 2;
                $out = $walker->emit_sidecars_slice( $this->out, null, time() + 60 );
                $this->assertFalse( $out['done'] );
                $this->assertSame( 1, $out['cursor']['count'] );
                Backup_Unreadable_Test_FS::$scandir_fail[ $this->root . '/locked' ] = true;
                $out = ( new Unreadable_Sliced_Walker( $this->root ) )->emit_sidecars_slice( $this->out, $out['cursor'], time() + 60 );
                Backup_Unreadable_Test_FS::$scandir_fail = array();
                for ( $i = 0; empty( $out['done'] ) && $i < 20; ++$i ) {
                    $out = ( new Unreadable_Sliced_Walker( $this->root ) )->emit_sidecars_slice( $this->out, $out['cursor'], time() + 60 );
                }
                $this->assertTrue( $out['done'] );
                $this->assertStringContainsString( "locked/a.txt\t", file_get_contents( $this->out . '/files.list.staged' ) );
                $this->assertStringNotContainsString( "locked/b.txt\t", file_get_contents( $this->out . '/files.list.staged' ) );
                Backup_Unreadable_Test_FS::$scandir_fail[ $this->root ] = true;
                $walker->recover( $this->out );
                $walker->recover( $this->out );
                $this->assertSame( 2, $state['_skip_report']['skipped_count'] );
                $this->assertSame( array( 'locked' ), array_values( $state['_unreadable']['paths'] ) );
                $this->assertTrue( $state['_unreadable']['unlisted'] );
            } finally {
                remove_action( 'mainwp_child_backup_skipped_path', $bridge, 10 );
            }
        }

        public function test_unreadable_store_is_bounded_deduped_and_independent_of_skip_seen() {
            $state = $this->state();
            for ( $i = 0; $i < 1002; ++$i ) {
                $path = $this->root . '/dir-' . $i;
                mkdir( $path );
                $this->runner->record( $state, $path );
                $this->runner->record( $state, $path );
            }
            $this->assertCount( 1001, $state['_unreadable']['paths'] );
            $this->assertTrue( $state['_unreadable']['unlisted'] );
            $this->assertSame( 1002, $state['_skip_report']['skipped_count'] );
            $this->assertCount( 100, $state['_skip_report']['skipped_paths'] );
            $this->assertSame( array( 'unreadable' => 1002 ), $state['_skip_report']['skipped_by_reason'] );
            $this->assertTrue( $this->runner->walk_options( $state )['unarchived_overflow'] );
            $path = $this->root . '/seen';
            mkdir( $path );
            $state = $this->state();
            $this->runner->record( $state, $path, 'unsafe' );
            $this->runner->record( $state, $path );
            $this->assertSame( 1, $state['_skip_report']['skipped_count'] );
            $this->assertSame( array( 'seen' ), array_values( $state['_unreadable']['paths'] ) );
        }

        public function test_unreadable_invalid_relative_directory_sets_unlisted() {
            mkdir( $this->dir . '/outside' );
            foreach ( array( $this->root, $this->dir . '/outside', $this->root . '/../outside' ) as $path ) {
                $state = $this->state();
                $this->runner->record( $state, $path );
                $this->assertTrue( $state['_unreadable']['unlisted'], $path );
                $walk = $this->runner->walk_options( $state );
                $this->assertTrue( $walk['unarchived_overflow'] );
                $this->assertArrayNotHasKey( 'unarchived', $walk );
            }
        }

        public function test_unreadable_non_utf8_directory_sets_unlisted_without_exposing_path() {
            $path = $this->root . "/bad-\xff";
            if ( ! @mkdir( $path ) ) { $this->markTestSkipped( 'This filesystem rejects non-UTF-8 names.' ); }
            $state = $this->state();
            $this->runner->record( $state, $path );
            $this->assertTrue( $state['_unreadable']['unlisted'] );
            $this->assertSame( array(), $state['_unreadable']['paths'] );
            $this->assertSame( array(), $state['_skip_report']['skipped_paths'] );
            $this->assertIsString( wp_json_encode( $state ) );
        }

        public static function diff_selection_cases() {
            $cases = array();
            foreach ( array( 'map', 'sorted' ) as $algorithm ) {
                foreach ( array( 'prefix', '1001 prefixes', 'unlisted', 'combined pack overflow' ) as $coverage ) {
                    $cases[ $algorithm . ' ' . $coverage ] = array( $algorithm, $coverage );
                }
            }
            return $cases;
        }

        /** @dataProvider diff_selection_cases */
        public function test_task_diff_uses_unreadable_coverage_before_combined_walk_overflow( $algorithm, $coverage ) {
            $state = $this->state();
            $state['_unreadable'] = array( 'paths' => array( 'locked' ), 'unlisted' => 'unlisted' === $coverage );
            if ( '1001 prefixes' === $coverage ) {
                for ( $i = 0; $i < 1000; ++$i ) { $state['_unreadable']['paths'][] = 'other-' . $i; }
            }
            if ( 'combined pack overflow' === $coverage ) {
                for ( $i = 0; $i < 1000; ++$i ) { $state['_pack_skips']['paths'][] = 'pack-' . $i; }
            }
            $parent = $this->out . '/parent.list';
            $handle = fopen( $parent, 'wb' );
            fwrite( $handle, "locked/live.txt\t1\t1\n" );
            $count = 'sorted' === $algorithm ? 40000 : 1;
            for ( $i = 0; $i < $count; ++$i ) { fwrite( $handle, 'outside/' . sprintf( '%06d', $i ) . str_repeat( 'x', 100 ) . "\t1\t1\n" ); }
            fclose( $handle );
            $this->assertSame( 'sorted' === $algorithm, filesize( $parent ) > 4 * 1024 * 1024 );
            file_put_contents( $this->out . '/current.list', '' );
            $result = $this->runner->diff_lists( $parent, $this->out . '/current.list', $this->out . '/changes', $this->out . '/tombs', $state );
            $all = in_array( $coverage, array( '1001 prefixes', 'unlisted' ), true );
            $this->assertFalse( $result['force_full'] );
            $this->assertSame( $all ? 0 : $count, $result['tombstones'] );
            $this->assertStringNotContainsString( 'locked/live.txt', file_get_contents( $this->out . '/tombs' ) );
            $this->assertSame( '', file_get_contents( $this->out . '/changes' ) );
            $walk = $this->runner->walk_options( $state );
            if ( 'prefix' === $coverage ) { $this->assertSame( array( 'locked' ), $walk['unarchived'] ); }
            else { $this->assertTrue( $walk['unarchived_overflow'] ); $this->assertArrayNotHasKey( 'unarchived', $walk ); }
        }

        public static function archive_modes() { return array( 'native' => array( true ), 'legacy' => array( false ) ); }

        /** @dataProvider archive_modes */
        public function test_tar_nested_unreadable_is_recorded_in_both_modes( $native ) {
            mkdir( $this->root . '/locked' );
            file_put_contents( $this->root . '/readable.txt', 'readable payload' );
            file_put_contents( $this->root . '/locked/hidden.txt', 'hidden payload' );
            $backup = new MainWP_Backup();
            if ( $native ) { $backup->set_native_task( true ); }
            $tar = new Tar_Archiver( $backup );
            try {
                $this->lock_directory( $this->root . '/locked' );
                $tar->create( $this->out . '/nested.tar' );
                $tar->add_dir( $this->root, array() );
            } finally {
                chmod( $this->root . '/locked', 0755 );
                $tar->close();
            }
            $this->assertSame( array( array( $this->root . '/locked', 'unreadable' ) ), $this->skips );
            $bytes = file_get_contents( $this->out . '/nested.tar' );
            $this->assertStringContainsString( 'readable payload', $bytes );
            $this->assertStringNotContainsString( 'hidden payload', $bytes );
            $this->assertSame( 0, $backup->get_failed_add_count() );
        }

        /** @dataProvider archive_modes */
        public function test_tar_top_level_unreadable_only_native_continues( $native ) {
            mkdir( $this->root . '/locked' );
            $backup = new MainWP_Backup();
            if ( $native ) { $backup->set_native_task( true ); }
            $tar = new Tar_Archiver( $backup );
            $thrown = false;
            try {
                $this->lock_directory( $this->root . '/locked' );
                $tar->create( $this->out . '/top.tar' );
                try { $tar->add_dir( $this->root . '/locked', array() ); }
                catch ( \UnexpectedValueException $e ) { $thrown = true; }
            } finally {
                chmod( $this->root . '/locked', 0755 );
                $tar->close();
            }
            $this->assertSame( ! $native, $thrown );
            $this->assertSame( $native ? array( array( $this->root . '/locked', 'unreadable' ) ) : array(), $this->skips );
            $this->assertSame( 0, $backup->get_failed_add_count() );
        }

        /** @dataProvider archive_modes */
        public function test_zip_unreadable_only_native_continues( $native ) {
            mkdir( $this->root . '/locked' );
            $backup = new class() extends MainWP_Backup {
                public function __construct() { $this->zip = new class() { public function add_empty_dir( $path ) {} }; }
            };
            if ( $native ) { $backup->set_native_task( true ); }
            $thrown = false;
            try {
                $this->lock_directory( $this->root . '/locked' );
                try { $backup->zip_add_dir( $this->root . '/locked', array() ); }
                catch ( \UnexpectedValueException $e ) { $thrown = true; }
            } finally {
                chmod( $this->root . '/locked', 0755 );
            }
            $this->assertSame( ! $native, $thrown );
            $this->assertSame( $native ? array( array( $this->root . '/locked', 'unreadable' ) ) : array(), $this->skips );
            $this->assertSame( 0, $backup->get_failed_add_count() );
        }

        public function test_native_pcl_copy_unreadable_directory_is_recorded_without_failed_add() {
            mkdir( $this->root . '/locked' );
            $backup = new MainWP_Backup();
            $backup->set_native_task( true );
            $bases = new \ReflectionProperty( MainWP_Backup::class, 'allowed_backup_bases' );
            $bases->setAccessible( true );
            $bases->setValue( $backup, array( $this->root ) );
            try {
                $this->lock_directory( $this->root . '/locked' );
                $backup->copy_dir( array( $this->root . '/locked' ), array(), $this->out, false, array( $this->root ) );
            } finally {
                chmod( $this->root . '/locked', 0755 );
            }
            $this->assertSame( array( array( $this->root . '/locked', 'unreadable' ) ), $this->skips );
            $this->assertSame( 0, $backup->get_failed_add_count() );
        }

        public function test_native_task_flag_reaches_backup_and_tar_and_is_reset_after_throw() {
            $instance = new \ReflectionProperty( MainWP_Backup::class, 'instance' );
            $instance->setAccessible( true );
            $old = $instance->getValue();
            $backup = new class() extends MainWP_Backup {
                public $observed;
                public function create_full_backup( $excludes, $filePrefix = '', $addConfig = false, $includeCoreFiles = false, $file_descriptors = 0, $fileSuffix = false, $excludezip = false, $excludenonwp = false, $loadFilesBeforeZip = true, $ext = 'zip', $pid = false, $append = false ) {
                    $tar = new Tar_Archiver( $this );
                    $owner = new \ReflectionProperty( Tar_Archiver::class, 'backup' );
                    $owner->setAccessible( true );
                    $this->observed = $owner->getValue( $tar )->is_native_task();
                    throw new \RuntimeException( 'Archive boundary.' );
                }
            };
            try {
                $instance->setValue( null, $backup );
                try { $this->runner->archive(); $this->fail( 'Expected archive boundary exception.' ); }
                catch ( \RuntimeException $e ) { $this->assertSame( 'Archive boundary.', $e->getMessage() ); }
                $this->assertTrue( $backup->observed );
                $this->assertFalse( $backup->is_native_task() );
            } finally {
                $instance->setValue( null, $old );
            }
        }
    }
}
