<?php
/**
 * Legacy age cleanup must preserve the pending intent and its lock inode.
 *
 * @package MainWP_Child
 */

namespace MainWP\Child;

use WP_UnitTestCase;

class Test_Backup_Legacy_Age_Sweep extends WP_UnitTestCase {

    private $dir;
    private $files = array();

    public function set_up() {
        parent::set_up();
        $this->dir = sys_get_temp_dir() . '/mainwp-legacy-age-' . wp_generate_uuid4();
        $this->files = array();
        $this->assertTrue( wp_mkdir_p( $this->dir ) );
    }

    public function tear_down() {
        foreach ( $this->files as $file ) {
            if ( file_exists( $file ) ) {
                unlink( $file );
            }
        }
        rmdir( $this->dir );
        parent::tear_down();
    }

    /** @dataProvider legacy_age_sweep_provider */
    public function test_legacy_age_sweep_preserves_pending_files_and_existing_age_rules( $name, $age, $deleted ) {
        $file = $this->dir . '/' . $name;
        file_put_contents( $file, 'fixture' );
        touch( $file, time() - $age );
        clearstatcache( true, $file );
        $this->files[] = $file;
        $inode = fileinode( $file );
        $backup = new class() extends MainWP_Backup {
            public function sweep_fixture( $path ) {
                $this->delete_aged_backup_file( $path );
            }
        };
        $backup->sweep_fixture( $file );
        clearstatcache( true, $file );
        if ( $deleted ) {
            $this->assertFileDoesNotExist( $file );
        } else {
            $this->assertFileExists( $file );
            $this->assertSame( 'fixture', file_get_contents( $file ) );
            $this->assertSame( $inode, fileinode( $file ), 'A retained lock must keep its inode.' );
        }
    }

    public static function legacy_age_sweep_provider() {
        return array(
            'pending intent' => array( 'pending-delete.json', 4 * HOUR_IN_SECONDS, false ),
            'pending lock' => array( 'pending-delete.lock', 4 * HOUR_IN_SECONDS, false ),
            'stray temporary file' => array( 'pending-delete.json.orphan.tmp', 4 * HOUR_IN_SECONDS, true ),
            'recent temporary file' => array( 'pending-delete.json.recent.tmp', 0, false ),
            'old archive' => array( 'backup-old.zip', 4 * HOUR_IN_SECONDS, true ),
            'recent archive' => array( 'backup-recent.zip', 0, false ),
            'index protection' => array( 'index.php', 4 * HOUR_IN_SECONDS, false ),
            'access protection' => array( '.htaccess', 4 * HOUR_IN_SECONDS, false ),
            'intent lookalike' => array( 'pending-delete.json.bak', 4 * HOUR_IN_SECONDS, true ),
            'lock lookalike' => array( 'pending-delete.lock.bak', 4 * HOUR_IN_SECONDS, true ),
        );
    }
}
