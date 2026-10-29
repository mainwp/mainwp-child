<?php
/**
 * Native storage must stay private without changing restored site modes.
 *
 * @package Mainwp_Child
 */

namespace MainWP\Child {
    class Backup_Permissions_Test_FS {
        public static $fail = false;
        public static $attempts = 0;
    }

    function chmod( $path, $mode ) {
        $caller = debug_backtrace( DEBUG_BACKTRACE_IGNORE_ARGS, 2 )[1]['function'] ?? '';
        if ( 'protect_backup_path' === $caller ) {
            ++Backup_Permissions_Test_FS::$attempts;
            if ( Backup_Permissions_Test_FS::$fail ) {
                return false;
            }
        }
        return \chmod( $path, $mode );
    }
}

namespace {
    use MainWP\Child\Backup_Permissions_Test_FS;
    use MainWP\Child\MainWP_Backup;
    use MainWP\Child\MainWP_Backup_Db_Dumper;
    use MainWP\Child\MainWP_Child_Backup_Task;
    use MainWP\Child\MainWP_Child_Backup_Task_Runner;
    use MainWP\Child\MainWP_Child_Backup_Mutex;
    use MainWP\Child\MainWP_Helper;

    require_once __DIR__ . '/test-backup-restore-task-runner.php';

    class Permissions_Task_Runner extends MainWP_Child_Backup_Task_Runner {
        public $dir;
        public $site;

        protected function backup_dir() {
            return $this->dir;
        }

        protected function site_root() {
            return $this->site . '/';
        }

        protected function content_roots() {
            return array( $this->site . '/wp-content' );
        }

        protected function make_walker( $params, $backup_dir ) {
            return new \MainWP\Child\MainWP_Child_Backup_File_Walker( $this->site );
        }

        protected function tier1_available() {
            return false;
        }

        protected function dump_tier2( $prefix, $cursor, $budget ) {
            global $wpdb;
            return MainWP_Backup_Db_Dumper::instance()->dump_tables_tier2_slice( array( $wpdb->options ), $prefix, $cursor, $budget );
        }

        protected function run_file_archiver( $params, $archive_file, $append, $deadline, $db_files, $skip_db = false ) {
            $backup = new MainWP_Backup();
            $backup->set_native_task( true );
            $path = $this->dir . '/' . $archive_file;
            $files = array_merge( $db_files, array( $this->site . '/ordinary.txt' ) );
            if ( 'zip' === $params['ext'] ) {
                $backup->m_zip_file( $files, $path );
            } else {
                $tar = new \MainWP\Child\Tar_Archiver( $backup, $params['ext'] );
                $tar->zip_file( $files, $path );
            }
            return array( 'file' => basename( $path ), 'filesize' => filesize( $path ) );
        }
    }

    class BackupStoragePermissionsTest extends WP_UnitTestCase {
        private $dir;
        private $mask;

        public function set_up() {
            parent::set_up();
            $this->mask = umask( 0022 );
            $this->dir = ABSPATH . 'mainwp-permissions-' . uniqid();
            mkdir( $this->dir . '/storage', 0755, true );
            mkdir( $this->dir . '/site', 0755 );
            file_put_contents( $this->dir . '/site/ordinary.txt', 'site bytes' );
            remove_filter( 'query', array( $this, '_create_temporary_tables' ) );
            remove_filter( 'query', array( $this, '_drop_temporary_tables' ) );
            delete_option( MainWP_Child_Backup_Task::DB_VERSION_OPTION );
            MainWP_Child_Backup_Task::instance()->install();
            add_filter( 'query', array( $this, '_create_temporary_tables' ) );
            add_filter( 'query', array( $this, '_drop_temporary_tables' ) );
            Backup_Permissions_Test_FS::$attempts = 0;
        }

        public function tear_down() {
            Backup_Permissions_Test_FS::$fail = false;
            $holder = MainWP_Child_Backup_Mutex::holder();
            if ( null !== $holder ) {
                MainWP_Child_Backup_Mutex::release( $holder['owner'] );
            }
            global $wpdb;
            $wpdb->query( 'DELETE FROM ' . MainWP_Child_Backup_Task::instance()->get_table_name() );
            $this->remove_tree( $this->dir );
            umask( $this->mask );
            parent::tear_down();
        }

        private function remove_tree( $dir ) {
            foreach ( new DirectoryIterator( $dir ) as $entry ) {
                if ( $entry->isDot() ) { continue; }
                if ( $entry->isDir() ) { $this->remove_tree( $entry->getPathname() ); }
                else { unlink( $entry->getPathname() ); }
            }
            rmdir( $dir );
        }

        private function assert_private_tree( $dir ) {
            clearstatcache();
            $this->assertSame( 0700, fileperms( $dir ) & 0777, $dir );
            foreach ( new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $dir, FilesystemIterator::SKIP_DOTS ), RecursiveIteratorIterator::SELF_FIRST ) as $entry ) {
                $this->assertSame( $entry->isDir() ? 0700 : 0600, $entry->getPerms() & 0777, $entry->getPathname() );
            }
        }

        private function run_backup( $ext = 'tar.gz' ) {
            $runner = new Permissions_Task_Runner();
            $runner->dir = $this->dir . '/storage';
            $runner->site = $this->dir . '/site';
            $id = $runner->create_task_for_params( 'full', array( 'ext' => $ext, 'emit_manifest' => true ) );
            $spine = MainWP_Child_Backup_Task::instance();
            $task = $spine->get_task( $id );
            for ( $i = 0; $i < 15 && ! MainWP_Child_Backup_Task::is_terminal_phase( $task->phase ); ++$i ) {
                $task = $runner->advance_one_slice( $task, 'dashboard' );
            }
            $this->assertSame( 'completed', $task->phase, wp_json_encode( $task ) );
            return $task;
        }

        public static function archive_formats() {
            return array( array( 'tar.gz' ), array( 'zip' ) );
        }

        /** @dataProvider archive_formats */
        public function test_native_full_task_keeps_storage_private( $ext ) {
            $this->run_backup( $ext );
            $this->assert_private_tree( $this->dir . '/storage' );
            $this->assertSame( 0644, fileperms( $this->dir . '/site/ordinary.txt' ) & 0777 );
            $this->assertSame( 0022, umask() );
        }

        public function test_chmod_failure_does_not_fail_backup() {
            Backup_Permissions_Test_FS::$fail = true;
            ob_start();
            try {
                $this->run_backup();
                $output = ob_get_contents();
            } finally {
                ob_end_clean();
                Backup_Permissions_Test_FS::$fail = false;
            }
            $this->assertGreaterThan( 0, Backup_Permissions_Test_FS::$attempts );
            $this->assertSame( '', $output );
            $this->assertSame( 0022, umask() );
        }

        public function test_chunk_receive_and_spilled_sidecar_are_private() {
            $runner = new Testable_Restore_Task_Runner();
            $runner->test_backup_dir = $this->dir . '/storage';
            $runner->test_site_root = $this->dir . '/site';
            $runner->test_content_roots = array( $this->dir . '/site/wp-content' );
            $runner->fake_engine = new Fake_Restore_Engine();
            $raw = 'archive bytes';
            $id = $runner->create_restore_task( 'files', array(
                'restore_id' => 'chunk' . uniqid(),
                'artifacts' => array( array( 'role' => 'full', 'file' => 'received.zip', 'size' => strlen( $raw ), 'sha256' => hash( 'sha256', $raw ) ) ),
            ) );
            $ack = $runner->receive_chunk( $id, 'received.zip', 0, $raw, hash( 'sha256', $raw ), true );
            $this->assertArrayNotHasKey( 'error', $ack );
            $spine = MainWP_Child_Backup_Task::instance();
            $json = $spine->write_sub_state( $id, array( 'large' => str_repeat( 'x', 70000 ) ), $this->dir . '/storage', 100 );
            $this->assertTrue( json_decode( $json, true )['_sidecar'] );
            $this->assert_private_tree( $this->dir . '/storage' );
            $empty = $runner->scratch_dir( $id, $this->dir . '/storage' ) . 'empty.list';
            $this->assertNull( \MainWP\Child\MainWP_Child_Chunk_Append_Helper::append_chunk( $empty, '', 0 ) );
            $this->assertSame( 0600, fileperms( $empty ) & 0777 );
        }

        public function test_database_restore_scratch_is_private() {
            $path = $this->dir . '/storage/database.zip';
            $sql = '<?php exit(); ?>' . "\n" . '-- mainwp-backup-info: {"site_url":"' . site_url() . '","home_url":"' . home_url() . '","table_prefix":"src_"}' . "\n"
                . '-- mainwp-backup-meta: {"has_foreign_keys":false,"tier":1}' . "\nDROP TABLE IF EXISTS `src_items`;\nCREATE TABLE `src_items` (id INT);\nINSERT INTO `src_items` (`id`) VALUES (1),(2);\n";
            $zip = new ZipArchive();
            $zip->open( $path, ZipArchive::CREATE );
            $zip->addEmptyDir( 'empty/nested' );
            $zip->addFromString( 'dbBackup-src_items.sql.php', $sql );
            $zip->close();
            $runner = new Testable_Restore_Task_Runner();
            $runner->test_backup_dir = $this->dir . '/storage';
            $runner->test_site_root = $this->dir . '/site';
            $runner->fake_engine = new Fake_Restore_Engine();
            $runner->pause_before_phase = MainWP_Child_Backup_Task::PHASE_SWAPPING_DB;
            $id = $runner->create_restore_task( 'db', array(
                'restore_id' => 'dbperm' . uniqid(),
                'source_prefix' => 'src_',
                'artifacts' => array( array( 'role' => 'db', 'file' => 'database.zip', 'size' => filesize( $path ), 'sha256' => hash_file( 'sha256', $path ) ) ),
            ) );
            $spine = MainWP_Child_Backup_Task::instance();
            $task = $spine->get_task( $id );
            for ( $i = 0; $i < 15 && MainWP_Child_Backup_Task::PHASE_SWAPPING_DB !== $task->phase && ! MainWP_Child_Backup_Task::is_terminal_phase( $task->phase ); ++$i ) {
                $task = $runner->advance_one_slice( $task, 'dashboard' );
            }
            $this->assertSame( MainWP_Child_Backup_Task::PHASE_SWAPPING_DB, $task->phase, wp_json_encode( $task ) );
            $scratch = $runner->scratch_dir( $id, $this->dir . '/storage' );
            $this->assertFileExists( $scratch . 'db/dbBackup-src_items.sql.php' );
            $this->assertDirectoryExists( $scratch . 'db/empty/nested' );
            $this->assert_private_tree( $scratch );
            $this->assertSame( 0022, umask() );
        }

        public function test_incremental_parts_and_tar_aliases_are_private() {
            $storage = $this->dir . '/storage';
            $writer = new \MainWP\Child\MainWP_Child_Backup_Incremental_Zip_Writer( $storage, 'core' );
            $writer->open_new_part( 1 );
            $writer->add_file( $this->dir . '/site/ordinary.txt', 'ordinary.txt' );
            $part = $writer->close_part();
            $this->assertSame( 0600, fileperms( $storage . '/' . $part['file'] ) & 0777 );
            $archive = $storage . '/pieces.test.part001.tar.gz';
            $backup = new MainWP_Backup();
            $backup->set_native_task( true );
            $tar = new \MainWP\Child\Tar_Archiver( $backup, 'tar.gz' );
            $this->assertTrue( $tar->zip_file( array( $this->dir . '/site/ordinary.txt' ), $archive ) );
            $method = new ReflectionMethod( \MainWP\Child\MainWP_Child_Backup_File_Restorer::class, 'plain_tar_for' );
            $method->setAccessible( true );
            $timed_out = false;
            $args = array( $archive, microtime( true ) + 30, &$timed_out );
            $plain = $method->invokeArgs( new \MainWP\Child\MainWP_Child_Backup_File_Restorer(), $args );
            $this->assertNotNull( $plain );
            $this->assertFalse( $timed_out );
            $this->assertSame( 0600, fileperms( $plain ) & 0777 );
        }

        public function test_private_umask_is_restored_when_creation_throws() {
            $path = $this->dir . '/storage/interrupted.tmp';
            try {
                MainWP_Helper::protect_backup_path( $path, static function () use ( $path ) {
                    file_put_contents( $path, 'partial bytes' );
                    throw new RuntimeException( 'interrupted' );
                } );
                $this->fail( 'The original exception must propagate.' );
            } catch ( RuntimeException $e ) {
                $this->assertSame( 'interrupted', $e->getMessage() );
            }
            $this->assertSame( 0022, umask() );
            $this->assertSame( 0600, fileperms( $path ) & 0777 );
        }

        public static function chmod_outcomes() {
            return array( 'allowed' => array( false ), 'refused' => array( true ) );
        }

        /** @dataProvider chmod_outcomes */
        public function test_restore_staging_private_and_site_modes_unchanged( $fail ) {
            Backup_Permissions_Test_FS::$fail = $fail;
            $path = $this->dir . '/storage/files.zip';
            $zip = new ZipArchive();
            $zip->open( $path, ZipArchive::CREATE );
            $zip->addFromString( 'wp-content/uploads/restored.txt', 'restored bytes' );
            $zip->close();
            $runner = new Testable_Restore_Task_Runner();
            $runner->test_backup_dir = $this->dir . '/storage';
            $runner->test_site_root = $this->dir . '/site';
            $runner->test_content_roots = array( $this->dir . '/site/wp-content' );
            $runner->fake_engine = new Fake_Restore_Engine();
            $runner->pause_before_phase = MainWP_Child_Backup_Task::PHASE_POST_RESTORE_VERIFY;
            $id = $runner->create_restore_task( 'files', array(
                'restore_id' => 'perm' . uniqid(),
                'source_prefix' => 'src_',
                'artifacts' => array( array( 'role' => 'full', 'file' => 'files.zip', 'size' => filesize( $path ), 'sha256' => hash_file( 'sha256', $path ) ) ),
            ) );
            $spine = MainWP_Child_Backup_Task::instance();
            $task = $runner->advance_one_slice( $spine->get_task( $id ), 'dashboard' );
            for ( $i = 0; $i < 15 && MainWP_Child_Backup_Task::PHASE_POST_RESTORE_VERIFY !== $task->phase && ! MainWP_Child_Backup_Task::is_terminal_phase( $task->phase ); ++$i ) {
                $task = $runner->advance_one_slice( $task, 'dashboard' );
            }
            $this->assertSame( MainWP_Child_Backup_Task::PHASE_POST_RESTORE_VERIFY, $task->phase, wp_json_encode( $task ) );
            if ( $fail ) {
                $this->assertGreaterThan( 0, Backup_Permissions_Test_FS::$attempts );
            } else {
                $this->assert_private_tree( $runner->scratch_dir( $id, $this->dir . '/storage' ) );
            }
            $restored = $this->dir . '/site/wp-content/uploads/restored.txt';
            $this->assertSame( 'restored bytes', file_get_contents( $restored ) );
            $this->assertSame( 0644, fileperms( $restored ) & 0777 );
            for ( $i = 0; $i < 15 && ! MainWP_Child_Backup_Task::is_terminal_phase( $task->phase ); ++$i ) {
                $task = $runner->advance_one_slice( $task, 'dashboard' );
            }
            $this->assertSame( 'completed', $task->phase, wp_json_encode( $task ) );
        }
    }
}
