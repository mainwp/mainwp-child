<?php
/**
 * Exact external config files are captured only for manual recovery.
 *
 * @package Mainwp_Child
 */

use MainWP\Child\MainWP_Child_Backup_Config_Includes;
use MainWP\Child\MainWP_Child_Backup_Layout;
use MainWP\Child\MainWP_Child_Backup_Layout_Codec;
use MainWP\Child\MainWP_Backup_Error;
use MainWP\Child\MainWP_Exception;

class BackupV2ConfigRecoveryTest extends WP_UnitTestCase {
    private $dir;

    public function set_up() {
        parent::set_up();
        $this->dir = sys_get_temp_dir() . '/mwp-config-recovery-' . uniqid();
        mkdir( $this->dir );
        mkdir( $this->dir . '/web' );
        mkdir( $this->dir . '/web/wp' );
        mkdir( $this->dir . '/config' );
        file_put_contents( $this->dir . '/web/wp-config.php', 'fake bootstrap recovery marker' );
        file_put_contents( $this->dir . '/.env', 'FAKE_RECOVERY_VALUE=fixture' );
        file_put_contents( $this->dir . '/config/application.php', 'fake application recovery marker' );
        file_put_contents( $this->dir . '/config/private.php', 'must remain outside the archive' );
        file_put_contents( $this->dir . '/web/other.php', 'must remain outside the archive' );
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

    private function recovery_files( $root ) {
        $method = new ReflectionMethod( MainWP_Child_Backup_Config_Includes::class, 'recovery_files' );
        $this->assertTrue( $method->isPrivate() );
        $method->setAccessible( true );
        return $method->invoke( new MainWP_Child_Backup_Config_Includes(), $root );
    }

    public function test_bedrock_exact_allowlist_round_trips_as_recovery_only() {
        $files = $this->recovery_files( $this->dir . '/web/wp' );
        $this->assertSame( array( 'wp-config.php', '.env', 'config/application.php' ), array_keys( $files ) );
        $layout = new MainWP_Child_Backup_Layout( array( 'site' => $this->dir . '/web/wp' ), $files );
        $descriptor = MainWP_Child_Backup_Layout::descriptor( array( array( 'role' => 'site', 'coverage' => 'preserved' ), array( 'role' => 'config', 'coverage' => 'recovery_only' ) ), str_repeat( 'a', 64 ) );
        $this->assertSame( array( 'wp-config.php', '.env', 'config/application.php' ), array_keys( $layout->capture_bindings( $descriptor )['config'] ) );
        $entries = array();
        foreach ( $files as $name => $path ) {
            $entries[ $layout->identity( $path ) ] = $layout->resolve( 'roots/config/' . $name );
        }
        $codec = new MainWP_Child_Backup_Layout_Codec();
        $codec->write( $this->dir . '/recovery.mwp2', $descriptor, $entries );
        mkdir( $this->dir . '/scratch' );
        $cursor = array();
        do {
            $result = $codec->read_slice( $this->dir . '/recovery.mwp2', $this->dir . '/scratch', MainWP_Child_Backup_Layout::digest( $descriptor ), $cursor, microtime( true ) + 10 );
            $cursor = $result['cursor'];
        } while ( ! $result['done'] );
        $this->assertSame( $descriptor, $result['descriptor'] );
        $this->assertSame( 'recovery_only', array_column( $result['descriptor']['roots'], 'coverage', 'role' )['config'] );
        foreach ( $files as $name => $source ) {
            $this->assertSame( file_get_contents( $source ), file_get_contents( $this->dir . '/scratch/extracted/roots/config/' . $name ) );
        }
        $this->assertFileDoesNotExist( $this->dir . '/scratch/extracted/roots/config/config/private.php' );
        $this->assertFileDoesNotExist( $this->dir . '/scratch/extracted/roots/config/other.php' );
        $this->assertSame( 'must remain outside the archive', file_get_contents( $this->dir . '/config/private.php' ) );
        $this->assertSame( 'fake bootstrap recovery marker', file_get_contents( $this->dir . '/web/wp-config.php' ) );
    }

    public function test_external_wordpress_config_does_not_promote_adjacent_project_files() {
        mkdir( $this->dir . '/web/ordinary' );
        $files = $this->recovery_files( $this->dir . '/web/ordinary' );
        $this->assertSame( array( 'wp-config.php' => $this->dir . '/web/wp-config.php' ), $files );
    }

    public function test_in_place_config_keeps_ordinary_site_on_legacy_path() {
        file_put_contents( $this->dir . '/web/wp/wp-config.php', 'ordinary in-place config' );
        $this->assertSame( array(), $this->recovery_files( $this->dir . '/web/wp' ) );
        $this->assertSame( $this->dir . '/web/wp/wp-config.php', MainWP_Child_Backup_Config_Includes::locate( $this->dir . '/web/wp' ) );
        $this->assertSame( array(), glob( $this->dir . '/*.mwp2' ) );
        $this->assertSame( 'ordinary in-place config', file_get_contents( $this->dir . '/web/wp/wp-config.php' ) );
    }

    public function test_allowlisted_file_cannot_redirect_to_an_arbitrary_source() {
        unlink( $this->dir . '/.env' );
        symlink( $this->dir . '/config/private.php', $this->dir . '/.env' );
        try {
            $this->recovery_files( $this->dir . '/web/wp' );
            $this->fail( 'A config symlink promoted an arbitrary file.' );
        } catch ( MainWP_Exception $error ) {
            $this->assertSame( MainWP_Backup_Error::ERR_UNSUPPORTED_LAYOUT, $error->get_message_error_code() );
        }
        $this->assertSame( array(), glob( $this->dir . '/*.mwp2' ) );
    }

    public function test_config_directory_cannot_redirect_outside_the_project() {
        rename( $this->dir . '/config', $this->dir . '/other-config' );
        symlink( $this->dir . '/other-config', $this->dir . '/config' );
        $this->expectException( MainWP_Exception::class );
        $this->recovery_files( $this->dir . '/web/wp' );
    }

    public function test_registry_refuses_parent_traversal_as_a_config_name() {
        $this->expectException( MainWP_Exception::class );
        new MainWP_Child_Backup_Layout( array( 'site' => $this->dir . '/web/wp' ), array( '../private.php' => $this->dir . '/config/private.php' ) );
    }
}
