<?php
/**
 * Wrapped incremental parts remain private until v2 activation.
 *
 * @package Mainwp_Child
 */

use MainWP\Child\MainWP_Child_Backup_Incremental_Zip_Writer;
use MainWP\Child\MainWP_Child_Backup_Layout;
use MainWP\Child\MainWP_Child_Backup_Layout_Codec;
use MainWP\Child\MainWP_Child_Backup_File_Restorer;
use MainWP\Child\MainWP_Backup_Error;
use MainWP\Child\MainWP_Exception;

class BackupV2WrappedPartsTest extends WP_UnitTestCase {
    private $dir;

    public function set_up() {
        parent::set_up();
        $this->dir = sys_get_temp_dir() . '/mwp-wrapped-parts-' . uniqid();
        mkdir( $this->dir );
        mkdir( $this->dir . '/out' );
        foreach ( array( 'a', 'b', 'c' ) as $name ) {
            file_put_contents( $this->dir . '/' . $name, str_repeat( $name, 100 ) );
        }
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

    private function descriptor() {
        return MainWP_Child_Backup_Layout::descriptor( array( array( 'role' => 'uploads', 'coverage' => 'restore' ) ), str_repeat( 'a', 64 ) );
    }

    private function entries() {
        return array( 'roots/uploads/a' => $this->dir . '/a', 'roots/uploads/b' => $this->dir . '/b', 'roots/uploads/c' => $this->dir . '/c' );
    }

    private function slice( $cursor = array(), $descriptor = null, $max_entries = 2, $max_bytes = 1000 ) {
        $writer = new MainWP_Child_Backup_Incremental_Zip_Writer( $this->dir . '/out', 'uploads', $max_entries, $max_bytes, 'fixture', array( $this->dir ) );
        $method = new ReflectionMethod( $writer, 'write_v2_parts_slice' );
        $this->assertTrue( $method->isPrivate() );
        $method->setAccessible( true );
        return $method->invoke( $writer, $descriptor ?? $this->descriptor(), $this->entries(), $cursor, microtime( true ) + 10, 1, 127 );
    }

    private function complete( $max_entries = 2, $max_bytes = 1000 ) {
        $cursor = array();
        $calls = 0;
        do {
            $out = $this->slice( $cursor, null, $max_entries, $max_bytes );
            $cursor = json_decode( wp_json_encode( $out['cursor'] ), true );
            $this->assertLessThan( 200, ++$calls, 'Part writer did not finish.' );
        } while ( ! $out['done'] );
        $this->assertGreaterThan( 3, $calls );
        return $out['parts'];
    }

    public function test_rotating_parts_resume_and_round_trip_with_one_descriptor() {
        $parts = $this->complete();
        $this->assertSame( array( 2, 1 ), array_column( $parts, 'entries' ) );
        $digest = MainWP_Child_Backup_Layout::digest( $this->descriptor() );
        $found = array();
        foreach ( $parts as $part ) {
            $this->assertSame( $digest, $part['layout_descriptor_sha256'] );
            $this->assertSame( 2, $part['backup_format'] );
            $this->assertStringEndsWith( '.mwp2', $part['file'] );
            $path = $this->dir . '/out/' . $part['file'];
            $this->assertSame( MainWP_Child_Backup_Layout_Codec::MAGIC, file_get_contents( $path, false, null, 0, 11 ) );
            $this->assertFileDoesNotExist( $path . '.tar' );
            $scratch = $this->dir . '/read-' . $part['index'];
            mkdir( $scratch );
            $cursor = array();
            $calls = 0;
            do {
                $result = ( new MainWP_Child_Backup_Layout_Codec() )->read_slice( $path, $scratch, $digest, $cursor, microtime( true ) + 10, 127 );
                $cursor = $result['cursor'];
                $this->assertLessThan( 200, ++$calls );
            } while ( ! $result['done'] );
            $this->assertSame( $this->descriptor(), $result['descriptor'] );
            foreach ( glob( $scratch . '/extracted/roots/uploads/*' ) as $file ) {
                $found[ basename( $file ) ] = file_get_contents( $file );
            }
        }
        $this->assertSame( array( 'a' => str_repeat( 'a', 100 ), 'b' => str_repeat( 'b', 100 ), 'c' => str_repeat( 'c', 100 ) ), $found );
    }

    public function test_source_byte_threshold_rotates_between_complete_entries() {
        $this->assertSame( array( 1, 1, 1 ), array_column( $this->complete( 20, 150 ), 'entries' ) );
    }

    public function test_each_real_part_and_renamed_part_is_refused_before_old_reader_writes() {
        mkdir( $this->dir . '/target' );
        foreach ( $this->complete() as $part ) {
            foreach ( array( 'mwp2', 'zip', 'tar' ) as $extension ) {
                $path = $this->dir . '/renamed.' . $extension;
                copy( $this->dir . '/out/' . $part['file'], $path );
                $writes = 0;
                $result = ( new MainWP_Child_Backup_File_Restorer() )->restore_slice(
                    array(),
                    array( 'parts' => array( $path ), 'hashes' => array( basename( $path ) => hash_file( 'sha256', $path ) ), 'extract_to' => $this->dir . '/target', 'after_file_change' => function () use ( &$writes ) { ++$writes; } ),
                    microtime( true ) + 10
                );
                $this->assertSame( 0, $writes, $extension );
                $this->assertNotNull( $result['error'], $extension );
                $this->assertFalse( $result['done'], $extension );
                $this->assertSame( array( '.', '..' ), scandir( $this->dir . '/target' ), $extension );
                unlink( $path );
            }
        }
    }

    public function test_resume_refuses_descriptor_change_before_more_bytes() {
        $out = $this->slice();
        $before = file_get_contents( $this->dir . '/out/uploads.fixture.part001.mwp2.tar' );
        $descriptor = $this->descriptor();
        $descriptor['layout_id'] = str_repeat( 'b', 64 );
        try {
            $this->slice( $out['cursor'], $descriptor );
            $this->fail( 'A part resumed with a different descriptor.' );
        } catch ( MainWP_Exception $error ) {
            $this->assertSame( MainWP_Backup_Error::ERR_LAYOUT_CHANGED, $error->get_message_error_code() );
        }
        $this->assertSame( $before, file_get_contents( $this->dir . '/out/uploads.fixture.part001.mwp2.tar' ) );
    }

    public function test_parts_refuse_undeclared_and_preserved_roles_before_writes() {
        $descriptor = MainWP_Child_Backup_Layout::descriptor( array( array( 'role' => 'site', 'coverage' => 'preserved' ), array( 'role' => 'uploads', 'coverage' => 'restore' ) ), str_repeat( 'a', 64 ) );
        foreach ( array( 'plugins', 'site' ) as $role ) {
            $writer = new MainWP_Child_Backup_Incremental_Zip_Writer( $this->dir . '/out', 'uploads', null, null, 'roles', array( $this->dir ) );
            $method = new ReflectionMethod( $writer, 'write_v2_parts_slice' );
            $method->setAccessible( true );
            try {
                $method->invoke( $writer, $descriptor, array( 'roots/' . $role . '/a' => $this->dir . '/a' ), array(), microtime( true ) + 10 );
                $this->fail( 'A part accepted an undeclared or preserved role.' );
            } catch ( MainWP_Exception $error ) {
                $this->assertSame( MainWP_Backup_Error::ERR_FILE_CORRUPT, $error->get_message_error_code() );
            }
            $this->assertSame( array(), glob( $this->dir . '/out/*.mwp2*' ) );
        }
    }

    private function finish_omission_cursor( $cursor = array() ) {
        $calls = 0;
        do {
            $result = $this->slice( $cursor );
            $cursor = $result['cursor'];
            $this->assertLessThan( 200, ++$calls );
        } while ( ! $result['done'] );
        $found = array();
        foreach ( $result['parts'] as $part ) {
            $scratch = $this->dir . '/omission-' . $part['index'];
            mkdir( $scratch );
            $read_cursor = array();
            do {
                $read = ( new MainWP_Child_Backup_Layout_Codec() )->read_slice( $this->dir . '/out/' . $part['file'], $scratch, MainWP_Child_Backup_Layout::digest( $this->descriptor() ), $read_cursor, microtime( true ) + 10 );
                $read_cursor = $read['cursor'];
            } while ( ! $read['done'] );
            foreach ( glob( $scratch . '/extracted/roots/uploads/*' ) as $file ) {
                $found[] = basename( $file );
            }
        }
        return array( $result, $found );
    }

    public function test_vanished_and_link_sources_before_selection_are_reported_and_omitted() {
        unlink( $this->dir . '/b' );
        symlink( $this->dir . '/a', $this->dir . '/b' );
        unlink( $this->dir . '/c' );
        list( $result, $found ) = $this->finish_omission_cursor();
        $this->assertSame( array( 'roots/uploads/b' => 'link', 'roots/uploads/c' => 'unstatable' ), $result['omitted'] );
        $this->assertSame( array( 'a' ), $found );
        $this->assertSame( 1, array_sum( array_column( $result['parts'], 'entries' ) ) );
    }

    public function test_resumed_part_omits_an_unsent_vanished_source_without_rebinding_entries() {
        $initial = $this->slice();
        $this->assertSame( 1, $initial['cursor']['codec']['entry_index'] );
        $binding = $initial['cursor']['entries_sha256'];
        unlink( $this->dir . '/b' );
        list( $result, $found ) = $this->finish_omission_cursor( $initial['cursor'] );
        $this->assertSame( $binding, $result['cursor']['entries_sha256'] );
        $this->assertSame( array( 'roots/uploads/b' => 'unstatable' ), $result['omitted'] );
        $this->assertSame( array( 'a', 'c' ), $found );
        $this->assertSame( 2, array_sum( array_column( $result['parts'], 'entries' ) ) );
    }

    public function test_v1_zip_bytes_match_direct_legacy_writer() {
        $writer = new MainWP_Child_Backup_Incremental_Zip_Writer( $this->dir . '/out', 'uploads', null, null, 'v1', array( $this->dir ) );
        $writer->open_new_part( 1 );
        $writer->add_file( $this->dir . '/a', 'wp-content/uploads/a' );
        $part = $writer->close_part();
        $expected = $this->dir . '/expected.zip';
        $zip = new ZipArchive();
        $this->assertTrue( $zip->open( $expected, ZipArchive::CREATE ) );
        $this->assertTrue( $zip->addFile( $this->dir . '/a', 'wp-content/uploads/a' ) );
        $this->assertTrue( $zip->close() );
        $this->assertSame( file_get_contents( $expected ), file_get_contents( $this->dir . '/out/' . $part['file'] ) );
        $this->assertSame( 'uploads.v1.part001.zip', $part['file'] );
        $this->assertSame( array(), glob( $this->dir . '/out/*.mwp2' ) );
    }
}
