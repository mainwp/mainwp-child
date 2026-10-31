<?php
/**
 * Archive pathname spelling regressions.
 *
 * @package Mainwp_Child
 */
use MainWP\Child\MainWP_Child_Backup_Layout;
use MainWP\Child\MainWP_Child_Backup_Layout_Codec;
use MainWP\Child\MainWP_Exception;

require_once __DIR__ . '/includes/trait-tar-entry-fixture.php';

class BackupLayoutPathSpellingsTest extends WP_UnitTestCase {
    use Tar_Entry_Fixture;
    private $dir;

    public function set_up() {
        parent::set_up();
        $this->dir = sys_get_temp_dir() . '/mwp-path-spellings-' . uniqid();
        mkdir( $this->dir );
        mkdir( $this->dir . '/scratch' );
    }

    public function tear_down() {
        $files = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $this->dir, FilesystemIterator::SKIP_DOTS ), RecursiveIteratorIterator::CHILD_FIRST );
        foreach ( $files as $file ) {
            $file->isDir() && ! $file->isLink() ? rmdir( $file->getPathname() ) : unlink( $file->getPathname() );
        }
        rmdir( $this->dir );
        parent::tear_down();
    }

    public static function spellings() {
        return array( 'plain' => array( '' ), 'double-slash' => array( '/' ), 'dot-segment' => array( '/.' ), 'trailing-separators' => array( '//' ) );
    }

    private function descriptor() {
        return MainWP_Child_Backup_Layout::descriptor( array( array( 'role' => 'plugins', 'coverage' => 'restore' ) ), str_repeat( 'a', 64 ) );
    }

    private function archive( $member = 'roots/plugins/file', $type = '0' ) {
        $tar = $this->tar_entry_bytes( 'mainwp-layout.json', MainWP_Child_Backup_Layout::metadata_bytes( $this->descriptor() ) );
        $tar .= $this->tar_entry_bytes( $member, 'first member', $type, 'outside' );
        $tar .= $this->tar_entry_bytes( 'roots/plugins/second', 'second member' );
        file_put_contents( $this->dir . '/backup.mwp2', "MWPBACKUP2\n" . $tar );
    }

    /** @dataProvider spellings */
    public function test_normal_read_preserves_member_identities( $suffix ) {
        $this->archive();
        $result = ( new MainWP_Child_Backup_Layout_Codec() )->read_slice( $this->dir . '/backup.mwp2', $this->dir . '/scratch' . $suffix, MainWP_Child_Backup_Layout::digest( $this->descriptor() ), array(), PHP_FLOAT_MAX );
        $this->assertTrue( $result['done'] );
        $this->assertSame( $this->descriptor(), $result['descriptor'] );
        $this->assert_members();
    }

    /** @dataProvider spellings */
    public function test_resumed_extraction_preserves_member_identities( $suffix ) {
        $this->archive();
        $codec = new MainWP_Child_Backup_Layout_Codec();
        $digest = MainWP_Child_Backup_Layout::digest( $this->descriptor() );
        $first = $codec->read_slice( $this->dir . '/backup.mwp2', $this->dir . '/scratch' . $suffix, $digest, array(), 0 );
        $this->assertFalse( $first['done'] );
        $this->assertTrue( $first['cursor']['validated'] );
        $this->assertGreaterThan( 0, $first['cursor']['extract_cursor']['entry_index'] );
        $second = $codec->read_slice( $this->dir . '/backup.mwp2', $this->dir . '/scratch' . $suffix, $digest, $first['cursor'], PHP_FLOAT_MAX );
        $this->assertTrue( $second['done'] );
        $this->assertSame( $first['descriptor'], $second['descriptor'] );
        $this->assert_members();
    }

    /** @dataProvider spellings */
    public function test_scratch_link_is_refused_before_writing( $suffix ) {
        $this->archive();
        symlink( $this->dir . '/scratch', $this->dir . '/linked-scratch' );
        try {
            ( new MainWP_Child_Backup_Layout_Codec() )->read_slice( $this->dir . '/backup.mwp2', $this->dir . '/linked-scratch' . $suffix, MainWP_Child_Backup_Layout::digest( $this->descriptor() ), array(), PHP_FLOAT_MAX );
            $this->fail( 'Linked scratch was accepted.' );
        } catch ( MainWP_Exception $error ) {
            $this->assertSame( 'mainwp_backup_file_corrupt', $error->get_message_error_code() );
        }
        $this->assertSame( array( '.', '..' ), scandir( $this->dir . '/scratch' ) );
    }

    private function assert_members() {
        $root = $this->dir . '/scratch/extracted';
        $this->assertSame( 'first member', file_get_contents( $root . '/roots/plugins/file' ) );
        $this->assertSame( 'second member', file_get_contents( $root . '/roots/plugins/second' ) );
        $this->assertSame( array( 'file', 'second' ), array_values( array_diff( scandir( $root . '/roots/plugins' ), array( '.', '..' ) ) ) );
        $this->assertFileDoesNotExist( $root . '/mainwp-layout.json' );
    }

    public static function unsupported_member_names() {
        return array(
            'filename' => array( 'roots/plugins/a\\b.php' ),
            'directory' => array( 'roots/plugins/a\\b/file.php' ),
        );
    }

    /** @dataProvider unsupported_member_names */
    public function test_writer_refuses_unsupported_names_before_creating_files( $identity ) {
        file_put_contents( $this->dir . '/source', 'source bytes' );
        try {
            ( new MainWP_Child_Backup_Layout_Codec() )->write_slice( $this->dir . '/backup.mwp2', $this->descriptor(), array( $identity => $this->dir . '/source' ), array(), PHP_FLOAT_MAX );
            $this->fail( 'An unreadable member name was accepted.' );
        } catch ( MainWP_Exception $error ) {
            $this->assertSame( 'mainwp_backup_file_corrupt', $error->get_message_error_code() );
        }
        $this->assertFileDoesNotExist( $this->dir . '/backup.mwp2' );
        $this->assertFileDoesNotExist( $this->dir . '/backup.mwp2.tar' );
    }

    /** @dataProvider unsupported_member_names */
    public function test_writer_checks_later_names_before_changing_existing_files( $identity ) {
        file_put_contents( $this->dir . '/source', 'source bytes' );
        file_put_contents( $this->dir . '/backup.mwp2', 'existing wrapper' );
        file_put_contents( $this->dir . '/backup.mwp2.tar', 'existing tar' );
        $entries = array( 'roots/plugins/first' => $this->dir . '/source', $identity => $this->dir . '/source' );
        try {
            ( new MainWP_Child_Backup_Layout_Codec() )->write_slice( $this->dir . '/backup.mwp2', $this->descriptor(), $entries, array(), PHP_FLOAT_MAX, 1 );
            $this->fail( 'A later unreadable member name was accepted.' );
        } catch ( MainWP_Exception $error ) {
            $this->assertSame( 'mainwp_backup_file_corrupt', $error->get_message_error_code() );
        }
        $this->assertSame( 'existing wrapper', file_get_contents( $this->dir . '/backup.mwp2' ) );
        $this->assertSame( 'existing tar', file_get_contents( $this->dir . '/backup.mwp2.tar' ) );
    }

    /** @dataProvider unsupported_member_names */
    public function test_reader_refuses_unsupported_names_after_resumed_unwrapping( $identity ) {
        $this->archive( $identity );
        $codec = new MainWP_Child_Backup_Layout_Codec();
        $cursor = array();
        $calls = 0;
        try {
            do {
                $result = $codec->read_slice( $this->dir . '/backup.mwp2', $this->dir . '/scratch', MainWP_Child_Backup_Layout::digest( $this->descriptor() ), $cursor, PHP_FLOAT_MAX, 127 );
                $cursor = $result['cursor'];
                $this->assertLessThan( 100, ++$calls, 'Unwrapping did not finish.' );
            } while ( ! $result['done'] );
            $this->fail( 'An unreadable member name was accepted.' );
        } catch ( MainWP_Exception $error ) {
            $this->assertSame( 'mainwp_backup_file_corrupt', $error->get_message_error_code() );
        }
        $this->assertGreaterThan( 1, $calls );
        $this->assertDirectoryDoesNotExist( $this->dir . '/scratch/extracted' );
    }

    public static function refused_members() {
        $rows = array();
        foreach ( array( '/', '/.' ) as $suffix ) {
            foreach ( array( array( 'roots/alien/file', '0' ), array( 'roots/plugins/../escape', '0' ), array( 'roots/plugins/file', '2' ), array( 'roots/plugins/a\\b.php', '0' ), array( 'roots/plugins/a\\b/file.php', '0' ) ) as $member ) {
                $rows[] = array( $suffix, $member[0], $member[1] );
            }
        }
        return $rows;
    }

    /** @dataProvider refused_members */
    public function test_invalid_members_are_still_refused( $suffix, $name, $type ) {
        $this->archive( $name, $type );
        try {
            ( new MainWP_Child_Backup_Layout_Codec() )->read_slice( $this->dir . '/backup.mwp2', $this->dir . '/scratch' . $suffix, MainWP_Child_Backup_Layout::digest( $this->descriptor() ), array(), PHP_FLOAT_MAX );
            $this->fail( 'Invalid member was accepted.' );
        } catch ( MainWP_Exception $error ) {
            $codes = false !== strpos( $name, '/../' ) ? array( 'mainwp_backup_file_corrupt', 'mainwp_restore_extract' ) : array( 'mainwp_backup_file_corrupt' );
            $this->assertContains( $error->get_message_error_code(), $codes );
        }
        $this->assertFileDoesNotExist( $this->dir . '/scratch/extracted/roots/escape' );
        if ( is_dir( $this->dir . '/scratch/extracted' ) ) {
            $this->assertSame( array( '.', '..' ), scandir( $this->dir . '/scratch/extracted' ) );
        }
    }
}
