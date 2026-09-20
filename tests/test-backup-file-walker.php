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
/**
 * Walker with a tiny chunk size so the small fixture still crosses run
 * boundaries and exercises the chunked-spill + k-way-merge path.
 */
class ChunkedTestWalker extends MainWP_Child_Backup_File_Walker {

	/**
	 * Force a 2-entry chunk so a handful of files spills several runs.
	 *
	 * @return int Chunk size.
	 */
	protected function chunk_size() {
		return 2;
	}
}

/**
 * Chunked walker that appends a malformed line to every spilled run, so the
 * k-way merge meets a corrupt run and must fail cleanly instead of silently
 * misordering the manifest.
 */
class CorruptingTestWalker extends ChunkedTestWalker {

	/**
	 * Write the run as usual, then append a junk line with no tab fields.
	 *
	 * @param array $buffer Sorted chunk entries.
	 *
	 * @return string Run file path.
	 */
	protected function write_run( array $buffer ) {
		$path = parent::write_run( $buffer );
		file_put_contents( $path, "malformed-run-line-without-tab-fields\n", FILE_APPEND ); // phpcs:ignore -- deliberate corruption for the merge robustness test.
		return $path;
	}
}

/**
 * Chunked walker that yields after a fixed number of enumeration steps, so a
 * small fixture exercises the resume path across many slices. The wrapper path
 * (PHP_INT_MAX deadline) never yields, keeping emit_sidecars() one-shot.
 */
class SlicingTestWalker extends ChunkedTestWalker {

	/**
	 * Enumeration steps allowed before a slice yields.
	 *
	 * @var int
	 */
	public $steps_per_slice = 3;

	/**
	 * Steps taken in the current slice (resets per instance).
	 *
	 * @var int
	 */
	private $steps_taken = 0;

	/**
	 * Yield after steps_per_slice steps; never on the one-shot wrapper path.
	 *
	 * @param int $deadline Slice deadline.
	 *
	 * @return bool Whether to checkpoint and yield.
	 */
	protected function walk_budget_exhausted( $deadline ) {
		if ( PHP_INT_MAX === $deadline ) {
			return false;
		}
		if ( $this->steps_taken >= $this->steps_per_slice ) {
			return true;
		}
		++$this->steps_taken;
		return false;
	}
}

/**
 * Slicing walker that corrupts every run it writes on the first walk attempt
 * only; the recovery re-walk (recover_count > 0) writes clean runs, so the
 * merge meets a corrupt run once, recovers, and then succeeds.
 */
class CorruptOnceSlicingWalker extends SlicingTestWalker {

	/**
	 * Append a junk line on the first attempt so the merge fails once.
	 *
	 * @param array $buffer Sorted chunk entries.
	 *
	 * @return string Run file path.
	 */
	protected function write_run( array $buffer ) {
		$path = parent::write_run( $buffer );
		if ( 0 === $this->recover_count ) {
			file_put_contents( $path, "malformed-run-line-without-tab-fields\n", FILE_APPEND ); // phpcs:ignore -- deliberate one-time corruption for the recovery test.
		}
		return $path;
	}
}

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

		$this->root       = ABSPATH . 'mainwp-walker-root-' . uniqid();
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
	 * The reported manifest size matches the bytes on disk even when an
	 * earlier stat-family call primed PHP's stat cache for the staged
	 * path. The sidecar is streamed through fopen/fwrite/fclose, which on
	 * some runtimes leaves the primed entry in place.
	 *
	 * @return void
	 */
	public function test_emit_sidecars_size_fresh_after_stat_prime() {
		$staged = $this->backup_dir . '/files.list.staged';
		file_put_contents( $staged, 'leftover from an interrupted run' ); // phpcs:ignore -- test fixture.
		filesize( $staged ); // Prime the stat cache with the leftover size.

		$result = $this->walker()->emit_sidecars( $this->backup_dir );

		clearstatcache( true, $staged );
		$this->assertSame( (int) filesize( $staged ), (int) $result['size'] );
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
	 * Inside-resolving links must appear under their own relative paths.
	 *
	 * @return void
	 */
	public function test_inside_symlink_included() {
		symlink( $this->root . '/wp-content/uploads/photo.jpg', $this->root . '/wp-content/uploads/link.jpg' );

		$this->walker()->emit_sidecars( $this->backup_dir );

		$relpaths = array_column( $this->parse_sidecar( $this->backup_dir . '/files.list.staged' ), 0 );
		$this->assertContains( 'wp-content/uploads/link.jpg', $relpaths );
	}

	public function test_user_exclude_applies_to_link_target() {
		symlink( $this->root . '/wp-content/cache/page.html', $this->root . '/linked-page.html' );
		symlink( $this->root . '/wp-content/cache', $this->root . '/linked-cache' );
		$this->walker( array( 'wp-content/cache' ) )->emit_sidecars( $this->backup_dir );
		$relpaths = array_column( $this->parse_sidecar( $this->backup_dir . '/files.list.staged' ), 0 );
		$this->assertNotContains( 'linked-page.html', $relpaths );
		$this->assertNotContains( 'linked-cache/page.html', $relpaths );
		$this->assertContains( 'wp-content/uploads/photo.jpg', $relpaths );
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
	 * Dump litter at the site root never enters the manifest - a stale
	 * dbBackup dump described by the manifest would ride into the next
	 * incremental pack.
	 *
	 * @return void
	 */
	public function test_dump_litter_excluded_at_site_root() {
		file_put_contents( $this->root . '/dbBackup-wp_options.sql.php', 'stale dump' ); // phpcs:ignore -- test fixture.
		file_put_contents( $this->root . '/dbBackup.sql', '' ); // phpcs:ignore -- test fixture.

		$this->walker()->emit_sidecars( $this->backup_dir );

		$relpaths = array_column( $this->parse_sidecar( $this->backup_dir . '/files.list.staged' ), 0 );
		$this->assertNotContains( 'dbBackup-wp_options.sql.php', $relpaths );
		$this->assertNotContains( 'dbBackup.sql', $relpaths );
		$this->assertContains( 'index.php', $relpaths );
	}

	/**
	 * Dump litter directly under wp-content never enters the manifest.
	 *
	 * @return void
	 */
	public function test_dump_litter_excluded_in_wp_content() {
		file_put_contents( $this->root . '/wp-content/dbBackup-wp_posts.sql.php', 'stale dump' ); // phpcs:ignore -- test fixture.

		$this->walker()->emit_sidecars( $this->backup_dir );

		$relpaths = array_column( $this->parse_sidecar( $this->backup_dir . '/files.list.staged' ), 0 );
		$this->assertNotContains( 'wp-content/dbBackup-wp_posts.sql.php', $relpaths );
	}

	/**
	 * The litter exclusion matches the restore sweep exactly: lookalike
	 * names and dumps in deeper directories are site content and stay in
	 * the manifest.
	 *
	 * @return void
	 */
	public function test_dump_litter_lookalikes_and_deeper_dumps_stay_included() {
		file_put_contents( $this->root . '/dbBackupNotes.txt', 'user file' ); // phpcs:ignore -- test fixture.
		file_put_contents( $this->root . '/wp-content/uploads/dbBackup-deep.sql.php', 'user file' ); // phpcs:ignore -- test fixture.

		$this->walker()->emit_sidecars( $this->backup_dir );

		$relpaths = array_column( $this->parse_sidecar( $this->backup_dir . '/files.list.staged' ), 0 );
		$this->assertContains( 'dbBackupNotes.txt', $relpaths );
		$this->assertContains( 'wp-content/uploads/dbBackup-deep.sql.php', $relpaths );
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

	/**
	 * Walker forced to spill several runs over the fixture tree.
	 *
	 * @param array $excludes     Exclude list (relpaths).
	 * @param bool  $excludezip   Skip .zip files.
	 * @param bool  $excludenonwp Only walk wp dirs.
	 *
	 * @return ChunkedTestWalker
	 */
	private function chunked_walker( $excludes = array(), $excludezip = false, $excludenonwp = false ) {
		return new ChunkedTestWalker( $this->root, $excludes, $excludezip, $excludenonwp, array( $this->backup_dir ) );
	}

	/**
	 * Independent recursive scan of the fixture - the reference set the merged
	 * manifest must match, computed without the walker so the test is not
	 * circular.
	 *
	 * @param bool $excludezip Skip .zip files, matching the walk.
	 *
	 * @return string[] Site-relative paths of every regular (non-symlink) file.
	 */
	private function expected_files( $excludezip = false ) {
		$root = rtrim( $this->root, '/' ) . '/';
		$out  = array();
		$it   = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $this->root, FilesystemIterator::SKIP_DOTS ) );
		foreach ( $it as $file ) {
			if ( ! $file->isFile() || $file->isLink() ) {
				continue;
			}
			$abs = $file->getPathname();
			if ( $excludezip && '.zip' === substr( $abs, -4 ) ) {
				continue;
			}
			$out[] = substr( $abs, strlen( $root ) );
		}
		return $out;
	}

	/**
	 * Glob the spilled run files in the output dir.
	 *
	 * @return array Run file paths.
	 */
	private function run_files() {
		return (array) glob( $this->backup_dir . '/walk-run-*' );
	}

	/**
	 * The streamed manifest equals an independently computed reference:
	 * strictly ascending by strcmp, the same file set, correct size/mtime, a
	 * line-aligned paths.cache, and an accurate total_bytes - proving the
	 * chunked spill + k-way merge reproduces today's sorted output.
	 *
	 * @return void
	 */
	public function test_streamed_output_equals_independent_reference() {
		$result = $this->chunked_walker()->emit_sidecars( $this->backup_dir );

		$rows     = $this->parse_sidecar( $this->backup_dir . '/files.list.staged' );
		$relpaths = array_column( $rows, 0 );

		$count = count( $relpaths );
		for ( $i = 1; $i < $count; $i++ ) {
			$this->assertLessThan( 0, strcmp( $relpaths[ $i - 1 ], $relpaths[ $i ] ), 'files.list must be strictly ascending by strcmp.' );
		}

		$expected = $this->expected_files();
		sort( $expected, SORT_STRING );
		$got = $relpaths;
		sort( $got, SORT_STRING );
		$this->assertSame( $expected, $got, 'Streamed manifest must list exactly the walked files.' );

		foreach ( $rows as $row ) {
			$abs = rtrim( $this->root, '/' ) . '/' . $row[0];
			$this->assertSame( (string) filesize( $abs ), $row[1], 'Size column must match the file on disk.' );
			$this->assertSame( (string) filemtime( $abs ), $row[2], 'Mtime column must match the file on disk.' );
		}

		$pc = $this->parse_sidecar( $this->backup_dir . '/paths.cache.staged' );
		$this->assertCount( count( $rows ), $pc, 'paths.cache must carry one line per files.list line.' );
		foreach ( $pc as $i => $row ) {
			$this->assertSame( $relpaths[ $i ], $row[1], 'paths.cache line must align with the files.list line.' );
			$this->assertSame( MainWP_Child_Backup_File_Walker::classify_component( $row[1] ), $row[0], 'paths.cache component must match the relpath.' );
		}

		$sum = 0;
		foreach ( $relpaths as $rp ) {
			$sum += (int) filesize( rtrim( $this->root, '/' ) . '/' . $rp );
		}
		$this->assertSame( $sum, (int) $result['total_bytes'], 'total_bytes must be the raw sum of file sizes.' );
		$this->assertSame( $count, (int) $result['count'], 'count must equal the number of emitted lines.' );

		$this->assertSame( array(), $this->run_files(), 'walk-run-* files must be cleaned up after a successful emit.' );
	}

	/**
	 * The merge orders by raw bytes, not case-insensitive collation: uppercase
	 * Z (0x5A) sorts before lowercase a (0x61).
	 *
	 * @return void
	 */
	public function test_streamed_order_is_byte_order_not_collation() {
		file_put_contents( $this->root . '/wp-content/uploads/Zebra.txt', 'z' ); // phpcs:ignore -- test fixture.
		file_put_contents( $this->root . '/wp-content/uploads/apple.txt', 'a' ); // phpcs:ignore -- test fixture.

		$this->chunked_walker()->emit_sidecars( $this->backup_dir );

		$relpaths = array_column( $this->parse_sidecar( $this->backup_dir . '/files.list.staged' ), 0 );
		$zi       = array_search( 'wp-content/uploads/Zebra.txt', $relpaths, true );
		$ai       = array_search( 'wp-content/uploads/apple.txt', $relpaths, true );
		$this->assertNotFalse( $zi );
		$this->assertNotFalse( $ai );
		$this->assertLessThan( $ai, $zi, 'Uppercase Z must sort before lowercase a - byte order, not collation.' );
	}

	/**
	 * Files created across subtrees (so they land in different chunks) still
	 * come out in one globally sorted stream after the k-way merge.
	 *
	 * @return void
	 */
	public function test_multi_run_merge_is_globally_sorted() {
		file_put_contents( $this->root . '/wp-content/uploads/aaa.txt', '' ); // phpcs:ignore -- test fixture.
		file_put_contents( $this->root . '/wp-content/uploads/mmm.txt', '' ); // phpcs:ignore -- test fixture.
		file_put_contents( $this->root . '/wp-content/uploads/zzz.txt', '' ); // phpcs:ignore -- test fixture.
		file_put_contents( $this->root . '/wp-content/themes/twenty/bbb.txt', '' ); // phpcs:ignore -- test fixture.
		file_put_contents( $this->root . '/wp-content/themes/twenty/nnn.txt', '' ); // phpcs:ignore -- test fixture.

		$this->chunked_walker()->emit_sidecars( $this->backup_dir );

		$relpaths = array_column( $this->parse_sidecar( $this->backup_dir . '/files.list.staged' ), 0 );
		$sorted   = $relpaths;
		usort( $sorted, 'strcmp' );
		$this->assertSame( $sorted, $relpaths, 'The merged manifest must be globally sorted by strcmp.' );
	}

	/**
	 * An empty tree yields an empty manifest and leaves no run files.
	 *
	 * @return void
	 */
	public function test_empty_tree_yields_empty_manifest() {
		$root = sys_get_temp_dir() . '/mainwp-walker-empty-' . uniqid();
		$out  = sys_get_temp_dir() . '/mainwp-walker-empty-out-' . uniqid();
		mkdir( $root );
		mkdir( $out );

		$result = ( new ChunkedTestWalker( $root, array(), false, false, array( $out ) ) )->emit_sidecars( $out );

		$this->assertSame( 0, (int) $result['count'] );
		$this->assertSame( 0, (int) $result['total_bytes'] );
		$this->assertSame( '', trim( (string) file_get_contents( $out . '/files.list.staged' ) ) );
		$this->assertSame( array(), (array) glob( $out . '/walk-run-*' ) );

		$this->rrmdir( $root );
		$this->rrmdir( $out );
	}

	/**
	 * A single-file tree emits exactly one correctly formatted line.
	 *
	 * @return void
	 */
	public function test_single_file_tree() {
		$root = ABSPATH . 'mainwp-walker-one-' . uniqid();
		$out  = sys_get_temp_dir() . '/mainwp-walker-one-out-' . uniqid();
		mkdir( $root );
		mkdir( $out );
		file_put_contents( $root . '/only.php', 'x' ); // phpcs:ignore -- test fixture.

		$result = ( new ChunkedTestWalker( $root, array(), false, false, array( $out ) ) )->emit_sidecars( $out );

		$rows = $this->parse_sidecar( $out . '/files.list.staged' );
		$this->assertSame( 1, (int) $result['count'] );
		$this->assertCount( 1, $rows );
		$this->assertSame( 'only.php', $rows[0][0] );

		$this->rrmdir( $root );
		$this->rrmdir( $out );
	}

	/**
	 * A stale run file left by a killed slice is cleared before the walk and
	 * never bleeds into the fresh manifest.
	 *
	 * @return void
	 */
	public function test_stale_run_files_are_cleared_before_walk() {
		file_put_contents( $this->backup_dir . '/walk-run-0000', "ghost/file.php\t1\t1\tcore\n" ); // phpcs:ignore -- leftover from a prior killed slice.

		$this->chunked_walker()->emit_sidecars( $this->backup_dir );

		$relpaths = array_column( $this->parse_sidecar( $this->backup_dir . '/files.list.staged' ), 0 );
		$this->assertNotContains( 'ghost/file.php', $relpaths, 'A stale run must never enter the fresh manifest.' );
		$this->assertSame( array(), $this->run_files(), 'Stale runs must be cleared and new runs cleaned up.' );
	}

	/**
	 * A malformed run line aborts the merge cleanly - no silent misordering,
	 * no half-written staged manifest, no leftover runs.
	 *
	 * @return void
	 */
	public function test_corrupt_run_fails_cleanly() {
		$threw = false;
		try {
			( new CorruptingTestWalker( $this->root, array(), false, false, array( $this->backup_dir ) ) )->emit_sidecars( $this->backup_dir );
		} catch ( \Throwable $e ) {
			$threw = true;
		}

		$this->assertTrue( $threw, 'A malformed run must abort the merge, not silently misorder.' );
		$this->assertSame( array(), $this->run_files(), 'A failed merge must leave no run files.' );
		$this->assertFileDoesNotExist( $this->backup_dir . '/files.list.staged', 'A failed merge must leave no staged manifest.' );
	}

	/**
	 * The progress heartbeat fires once per spilled run (each chunk boundary),
	 * so a long walk keeps the task alive instead of looking stalled.
	 *
	 * @return void
	 */
	public function test_heartbeat_callback_fires_per_chunk() {
		$calls  = 0;
		$walker = $this->chunked_walker();
		$walker->set_progress_callback(
			function () use ( &$calls ) {
				++$calls;
			}
		);

		$result   = $walker->emit_sidecars( $this->backup_dir );
		$expected = (int) ceil( ( (int) $result['count'] ) / 2 );
		$this->assertSame( $expected, $calls, 'Heartbeat must fire once per spilled run.' );
	}

	/**
	 * Drive a sliced walk to completion, building a fresh walker per slice (as a
	 * real per-request runner would) and threading the cursor across slices.
	 *
	 * @param callable $factory Returns a fresh walker for each slice.
	 *
	 * @return array[] The per-slice results, last element done.
	 */
	private function drive_sliced( $factory ) {
		$cursor  = null;
		$results = array();
		$guard   = 0;
		do {
			$walker    = $factory();
			$out       = $walker->emit_sidecars_slice( $this->backup_dir, $cursor, time() + 1000 );
			$cursor    = $out['cursor'];
			$results[] = $out;
		} while ( empty( $out['done'] ) && ++$guard < 10000 );
		return $results;
	}

	/**
	 * A walk interrupted and resumed across many slices produces a manifest
	 * byte-identical to the one-shot walk - the 1A sorted-equivalence contract
	 * holds across resume. Root and subdir dotfiles are present so the resume
	 * re-derives the glob (root) and scandir (subdir) child lists.
	 *
	 * @return void
	 */
	public function test_resume_produces_byte_identical_manifest() {
		file_put_contents( $this->root . '/.htaccess', 'root' ); // phpcs:ignore -- test fixture.
		file_put_contents( $this->root . '/wp-content/.htaccess', 'sub' ); // phpcs:ignore -- test fixture.

		$oneshot   = $this->chunked_walker()->emit_sidecars( $this->backup_dir );
		$ref_files = file_get_contents( $this->backup_dir . '/files.list.staged' );
		$ref_paths = file_get_contents( $this->backup_dir . '/paths.cache.staged' );

		$results = $this->drive_sliced( fn() => new SlicingTestWalker( $this->root, array(), false, false, array( $this->backup_dir ) ) );
		$last    = end( $results );

		$this->assertTrue( $last['done'], 'The sliced walk must finish.' );
		$this->assertGreaterThan( 1, count( $results ), 'The walk must actually span multiple slices.' );
		$this->assertSame( $ref_files, file_get_contents( $this->backup_dir . '/files.list.staged' ), 'Resumed files.list must be byte-identical to the one-shot walk.' );
		$this->assertSame( $ref_paths, file_get_contents( $this->backup_dir . '/paths.cache.staged' ), 'Resumed paths.cache must be byte-identical to the one-shot walk.' );
		$this->assertSame( (int) $oneshot['count'], (int) $last['count'], 'Resumed count must match the one-shot count.' );
		$this->assertSame( (int) $oneshot['total_bytes'], (int) $last['total_bytes'], 'Resumed total_bytes must match.' );
	}

	/**
	 * Root-level dotfiles are skipped (glob) and subdir dotfiles are kept
	 * (scandir), independent of resume - locking the asymmetry the original
	 * walk_tree/walk_dir split had.
	 *
	 * @return void
	 */
	public function test_root_dotfiles_skipped_subdir_dotfiles_kept() {
		file_put_contents( $this->root . '/.htaccess', 'root' ); // phpcs:ignore -- test fixture.
		file_put_contents( $this->root . '/wp-content/.htaccess', 'sub' ); // phpcs:ignore -- test fixture.

		$this->walker()->emit_sidecars( $this->backup_dir );

		$relpaths = array_column( $this->parse_sidecar( $this->backup_dir . '/files.list.staged' ), 0 );
		$this->assertNotContains( '.htaccess', $relpaths, 'Root-level dotfiles must be skipped, as glob did.' );
		$this->assertContains( 'wp-content/.htaccess', $relpaths, 'Subdir dotfiles must be kept, as scandir did.' );
	}

	/**
	 * The live scanned count never decreases across slices and reaches the
	 * final manifest count - the signal the dashboard renders mid-walk.
	 *
	 * @return void
	 */
	public function test_live_count_climbs_across_slices() {
		$results = $this->drive_sliced( fn() => new SlicingTestWalker( $this->root, array(), false, false, array( $this->backup_dir ) ) );

		$prev = 0;
		foreach ( $results as $out ) {
			$this->assertGreaterThanOrEqual( $prev, (int) $out['count'], 'The scanned count must never go backwards.' );
			$prev = (int) $out['count'];
		}

		$last     = end( $results );
		$relpaths = array_column( $this->parse_sidecar( $this->backup_dir . '/files.list.staged' ), 0 );
		$this->assertSame( count( $relpaths ), (int) $last['count'], 'The final count must equal the manifest line count.' );
	}

	/**
	 * On resume the runs the cursor references survive and an orphan run a dead
	 * slice spilled past the last checkpoint is swept, never bleeding into the
	 * merge.
	 *
	 * @return void
	 */
	public function test_resume_keeps_runs_and_sweeps_orphans() {
		// One slice, then inspect the cursor.
		$first = ( new SlicingTestWalker( $this->root, array(), false, false, array( $this->backup_dir ) ) )
			->emit_sidecars_slice( $this->backup_dir, null, time() + 1000 );

		$this->assertFalse( $first['done'], 'The first slice should not finish the whole walk.' );
		$kept = $first['cursor']['runs'];

		// Plant an orphan run not referenced by the cursor.
		$orphan = $this->backup_dir . '/walk-run-9999';
		file_put_contents( $orphan, "ghost/file.php\t1\t1\tcore\n" ); // phpcs:ignore -- orphan from a dead slice.

		// Resume to completion.
		$cursor  = $first['cursor'];
		$results = array();
		$guard   = 0;
		do {
			$out       = ( new SlicingTestWalker( $this->root, array(), false, false, array( $this->backup_dir ) ) )
				->emit_sidecars_slice( $this->backup_dir, $cursor, time() + 1000 );
			$cursor    = $out['cursor'];
			$results[] = $out;
		} while ( empty( $out['done'] ) && ++$guard < 10000 );

		$this->assertNotEmpty( $kept, 'The first slice should have spilled at least one run.' );
		$this->assertFileDoesNotExist( $orphan, 'An orphan run must be swept on resume.' );
		$relpaths = array_column( $this->parse_sidecar( $this->backup_dir . '/files.list.staged' ), 0 );
		$this->assertNotContains( 'ghost/file.php', $relpaths, 'A swept orphan must never enter the manifest.' );
		$this->assertContains( 'wp-content/uploads/photo.jpg', $relpaths, 'Real files must survive the resume.' );
		$this->assertContains( 'index.php', $relpaths );
	}

	/**
	 * A run that is unreadable on a resumed merge triggers one clean-slate
	 * recovery, after which the walk completes with a correct manifest.
	 *
	 * @return void
	 */
	public function test_resume_recovers_from_corrupt_run_once() {
		$this->chunked_walker()->emit_sidecars( $this->backup_dir );
		$reference = array_column( $this->parse_sidecar( $this->backup_dir . '/files.list.staged' ), 0 );

		$results = $this->drive_sliced( fn() => new CorruptOnceSlicingWalker( $this->root, array(), false, false, array( $this->backup_dir ) ) );
		$last    = end( $results );

		$this->assertTrue( $last['done'], 'The walk must recover from the one-time corrupt run and finish.' );
		$relpaths = array_column( $this->parse_sidecar( $this->backup_dir . '/files.list.staged' ), 0 );
		$this->assertSame( $reference, $relpaths, 'The recovered manifest must match the clean reference.' );
	}
}
