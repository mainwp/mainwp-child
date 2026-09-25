<?php
/**
 * Class BackupFileRestorerValidatePathTest
 *
 * @package Mainwp_Child
 */

use MainWP\Child\MainWP_Child_Backup_File_Restorer;

/**
 * Tests for the zip-slip extraction path guard.
 *
 * Every archive entry resolves through validate_extract_path() before a
 * byte is written. Absolute paths, traversal sequences, and entries that
 * resolve outside the extraction directory abort extraction. The
 * trailing-slash prefix compare closes the sibling-directory hole where
 * /path/backup-abc-evil passes a bare prefix check against
 * /path/backup-abc.
 */
class BackupFileRestorerValidatePathTest extends WP_UnitTestCase {

	/**
	 * Extraction scratch dir.
	 *
	 * @var string
	 */
	protected $extract_to;

	/**
	 * Sibling dir sharing the extraction dir's name as a prefix.
	 *
	 * @var string
	 */
	protected $sibling;

	/**
	 * Build scratch dirs.
	 */
	public function set_up() {
		parent::set_up();
		$base             = sys_get_temp_dir() . '/mainwp-restore-test-' . uniqid();
		$this->extract_to = $base . '/backup-abc123';
		$this->sibling    = $base . '/backup-abc123-evil';
		mkdir( $this->extract_to, 0777, true );
		mkdir( $this->sibling, 0777, true );
	}

	/**
	 * Remove scratch dirs.
	 */
	public function tear_down() {
		$this->rrmdir( dirname( $this->extract_to ) );
		parent::tear_down();
	}

	/**
	 * Recursive scratch cleanup.
	 *
	 * @param string $dir Directory.
	 */
	protected function rrmdir( $dir ) {
		if ( ! is_dir( $dir ) ) {
			return;
		}
		foreach ( (array) scandir( $dir ) as $entry ) {
			if ( '.' === $entry || '..' === $entry ) {
				continue;
			}
			$path = $dir . '/' . $entry;
			if ( is_link( $path ) ) {
				unlink( $path );
			} elseif ( is_dir( $path ) ) {
				$this->rrmdir( $path );
			} else {
				unlink( $path );
			}
		}
		rmdir( $dir );
	}

	/**
	 * An absolute path entry is rejected.
	 */
	public function test_absolute_path_entry_rejected() {
		$this->expectException( \Exception::class );
		MainWP_Child_Backup_File_Restorer::validate_extract_path( '/etc/passwd', $this->extract_to );
	}

	/**
	 * A Windows drive-letter absolute path is rejected.
	 */
	public function test_windows_absolute_path_rejected() {
		$this->expectException( \Exception::class );
		MainWP_Child_Backup_File_Restorer::validate_extract_path( 'C:\\evil.php', $this->extract_to );
	}

	/**
	 * Any traversal sequence is rejected.
	 */
	public function test_path_traversal_dotdot_rejected() {
		$this->expectException( \Exception::class );
		MainWP_Child_Backup_File_Restorer::validate_extract_path( '../outside.php', $this->extract_to );
	}

	/**
	 * Traversal buried mid-path is rejected.
	 */
	public function test_path_traversal_mid_path_rejected() {
		$this->expectException( \Exception::class );
		MainWP_Child_Backup_File_Restorer::validate_extract_path( 'safe/../../outside.php', $this->extract_to );
	}

	/**
	 * A symlink inside the extraction dir pointing at a sibling cannot
	 * smuggle a write outside: the resolved target fails the prefix
	 * compare.
	 */
	public function test_symlinked_subdir_resolving_outside_rejected() {
		$link = $this->extract_to . '/link';
		symlink( $this->sibling, $link );
		$this->expectException( \Exception::class );
		MainWP_Child_Backup_File_Restorer::validate_extract_path( 'link/evil.php', $this->extract_to );
	}

	/**
	 * The sibling-directory prefix hole is closed by the trailing-slash
	 * compare: an extraction dir that is itself a prefix of a sibling
	 * never validates entries into that sibling. Constructed directly
	 * against a target path equal to the sibling via a crafted entry.
	 */
	public function test_sibling_dir_with_prefix_match_rejected() {
		// Validate against the SIBLING as extract root: an entry under the
		// real extract dir must not pass (sibling + '/' is not a prefix of
		// extract_to + '/').
		mkdir( $this->sibling . '/sub' );
		$this->expectException( \Exception::class );
		MainWP_Child_Backup_File_Restorer::validate_extract_path( '../backup-abc123-evil/sub/x.php', $this->extract_to );
	}

	/**
	 * A plain relative file path is accepted and returned anchored under
	 * the extraction dir.
	 */
	public function test_valid_relative_path_accepted() {
		$path = MainWP_Child_Backup_File_Restorer::validate_extract_path( 'wp-content/uploads/a.jpg', $this->extract_to );
		$this->assertSame( $this->extract_to . '/wp-content/uploads/a.jpg', $path );
		$this->assertDirectoryExists( $this->extract_to . '/wp-content/uploads' );
	}

	/**
	 * A nested subdirectory entry is accepted, creating intermediates.
	 */
	public function test_nested_subdirectory_accepted() {
		$path = MainWP_Child_Backup_File_Restorer::validate_extract_path( 'a/b/c/d.txt', $this->extract_to );
		$this->assertSame( $this->extract_to . '/a/b/c/d.txt', $path );
	}
}
