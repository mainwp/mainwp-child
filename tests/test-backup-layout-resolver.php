<?php
/**
 * Exact configuration ownership and side-effect-free resolution.
 *
 * @package Mainwp_Child
 */

use MainWP\Child\MainWP_Child_Backup_Layout;
use MainWP\Child\MainWP_Exception;

class BackupLayoutResolverTest extends WP_UnitTestCase {
	private $directory;

	public function set_up() {
		parent::set_up();
		$this->directory = sys_get_temp_dir() . '/mwp-resolver-' . uniqid();
		mkdir( $this->directory );
		mkdir( $this->directory . '/site' );
		mkdir( $this->directory . '/site/content' );
		file_put_contents( $this->directory . '/wp-config.php', 'fake config marker' );
	}

	public function tear_down() {
		$this->remove_tree( $this->directory );
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

	public function test_config_above_site_claims_only_the_allowlisted_file() {
		$layout = new MainWP_Child_Backup_Layout(
			array( 'site' => $this->directory . '/site', 'content' => $this->directory . '/site/content' ),
			array( 'wp-config.php' => $this->directory . '/wp-config.php' )
		);
		$this->assertSame( 'roots/config/wp-config.php', $layout->identity( $this->directory . '/wp-config.php' ) );
		$this->assertSame( 'roots/content/uploads/file.jpg', $layout->identity( $this->directory . '/site/content/uploads/file.jpg' ) );
		$this->assertSame( 'roots/site/index.php', $layout->identity( $this->directory . '/site/index.php' ) );
		$this->assertSame( $this->directory . '/wp-config.php', $layout->resolve( 'roots/config/wp-config.php' ) );
	}

	public function test_config_directory_root_is_refused() {
		$this->expectException( MainWP_Exception::class );
		new MainWP_Child_Backup_Layout( array( 'site' => $this->directory . '/site', 'config' => $this->directory ) );
	}

	public function test_config_unknown_file_is_refused() {
		$layout = new MainWP_Child_Backup_Layout( array(), array( 'wp-config.php' => $this->directory . '/wp-config.php' ) );
		$this->expectException( MainWP_Exception::class );
		$layout->resolve( 'roots/config/not-allowed.php' );
	}

	public function test_resolve_missing_parent_is_a_read_only_check() {
		$layout = new MainWP_Child_Backup_Layout( array( 'uploads' => $this->directory . '/site/content' ) );
		$this->assertSame( $this->directory . '/site/content/2024/01/gone.jpg', $layout->resolve( 'roots/uploads/2024/01/gone.jpg' ) );
		$this->assertDirectoryDoesNotExist( $this->directory . '/site/content/2024' );
	}

	public function test_config_rejects_live_file_link_after_pinning() {
		$layout = new MainWP_Child_Backup_Layout( array(), array( 'wp-config.php' => $this->directory . '/wp-config.php' ) );
		unlink( $this->directory . '/wp-config.php' );
		symlink( $this->directory . '/site/index.php', $this->directory . '/wp-config.php' );
		$this->expectException( MainWP_Exception::class );
		$layout->resolve( 'roots/config/wp-config.php' );
	}

	public static function invalid_config_names() {
		return array( array( '../wp-config.php' ), array( '/wp-config.php' ), array( "config\0name" ) );
	}

	/** @dataProvider invalid_config_names */
	public function test_config_names_use_qualified_identity_rules( $name ) {
		$this->expectException( MainWP_Exception::class );
		new MainWP_Child_Backup_Layout( array(), array( $name => $this->directory . '/wp-config.php' ) );
	}
	public function test_config_does_not_accept_a_directory_as_an_exact_file() {
		$this->expectException( MainWP_Exception::class );
		new MainWP_Child_Backup_Layout( array(), array( 'wp-config.php' => $this->directory ) );
	}

	public function test_config_file_aliases_cannot_have_conflicting_identities() {
		$this->expectException( MainWP_Exception::class );
		new MainWP_Child_Backup_Layout( array(), array( 'wp-config.php' => $this->directory . '/wp-config.php', 'second.php' => $this->directory . '/wp-config.php' ) );
	}

	public function test_config_file_links_are_refused_when_pinning() {
		symlink( $this->directory . '/wp-config.php', $this->directory . '/linked.php' );
		$this->expectException( MainWP_Exception::class );
		new MainWP_Child_Backup_Layout( array(), array( 'wp-config.php' => $this->directory . '/linked.php' ) );
	}

}
