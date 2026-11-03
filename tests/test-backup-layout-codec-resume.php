<?php
/**
 * Resumable private codec validation and exact qualified member names.
 *
 * @package Mainwp_Child
 */
use MainWP\Child\MainWP_Child_Backup_Layout;
use MainWP\Child\MainWP_Child_Backup_Layout_Codec;
use MainWP\Child\MainWP_Exception;

require_once __DIR__ . '/includes/trait-tar-entry-fixture.php';

class BackupLayoutCodecResumeTest extends WP_UnitTestCase {
    use Tar_Entry_Fixture;
    private $dir;

    public function set_up() {
        parent::set_up();
        $this->dir = sys_get_temp_dir() . '/mwp-codec-resume-' . uniqid();
        mkdir( $this->dir );
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
        } elseif ( file_exists( $path ) || is_link( $path ) ) {
            unlink( $path );
        }
    }

    private function descriptor() {
        return MainWP_Child_Backup_Layout::descriptor( array( array( 'role' => 'plugins', 'coverage' => 'restore' ) ), str_repeat( 'a', 64 ) );
    }

    private function read( $bytes, $codec = null ) {
        file_put_contents( $this->dir . '/backup.mwp2', $bytes );
        mkdir( $this->dir . '/scratch' );
        return ( $codec ?: new MainWP_Child_Backup_Layout_Codec() )->read_slice( $this->dir . '/backup.mwp2', $this->dir . '/scratch', MainWP_Child_Backup_Layout::digest( $this->descriptor() ), array(), microtime( true ) + 30 );
    }

    public function test_completed_validation_is_reused_on_a_resumed_slice() {
        $bytes = "MWPBACKUP2\n" . $this->tar_entry_bytes( 'mainwp-layout.json', MainWP_Child_Backup_Layout::serialize( $this->descriptor() ) ) . $this->tar_entry_bytes( 'roots/plugins/file', 'bytes' );
        $codec = new class extends MainWP_Child_Backup_Layout_Codec {
            public $walks = 0;
            public $hashes = 0;
            protected function tar_header_members( $plain ) {
                ++$this->walks;
                return parent::tar_header_members( $plain );
            }
            protected function payload_digest( $plain ) {
                ++$this->hashes;
                return parent::payload_digest( $plain );
            }
            public function restore_slice( $cursor, $config, $deadline ) {
                return array( 'done' => ! empty( $cursor ), 'cursor' => array( 'part_index' => 0, 'entry_index' => 1 ), 'error' => null, 'error_code' => null );
            }
        };
        $first = $this->read( $bytes, $codec );
        $this->assertFalse( $first['done'] );
        $second = $codec->read_slice( $this->dir . '/backup.mwp2', $this->dir . '/scratch', MainWP_Child_Backup_Layout::digest( $this->descriptor() ), $first['cursor'], microtime( true ) + 30 );
        $this->assertTrue( $second['done'] );
        $this->assertSame( 1, $codec->walks );
        $this->assertSame( 1, $codec->hashes );
        $this->assertSame( $first['descriptor'], $second['descriptor'] );
        $this->assertSame( hash( 'sha256', substr( $bytes, 11 ) ), $second['cursor']['payload_sha256'] );
    }

    public function test_empty_payload_reports_the_corrupt_registry_error() {
        try {
            $this->read( "MWPBACKUP2\n" );
            $this->fail( 'Empty tar was accepted.' );
        } catch ( MainWP_Exception $error ) {
            $this->assertSame( 'mainwp_backup_file_corrupt', $error->get_message_error_code() );
        }
    }

    public static function invalid_directories() {
        return array( array( 'evil/' ), array( 'roots/alien/' ), array( 'roots/alien/dir/' ) );
    }

    /** @dataProvider invalid_directories */
    public function test_every_explicit_directory_uses_the_qualified_namespace( $directory ) {
        $bytes = "MWPBACKUP2\n" . $this->tar_entry_bytes( 'mainwp-layout.json', MainWP_Child_Backup_Layout::serialize( $this->descriptor() ) ) . $this->tar_entry_bytes( $directory, '', '5' );
        try {
            $this->read( $bytes );
            $this->fail( 'Unqualified directory was accepted.' );
        } catch ( MainWP_Exception $error ) {
            $this->assertSame( 'mainwp_backup_file_corrupt', $error->get_message_error_code() );
        }
        $this->assertDirectoryDoesNotExist( $this->dir . '/scratch/extracted' );
    }

    public function test_structural_directories_and_exact_member_names_round_trip() {
        $tar = $this->tar_entry_bytes( 'mainwp-layout.json', MainWP_Child_Backup_Layout::serialize( $this->descriptor() ) );
        foreach ( array( 'roots/', 'roots/plugins/', 'roots/plugins/mainwp-child/' ) as $directory ) {
            $tar .= $this->tar_entry_bytes( $directory, '', '5' );
        }
        $tar .= $this->tar_entry_bytes( 'roots/plugins/mainwp-child/mainwp-htaccess', 'literal member' );
        $result = $this->read( "MWPBACKUP2\n" . $tar );
        $this->assertTrue( $result['done'] );
        $this->assertSame( 'literal member', file_get_contents( $this->dir . '/scratch/extracted/roots/plugins/mainwp-child/mainwp-htaccess' ) );
        $this->assertFileDoesNotExist( $this->dir . '/scratch/extracted/roots/plugins/mainwp-child/.htaccess' );
    }

    public function test_writer_plain_scratch_is_private_even_with_a_permissive_umask() {
        file_put_contents( $this->dir . '/source', 'private fixture bytes' );
        $mask = umask( 0000 );
        try {
            ( new MainWP_Child_Backup_Layout_Codec() )->write_slice( $this->dir . '/backup.mwp2', $this->descriptor(), array( 'roots/plugins/file' => $this->dir . '/source' ), array(), microtime( true ) + 30, 1, 1 );
        } finally {
            umask( $mask );
        }
        clearstatcache();
        $this->assertSame( 0, fileperms( $this->dir . '/backup.mwp2.tar' ) & 0077 );
    }
}
