<?php
/**
 * Class BackupIncrementalZipWriterTest
 *
 * @package Mainwp_Child
 */

use MainWP\Child\MainWP_Child_Backup_Incremental_Zip_Writer;
use MainWP\Child\MainWP_Backup;

/**
 * Tests for the rotating part writer.
 *
 * Incremental backups pack changed files into per-component ZIP parts
 * that rotate at size and entry-count thresholds. A ZIP killed mid-write
 * has no central directory (it is written only on close), so re-entry
 * verifies the part before appending: the archive must open and its
 * entry count must match the cursor's expectation, otherwise the part is
 * discarded and rebuilt from the part's first file.
 */
class BackupIncrementalZipWriterTest extends WP_UnitTestCase {

	/**
	 * Scratch dir for parts.
	 *
	 * @var string
	 */
	protected $dir;

	/**
	 * Source-file fixture dir.
	 *
	 * @var string
	 */
	protected $src;

	/**
	 * Build scratch dirs and source fixtures.
	 */
	public function set_up() {
		parent::set_up();
		$this->dir = sys_get_temp_dir() . '/mainwp-zipw-out-' . uniqid();
		$this->src = ABSPATH . 'mainwp-zipw-src-' . uniqid();
		mkdir( $this->dir );
		mkdir( $this->src );

		foreach ( array( 'a.php', 'b.php', 'c.php', 'd.php' ) as $name ) {
			file_put_contents( $this->src . '/' . $name, str_repeat( $name[0], 100 ) ); // phpcs:ignore -- test fixture.
		}
	}

	/**
	 * Remove the scratch dirs.
	 */
	public function tear_down() {
		foreach ( array( $this->dir, $this->src ) as $dir ) {
			foreach ( (array) glob( $dir . '/*' ) as $file ) {
				unlink( $file );
			}
			if ( is_dir( $dir ) ) {
				rmdir( $dir );
			}
		}
		parent::tear_down();
	}

	/**
	 * Entry names inside a part archive.
	 *
	 * @param string $basename Part file name.
	 *
	 * @return string[] Entry names.
	 */
	protected function part_entries( $basename ) {
		$zip = new ZipArchive();
		$this->assertTrue( $zip->open( $this->dir . '/' . $basename ) );
		$names = array();
		for ( $i = 0; $i < $zip->numFiles; $i++ ) {
			$names[] = $zip->getNameIndex( $i );
		}
		$zip->close();
		return $names;
	}

	/**
	 * Part names follow component.partNNN.zip.
	 */
	public function test_part_basename_format() {
		$this->assertSame( 'plugins.part001.zip', MainWP_Child_Backup_Incremental_Zip_Writer::part_basename( 'plugins', 1 ) );
		$this->assertSame( 'uploads.part010.zip', MainWP_Child_Backup_Incremental_Zip_Writer::part_basename( 'uploads', 10 ) );
		$this->assertSame( 'core.part123.zip', MainWP_Child_Backup_Incremental_Zip_Writer::part_basename( 'core', 123 ) );
	}

	/**
	 * A task token namespaces the part name so two tasks never collide on the
	 * same component+index; an empty token keeps the legacy format.
	 */
	public function test_part_basename_includes_task_token() {
		$this->assertSame( 'plugins.taskaaa.part001.zip', MainWP_Child_Backup_Incremental_Zip_Writer::part_basename( 'plugins', 1, 'taskaaa' ) );
		$this->assertSame( 'plugins.part001.zip', MainWP_Child_Backup_Incremental_Zip_Writer::part_basename( 'plugins', 1, '' ) );
	}

	/**
	 * Two tasks packing the same component+index produce distinct files in the
	 * shared dir, so one task's part never overwrites - or is pruned with -
	 * another's.
	 */
	public function test_task_token_namespaces_part_files() {
		$a = new MainWP_Child_Backup_Incremental_Zip_Writer( $this->dir, 'plugins', null, null, 'taskaaa' );
		$a->open_new_part( 1 );
		$a->add_file( $this->src . '/a.php', 'wp-content/plugins/x/a.php' );
		$reca = $a->close_part();

		$b = new MainWP_Child_Backup_Incremental_Zip_Writer( $this->dir, 'plugins', null, null, 'taskbbb' );
		$b->open_new_part( 1 );
		$b->add_file( $this->src . '/b.php', 'wp-content/plugins/x/b.php' );
		$recb = $b->close_part();

		$this->assertSame( 'plugins.taskaaa.part001.zip', $reca['file'] );
		$this->assertSame( 'plugins.taskbbb.part001.zip', $recb['file'] );
		$this->assertNotSame( $reca['file'], $recb['file'] );
		$this->assertFileExists( $this->dir . '/' . $reca['file'] );
		$this->assertFileExists( $this->dir . '/' . $recb['file'] );
		$this->assertSame( array( 'wp-content/plugins/x/a.php' ), $this->part_entries( $reca['file'] ), 'Task A part must not be overwritten by task B.' );
	}

	/**
	 * Files added to a fresh part land in a valid archive with the relpath
	 * as the entry name.
	 */
	public function test_new_part_packs_files() {
		$writer = new MainWP_Child_Backup_Incremental_Zip_Writer( $this->dir, 'plugins' );
		$writer->open_new_part( 1 );
		$writer->add_file( $this->src . '/a.php', 'wp-content/plugins/x/a.php' );
		$writer->add_file( $this->src . '/b.php', 'wp-content/plugins/x/b.php' );
		$record = $writer->close_part();

		$this->assertSame( 'plugins.part001.zip', $record['file'] );
		$this->assertSame( 2, $record['entries'] );
		$this->assertGreaterThan( 0, $record['size'] );
		$this->assertSame( (int) filesize( $this->dir . '/plugins.part001.zip' ), $record['size'] );

		$this->assertSame(
			array( 'wp-content/plugins/x/a.php', 'wp-content/plugins/x/b.php' ),
			$this->part_entries( 'plugins.part001.zip' )
		);
	}

	/**
	 * The writer rotates to a new part when the entry threshold is reached;
	 * the closed part is reported by add_file so the caller can checkpoint.
	 */
	public function test_rotation_on_entry_threshold() {
		$writer = new MainWP_Child_Backup_Incremental_Zip_Writer( $this->dir, 'core', 2 );
		$writer->open_new_part( 1 );

		$this->assertNull( $writer->add_file( $this->src . '/a.php', 'a.php' )['closed'] );
		$this->assertNull( $writer->add_file( $this->src . '/b.php', 'b.php' )['closed'] );

		$third = $writer->add_file( $this->src . '/c.php', 'c.php' );
		$this->assertTrue( $third['rotated'] );
		$this->assertSame( 'core.part001.zip', $third['closed']['file'] );
		$this->assertSame( 2, $third['closed']['entries'] );

		$last = $writer->close_part();
		$this->assertSame( 'core.part002.zip', $last['file'] );
		$this->assertSame( 1, $last['entries'] );

		$this->assertSame( array( 'a.php', 'b.php' ), $this->part_entries( 'core.part001.zip' ) );
		$this->assertSame( array( 'c.php' ), $this->part_entries( 'core.part002.zip' ) );
	}

	/**
	 * The writer also rotates when accumulated source bytes pass the byte
	 * threshold.
	 */
	public function test_rotation_on_byte_threshold() {
		$writer = new MainWP_Child_Backup_Incremental_Zip_Writer( $this->dir, 'uploads', 1000, 150 );
		$writer->open_new_part( 1 );

		$writer->add_file( $this->src . '/a.php', 'a.php' );   // 100 bytes.
		$second = $writer->add_file( $this->src . '/b.php', 'b.php' ); // Crosses 150.

		$this->assertTrue( $second['rotated'] );
		$this->assertSame( 'uploads.part001.zip', $second['closed']['file'] );
		$this->assertSame( array( 'a.php' ), $this->part_entries( 'uploads.part001.zip' ) );

		$writer->close_part();
		$this->assertSame( array( 'b.php' ), $this->part_entries( 'uploads.part002.zip' ) );
	}

	/**
	 * An intact closed part with the expected entry count reopens for
	 * appending; new entries land alongside the old ones.
	 */
	public function test_verify_intact_part_allows_append() {
		$writer = new MainWP_Child_Backup_Incremental_Zip_Writer( $this->dir, 'core' );
		$writer->open_new_part( 1 );
		$writer->add_file( $this->src . '/a.php', 'a.php' );
		$writer->close_part();

		$resumed = new MainWP_Child_Backup_Incremental_Zip_Writer( $this->dir, 'core' );
		$this->assertTrue( $resumed->open_for_append( 1, 1 ) );
		$resumed->add_file( $this->src . '/b.php', 'b.php' );
		$record = $resumed->close_part();

		$this->assertSame( 2, $record['entries'] );
		$this->assertSame( array( 'a.php', 'b.php' ), $this->part_entries( 'core.part001.zip' ) );
	}

	/**
	 * A part whose entry count does not match the cursor's expectation is
	 * rejected - entries past the checkpoint would otherwise duplicate or
	 * go missing.
	 */
	public function test_verify_count_mismatch_rejects_append() {
		$writer = new MainWP_Child_Backup_Incremental_Zip_Writer( $this->dir, 'core' );
		$writer->open_new_part( 1 );
		$writer->add_file( $this->src . '/a.php', 'a.php' );
		$writer->add_file( $this->src . '/b.php', 'b.php' );
		$writer->close_part();

		$resumed = new MainWP_Child_Backup_Incremental_Zip_Writer( $this->dir, 'core' );
		$this->assertFalse( $resumed->open_for_append( 1, 1 ) );
	}

	/**
	 * A truncated part - killed mid-write, no central directory - is
	 * rejected on re-entry.
	 */
	public function test_verify_truncated_part_rejects_append() {
		file_put_contents( $this->dir . '/core.part001.zip', 'PK not actually a zip' ); // phpcs:ignore -- test fixture: torn write.

		$writer = new MainWP_Child_Backup_Incremental_Zip_Writer( $this->dir, 'core' );
		$this->assertFalse( $writer->open_for_append( 1, 1 ) );
	}

	/**
	 * A missing part is rejected on re-entry when entries were expected.
	 */
	public function test_verify_missing_part_rejects_append() {
		$writer = new MainWP_Child_Backup_Incremental_Zip_Writer( $this->dir, 'core' );
		$this->assertFalse( $writer->open_for_append( 1, 3 ) );
	}

	/**
	 * Opening a new part over a discarded corrupt file rebuilds it from
	 * scratch.
	 */
	public function test_open_new_part_replaces_corrupt_file() {
		file_put_contents( $this->dir . '/core.part001.zip', 'garbage' ); // phpcs:ignore -- test fixture: torn write.

		$writer = new MainWP_Child_Backup_Incremental_Zip_Writer( $this->dir, 'core' );
		$writer->open_new_part( 1 );
		$writer->add_file( $this->src . '/a.php', 'a.php' );
		$record = $writer->close_part();

		$this->assertSame( 1, $record['entries'] );
		$this->assertSame( array( 'a.php' ), $this->part_entries( 'core.part001.zip' ) );
	}

	/**
	 * The size reported by close_part matches the bytes on disk even when
	 * a stat-family call primed PHP's stat cache for the part path earlier
	 * in the request. Some runtimes do not invalidate the cache when the
	 * archive is finalized, which would otherwise report a pre-close size.
	 */
	public function test_close_part_size_matches_disk_after_stat_prime() {
		$path = $this->dir . '/core.part001.zip';

		$writer = new MainWP_Child_Backup_Incremental_Zip_Writer( $this->dir, 'core' );
		$writer->open_new_part( 1 );
		$writer->add_file( $this->src . '/a.php', 'a.php' );

		// Prime the stat cache with the pre-close state of the part path.
		file_exists( $path );

		$record = $writer->close_part();

		clearstatcache( true, $path );
		$this->assertSame( (int) filesize( $path ), $record['size'] );
		$this->assertGreaterThan( 0, $record['size'] );
	}

	/**
	 * Same contract on the append path: open_for_append reads the closed
	 * part's size (priming the stat cache with the old byte count), so the
	 * size reported after appending must still be the fresh on-disk size.
	 */
	public function test_close_part_size_matches_disk_after_append_stat_prime() {
		$path = $this->dir . '/core.part001.zip';

		$writer = new MainWP_Child_Backup_Incremental_Zip_Writer( $this->dir, 'core' );
		$writer->open_new_part( 1 );
		$writer->add_file( $this->src . '/a.php', 'a.php' );
		$old_size = $writer->close_part()['size'];

		$resumed = new MainWP_Child_Backup_Incremental_Zip_Writer( $this->dir, 'core' );
		$this->assertTrue( $resumed->open_for_append( 1, 1 ) );
		$resumed->add_file( $this->src . '/b.php', 'b.php' );
		$resumed->add_file( $this->src . '/c.php', 'c.php' );
		$record = $resumed->close_part();

		clearstatcache( true, $path );
		$this->assertSame( (int) filesize( $path ), $record['size'] );
		$this->assertGreaterThan( $old_size, $record['size'] );
	}

	/**
	 * A source file that vanished between the walk and the pack is skipped
	 * without aborting the part.
	 */
	public function test_missing_source_file_skipped() {
		$writer = new MainWP_Child_Backup_Incremental_Zip_Writer( $this->dir, 'core' );
		$writer->open_new_part( 1 );

		$result = $writer->add_file( $this->src . '/gone.php', 'gone.php' );
		$this->assertTrue( $result['skipped'] );

		$writer->add_file( $this->src . '/a.php', 'a.php' );
		$record = $writer->close_part();

		$this->assertSame( 1, $record['entries'] );
		$this->assertSame( array( 'a.php' ), $this->part_entries( 'core.part001.zip' ) );
	}
	public function test_inside_link_skipped_and_recorded() {
		$link = $this->src . '/linked.php';
		if ( ! function_exists( 'symlink' ) || ! @symlink( $this->src . '/a.php', $link ) ) {
			$this->markTestSkipped( 'Filesystem does not support symlinks.' );
		}
		MainWP_Backup::reset_skipped_paths();
		$writer = new MainWP_Child_Backup_Incremental_Zip_Writer( $this->dir, 'core' );
		$writer->open_new_part( 1 );
		$result = $writer->add_file( $link, 'linked.php' );
		$writer->add_file( $this->src . '/a.php', 'a.php' );
		$part = $writer->close_part();
		$this->assertTrue( $result['skipped'] );
		$this->assertContains( $link, MainWP_Backup::get_skipped_paths() );
		$this->assertSame( array( 'a.php' ), $this->part_entries( $part['file'] ) );
		MainWP_Backup::reset_skipped_paths();
	}

	public function test_file_symlinks_in_stale_paths_cache_stay_skipped_after_resume() {
		$links = array( 'relative.php' => 'a.php', 'absolute.php' => $this->src . '/a.php', 'outside.php' => $this->dir . '/outside.php', 'dangling.php' => 'missing.php' );
		file_put_contents( $this->dir . '/outside.php', 'outside bytes' );
		foreach ( $links as $path => $target ) {
			if ( ! function_exists( 'symlink' ) || ! @symlink( $target, $this->src . '/' . $path ) ) {
				$this->markTestSkipped( 'Filesystem does not support symlinks.' );
			}
		}
		file_put_contents( $this->dir . '/paths.cache', "core\trelative.php\ncore\tabsolute.php\ncore\toutside.php\ncore\tdangling.php\n" );
		MainWP_Backup::reset_skipped_paths();
		$writer = new MainWP_Child_Backup_Incremental_Zip_Writer( $this->dir, 'core' );
		$writer->open_new_part( 1 );
		$writer->add_file( $this->src . '/a.php', 'a.php' );
		foreach ( array( false, true ) as $resume ) {
			if ( $resume ) {
				$writer = new MainWP_Child_Backup_Incremental_Zip_Writer( $this->dir, 'core' );
				$this->assertTrue( $writer->open_for_append( 1, 1 ) );
			}
			foreach ( file( $this->dir . '/paths.cache', FILE_IGNORE_NEW_LINES ) as $line ) {
				list( , $path ) = explode( "\t", $line );
				$result = $writer->add_file( $this->src . '/' . $path, $path );
				$this->assertTrue( $result['skipped'], $path );
				$this->assertFalse( $result['rotated'] );
				$this->assertContains( $this->src . '/' . $path, MainWP_Backup::get_skipped_paths() );
			}
			$part = $writer->close_part();
			$this->assertSame( 1, $part['entries'] );
			$this->assertSame( array( 'a.php' ), $this->part_entries( $part['file'] ) );
		}
		MainWP_Backup::reset_skipped_paths();
	}

	public function test_pack_time_outside_link_swap_is_skipped_and_recorded() {
		MainWP_Backup::reset_skipped_paths();
		$source = $this->src . '/a.php';
		$outside = $this->dir . '/outside.php';
		file_put_contents( $outside, 'outside bytes' );
		$writer = new MainWP_Child_Backup_Incremental_Zip_Writer( $this->dir, 'core', null, null, '', array( $this->src ) );
		$this->assertTrue( is_file( $source ) && ! is_link( $source ) );
		realpath( $source );
		$writer->open_new_part( 1 );
		$writer->add_file( $this->src . '/b.php', 'b.php' );
		unlink( $source );
		symlink( $outside, $source );
		try {
			$result = $writer->add_file( $source, 'a.php' );
			$part = $writer->close_part();
			$this->assertTrue( $result['skipped'], 'A walk-to-pack swap must not escape the bases.' );
			$this->assertContains( $source, MainWP_Backup::get_skipped_paths() );
			$this->assertSame( array( 'b.php' ), $this->part_entries( $part['file'] ) );
			$this->assertSame( 1, $part['entries'] );
		} finally {
			MainWP_Backup::reset_skipped_paths();
		}
	}

}
