<?php
/**
 * Class BackupFileWalkerTest
 *
 * @package Mainwp_Child
 */

use MainWP\Child\MainWP_Child_Backup_File_Walker;

/**
 * Tests for the manifest file walker.
 *
 * The walker streams two sidecar files describing the site tree: files.list
 * (relpath, size, mtime - sorted by relpath, the diff source for incremental
 * backups) and paths.cache (component, relpath - the pack-phase resume
 * order). Sidecars are written staged and only promoted to their final names
 * when a run completes, so the previous snapshot's sidecars survive a failed
 * run.
 */
class BackupFileWalkerTest extends WP_UnitTestCase {

	/**
	 * Site tree root fixture.
	 *
	 * @var string
	 */
	protected $root;

	/**
	 * Sidecar output dir fixture (stands in for the backup scratch dir).
	 *
	 * @var string
	 */
	protected $backup_dir;

	/**
	 * Build a small site-shaped tree.
	 */
	public function set_up() {
		parent::set_up();

		$this->root       = sys_get_temp_dir() . '/mainwp-walker-root-' . uniqid();
		$this->backup_dir = sys_get_temp_dir() . '/mainwp-walker-out-' . uniqid();

		mkdir( $this->backup_dir );
		mkdir( $this->root );
		mkdir( $this->root . '/wp-admin' );
		mkdir( $this->root . '/wp-content' );
		mkdir( $this->root . '/wp-content/plugins' );
		mkdir( $this->root . '/wp-content/plugins/hello' );
		mkdir( $this->root . '/wp-content/themes' );
		mkdir( $this->root . '/wp-content/themes/twenty' );
		mkdir( $this->root . '/wp-content/uploads' );
		mkdir( $this->root . '/wp-content/cache' );

		file_put_contents( $this->root . '/index.php', '<?php // root' ); // phpcs:ignore -- test fixture.
		file_put_contents( $this->root . '/wp-admin/admin.php', '<?php // admin' ); // phpcs:ignore -- test fixture.
		file_put_contents( $this->root . '/wp-content/plugins/hello/hello.php', '<?php // plugin' ); // phpcs:ignore -- test fixture.
		file_put_contents( $this->root . '/wp-content/themes/twenty/style.css', 'body{}' ); // phpcs:ignore -- test fixture.
		file_put_contents( $this->root . '/wp-content/uploads/photo.jpg', 'jpegdata' ); // phpcs:ignore -- test fixture.
		file_put_contents( $this->root . '/wp-content/uploads/archive.zip', 'zipdata' ); // phpcs:ignore -- test fixture.
		file_put_contents( $this->root . '/wp-content/cache/page.html', '<html>' ); // phpcs:ignore -- test fixture.
	}

	/**
	 * Remove the fixtures.
	 */
	public function tear_down() {
		$this->rrmdir( $this->root );
		$this->rrmdir( $this->backup_dir );
		parent::tear_down();
	}

	/**
	 * Recursive fixture cleanup.
	 *
	 * @param string $dir Directory.
	 */
	private function rrmdir( $dir ) {
		if ( ! is_dir( $dir ) ) {
			return;
		}
		foreach ( (array) scandir( $dir ) as $entry ) {
			if ( '.' === $entry || '..' === $entry ) {
				continue;
			}
			$path = $dir . '/' . $entry;
			if ( is_dir( $path ) && ! is_link( $path ) ) {
				$this->rrmdir( $path );
			} else {
				unlink( $path );
			}
		}
		rmdir( $dir );
	}

	/**
	 * Walker for the fixture tree.
	 *
	 * @param array $excludes     Exclude list (relpaths).
	 * @param bool  $excludezip   Skip .zip files.
	 * @param bool  $excludenonwp Only walk wp dirs.
	 *
	 * @return MainWP_Child_Backup_File_Walker
	 */
	private function walker( $excludes = array(), $excludezip = false, $excludenonwp = false ) {
		return new MainWP_Child_Backup_File_Walker( $this->root, $excludes, $excludezip, $excludenonwp, array( $this->backup_dir ) );
	}

	/**
	 * Parse a sidecar file into an array of line-field arrays.
	 *
	 * @param string $path Sidecar path.
	 *
	 * @return array[] Rows of tab-split fields.
	 */
	private function parse_sidecar( $path ) {
		$rows = array();
		foreach ( file( $path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES ) as $line ) {
			$rows[] = explode( "\t", $line );
		}
		return $rows;
	}

	/**
	 * Emitting sidecars produces staged files.list and paths.cache in the
	 * output dir, and reports the manifest size.
	 *
	 * @return void
	 */
	public function test_emit_sidecars_creates_staged_files() {
		$result = $this->walker()->emit_sidecars( $this->backup_dir );

		$this->assertFileExists( $this->backup_dir . '/files.list.staged' );
		$this->assertFileExists( $this->backup_dir . '/paths.cache.staged' );
		$this->assertSame( (int) filesize( $this->backup_dir . '/files.list.staged' ), (int) $result['size'] );
		$this->assertGreaterThan( 0, (int) $result['count'] );
	}

	/**
	 * files.list lines carry relpath, size, and mtime for every file, sorted
	 * by relpath so a sorted-merge diff stays possible.
	 *
	 * @return void
	 */
	public function test_files_list_format_and_sorted_order() {
		$this->walker()->emit_sidecars( $this->backup_dir );

		$rows     = $this->parse_sidecar( $this->backup_dir . '/files.list.staged' );
		$relpaths = array();

		foreach ( $rows as $row ) {
			$this->assertCount( 3, $row, 'Each files.list line is relpath<tab>size<tab>mtime.' );
			$this->assertMatchesRegularExpression( '/^\d+$/', $row[1], 'Size is an integer.' );
			$this->assertMatchesRegularExpression( '/^\d+$/', $row[2], 'Mtime is an integer.' );
			$relpaths[] = $row[0];
		}

		$this->assertContains( 'index.php', $relpaths );
		$this->assertContains( 'wp-content/plugins/hello/hello.php', $relpaths );

		$sorted = $relpaths;
		sort( $sorted, SORT_STRING );
		$this->assertSame( $sorted, $relpaths, 'files.list must be sorted by relpath.' );

		$sizes = array_column( $rows, 1, 0 );
		$this->assertSame( (string) filesize( $this->root . '/wp-content/uploads/photo.jpg' ), $sizes['wp-content/uploads/photo.jpg'] );
	}

	/**
	 * paths.cache classifies every file into its component.
	 *
	 * @return void
	 */
	public function test_paths_cache_component_assignment() {
		$this->walker()->emit_sidecars( $this->backup_dir );

		$rows       = $this->parse_sidecar( $this->backup_dir . '/paths.cache.staged' );
		$components = array();
		foreach ( $rows as $row ) {
			$this->assertCount( 2, $row, 'Each paths.cache line is component<tab>relpath.' );
			$components[ $row[1] ] = $row[0];
		}

		$this->assertSame( 'core', $components['index.php'] );
		$this->assertSame( 'core', $components['wp-admin/admin.php'] );
		$this->assertSame( 'plugins', $components['wp-content/plugins/hello/hello.php'] );
		$this->assertSame( 'themes', $components['wp-content/themes/twenty/style.css'] );
		$this->assertSame( 'uploads', $components['wp-content/uploads/photo.jpg'] );
		$this->assertSame( 'wp-content', $components['wp-content/cache/page.html'] );
	}

	/**
	 * Symlinks are never followed - same rule as the archive traversal.
	 *
	 * @return void
	 */
	public function test_symlinks_excluded() {
		symlink( $this->root . '/wp-content/uploads/photo.jpg', $this->root . '/wp-content/uploads/link.jpg' );

		$this->walker()->emit_sidecars( $this->backup_dir );

		$relpaths = array_column( $this->parse_sidecar( $this->backup_dir . '/files.list.staged' ), 0 );
		$this->assertNotContains( 'wp-content/uploads/link.jpg', $relpaths );
	}

	/**
	 * Excluded relpaths prune the whole subtree, matching the archive
	 * traversal's exclude semantics.
	 *
	 * @return void
	 */
	public function test_excludes_prune_subtrees() {
		$this->walker( array( 'wp-content/cache' ) )->emit_sidecars( $this->backup_dir );

		$relpaths = array_column( $this->parse_sidecar( $this->backup_dir . '/files.list.staged' ), 0 );
		$this->assertNotContains( 'wp-content/cache/page.html', $relpaths );
		$this->assertContains( 'wp-content/uploads/photo.jpg', $relpaths );
	}

	/**
	 * The excludezip option skips .zip files, matching the archive walk.
	 *
	 * @return void
	 */
	public function test_excludezip_skips_zip_files() {
		$this->walker( array(), true )->emit_sidecars( $this->backup_dir );

		$relpaths = array_column( $this->parse_sidecar( $this->backup_dir . '/files.list.staged' ), 0 );
		$this->assertNotContains( 'wp-content/uploads/archive.zip', $relpaths );
		$this->assertContains( 'wp-content/uploads/photo.jpg', $relpaths );
	}

	/**
	 * The excludenonwp option keeps only wp-admin, wp-includes, and
	 * wp-content trees, matching the archive walk's gate. Root-level loose
	 * files stay, mirroring the archive walk which only gates directories.
	 *
	 * @return void
	 */
	public function test_excludenonwp_keeps_only_wp_trees() {
		mkdir( $this->root . '/custom-app' );
		file_put_contents( $this->root . '/custom-app/app.php', '<?php // app' ); // phpcs:ignore -- test fixture.

		$this->walker( array(), false, true )->emit_sidecars( $this->backup_dir );

		$relpaths = array_column( $this->parse_sidecar( $this->backup_dir . '/files.list.staged' ), 0 );
		$this->assertNotContains( 'custom-app/app.php', $relpaths );
		$this->assertContains( 'wp-content/uploads/photo.jpg', $relpaths );
		$this->assertContains( 'wp-admin/admin.php', $relpaths );
	}

	/**
	 * The backup scratch dir is never walked, even when it lives inside the
	 * tree being walked - the manifest must not describe in-flight backup
	 * artifacts.
	 *
	 * @return void
	 */
	public function test_backup_scratch_dir_skipped() {
		mkdir( $this->root . '/wp-content/uploads/mainwp' );
		mkdir( $this->root . '/wp-content/uploads/mainwp/backup-abc123' );
		file_put_contents( $this->root . '/wp-content/uploads/mainwp/backup-abc123/old.tar', 'tar' ); // phpcs:ignore -- test fixture.

		$walker = new MainWP_Child_Backup_File_Walker(
			$this->root,
			array(),
			false,
			false,
			array( $this->root . '/wp-content/uploads/mainwp/backup-abc123' )
		);
		$walker->emit_sidecars( $this->root . '/wp-content/uploads/mainwp/backup-abc123' );

		$relpaths = array_column( $this->parse_sidecar( $this->root . '/wp-content/uploads/mainwp/backup-abc123/files.list.staged' ), 0 );
		$this->assertNotContains( 'wp-content/uploads/mainwp/backup-abc123/old.tar', $relpaths );
		$this->assertContains( 'wp-content/uploads/photo.jpg', $relpaths );
	}

	/**
	 * Promotion renames the staged sidecars over the final names; until then
	 * a previous run's promoted sidecars stay untouched.
	 *
	 * @return void
	 */
	public function test_promote_replaces_previous_sidecars_only_on_promotion() {
		file_put_contents( $this->backup_dir . '/files.list', "old.php\t1\t1\n" ); // phpcs:ignore -- test fixture: previous snapshot manifest.
		file_put_contents( $this->backup_dir . '/paths.cache', "core\told.php\n" ); // phpcs:ignore -- test fixture: previous snapshot manifest.

		$this->walker()->emit_sidecars( $this->backup_dir );

		$this->assertStringContainsString( 'old.php', file_get_contents( $this->backup_dir . '/files.list' ), 'Staged emission must not touch the promoted manifest.' );

		$this->assertTrue( MainWP_Child_Backup_File_Walker::promote_sidecars( $this->backup_dir ) );

		$promoted = file_get_contents( $this->backup_dir . '/files.list' );
		$this->assertStringNotContainsString( 'old.php', $promoted );
		$this->assertStringContainsString( 'index.php', $promoted );
		$this->assertFileDoesNotExist( $this->backup_dir . '/files.list.staged' );
		$this->assertFileExists( $this->backup_dir . '/paths.cache' );
	}

	/**
	 * Promotion without staged sidecars reports failure and leaves any
	 * promoted manifest alone.
	 *
	 * @return void
	 */
	public function test_promote_without_staged_files_fails() {
		file_put_contents( $this->backup_dir . '/files.list', "old.php\t1\t1\n" ); // phpcs:ignore -- test fixture.

		$this->assertFalse( MainWP_Child_Backup_File_Walker::promote_sidecars( $this->backup_dir ) );
		$this->assertStringContainsString( 'old.php', file_get_contents( $this->backup_dir . '/files.list' ) );
	}
}
