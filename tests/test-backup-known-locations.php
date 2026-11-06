<?php
/**
 * Optional backup directory exclusions.
 *
 * @package Mainwp_Child
 */

use MainWP\Child\MainWP_Backup;

class BackupKnownLocationsTest extends WP_UnitTestCase {

    public static function folders() {
        return array( array( 'ai1wm-backups' ), array( 'updraft' ), array( 'duplicator-backups' ) );
    }

    /**
     * @dataProvider folders
     */
    public function test_optional_locations_and_independent_cache_flag( $folder ) {
        $backup = MainWP_Backup::get();
        $content = str_replace( ABSPATH, '', WP_CONTENT_DIR );
        foreach ( array( false, true ) as $known ) {
            foreach ( array( false, true ) as $cache ) {
                $actual = $backup->assemble_exclude_list( array( 'custom/path/' ), $known, $cache );
                $this->assertContains( 'custom/path', $actual );
                $this->assertContains( $content . '/uploads/mainwp', $actual );
                $this->assertContains( $content . '/object-cache.php', $actual );
                foreach ( array( $folder, 'uploads/backupbuddy_backups', 'managewp' ) as $folder_to_check ) {
                    $this->assertSame( $known, in_array( $content . '/' . $folder_to_check, $actual, true ), $folder_to_check );
                }
                $this->assertSame( $cache, in_array( $content . '/cache/supercache', $actual, true ) );
            }
        }
    }

    public function test_archive_entries_follow_optional_selection() {
        $backup = new class() extends MainWP_Backup {
            public function extend_archive_mutex_lease() {}
            public function get_or_create_db_dump_files( $prefix, &$archiver = null ) {
                return array();
            }
        };
        $folders = array( 'ai1wm-backups', 'updraft', 'duplicator-backups', 'mwp2192-sibling', 'mwp2192-custom' );
        $created = array();
        $scratch = sys_get_temp_dir() . '/mwp2192-' . uniqid();
        mkdir( $scratch );
        try {
            foreach ( $folders as $folder ) {
                $this->assertDirectoryDoesNotExist( WP_CONTENT_DIR . '/' . $folder );
                $this->assertTrue( mkdir( WP_CONTENT_DIR . '/' . $folder ) );
                $created[] = $folder;
                file_put_contents( WP_CONTENT_DIR . '/' . $folder . '/marker.txt', 'MWP-2192' );
            }
            $content = str_replace( ABSPATH, '', WP_CONTENT_DIR );
            foreach ( array( false, true ) as $known ) {
                $excludes = $backup->assemble_exclude_list( array( $content . '/mwp2192-custom' ), $known, false );
                foreach ( glob( ABSPATH . '*' ) as $node ) {
                    if ( WP_CONTENT_DIR !== $node ) {
                        $excludes[] = str_replace( ABSPATH, '', $node );
                    }
                }
                foreach ( glob( WP_CONTENT_DIR . '/*' ) as $node ) {
                    if ( ! in_array( basename( $node ), $folders, true ) ) {
                        $excludes[] = str_replace( ABSPATH, '', $node );
                    }
                }
                $archive = $scratch . '/' . (int) $known . '.tar';
                $tar = new \MainWP\Child\Tar_Archiver( $backup, 'tar' );
                $this->assertTrue( $tar->create_full_backup( $archive, $excludes, false, true, false, false ) );
                $phar = new PharData( $archive );
                foreach ( array_slice( $folders, 0, 3 ) as $folder ) {
                    $this->assertSame( ! $known, isset( $phar[ $content . '/' . $folder . '/marker.txt' ] ), $folder );
                }
                $this->assertTrue( isset( $phar[ $content . '/mwp2192-sibling/marker.txt' ] ) );
                $this->assertFalse( isset( $phar[ $content . '/mwp2192-custom/marker.txt' ] ) );
                unset( $phar );
            }
        } finally {
            foreach ( $created as $folder ) {
                $marker = WP_CONTENT_DIR . '/' . $folder . '/marker.txt';
                if ( is_file( $marker ) ) {
                    unlink( $marker );
                }
                rmdir( WP_CONTENT_DIR . '/' . $folder );
            }
            foreach ( glob( $scratch . '/*.tar' ) as $archive ) {
                unlink( $archive );
            }
            rmdir( $scratch );
        }
    }
}
