<?php
/**
 * Class BackupFileRestorerTest
 *
 * @package Mainwp_Child
 */

use MainWP\Child\MainWP_Child_Backup_File_Restorer;
use MainWP\Child\MainWP_Backup_Error;

/**
 * Restorer that forces every .tar.gz decompression to hit the slice deadline,
 * standing in for a stream too large to finish, without depending on wall-clock
 * timing.
 */
class TimeoutDecompressRestorer extends MainWP_Child_Backup_File_Restorer {

	/**
	 * Force the timeout signal for compressed parts; plain tars pass through.
	 *
	 * @param string    $part      Archive path.
	 * @param float|int $deadline  Decompression deadline.
	 * @param bool      $timed_out Set true on a forced timeout (by reference).
	 *
	 * @return string|null Plain tar path, or null on the forced timeout.
	 */
	protected function plain_tar_for( $part, $deadline, &$timed_out ) {
		if ( preg_match( '/\.tar\.gz$/i', $part ) ) {
			$timed_out = true;
			return null;
		}
		return parent::plain_tar_for( $part, $deadline, $timed_out );
	}
}

/**
 * Bypass decompression so malformed tar bytes reach PharData directly.
 */
class InvalidTarDecompressRestorer extends MainWP_Child_Backup_File_Restorer {

	/**
	 * Use the malformed fixture to isolate archive-open failures.
	 *
	 * @param string    $part      Archive path.
	 * @param float|int $deadline  Decompression deadline.
	 * @param bool      $timed_out Timeout signal (by reference).
	 *
	 * @return string Plain tar fixture path.
	 */
	protected function plain_tar_for( $part, $deadline, &$timed_out ) {
		$timed_out = false;
		return $part . '.plain.tar';
	}

	/**
	 * Reach the archive-open message builder with a crafted exception.
	 *
	 * @param \Throwable $e     Archive-open failure.
	 * @param string     $part  Original archive path.
	 * @param string     $plain Plain tar path.
	 *
	 * @return string Result text.
	 */
	public function public_archive_open_error( $e, $part, $plain ) {
		return $this->archive_open_error( $e, $part, $plain );
	}
}

/**
 * Stand in for a host whose PHP has the phar extension disabled.
 */
class PharlessRestorer extends InvalidTarDecompressRestorer {

	/**
	 * Report phar as unavailable.
	 *
	 * @return bool Always false.
	 */
	protected function phar_available() {
		return false;
	}
}

/**
 * Reach the copy fallback even on filesystems that support hard links.
 */
class CopyFallbackRestorer extends MainWP_Child_Backup_File_Restorer {

	/**
	 * Prevent filesystem support from bypassing the copy regression checks.
	 *
	 * @param string $part  Archive path.
	 * @param string $plain Alias path.
	 *
	 * @return bool Always false.
	 */
	protected function link_part( $part, $plain ) {
		return false;
	}
}

/**
 * Reproduce a successful copy whose temporary file lost bytes before promotion.
 */
class ShortCopyRestorer extends CopyFallbackRestorer {

	/**
	 * A successful return must not let an incomplete file bypass the size check.
	 *
	 * @param string $part Archive path.
	 * @param string $tmp  Temporary copy path.
	 *
	 * @return bool Whether writing the short fixture succeeded.
	 */
	protected function copy_part( $part, $tmp ) {
		return false !== file_put_contents( $tmp, 'short' );
	}
}

/**
 * Tests for the chunked, hash-verified, zip-slip-guarded file restorer.
 *
 * Every archive verifies its sha256 against the expected hash before a
 * single entry is extracted; every entry path passes the extraction
 * guard; the entry cursor makes extraction resumable across budget
 * yields; tombstones delete files through the same path guard.
 */
class BackupFileRestorerTest extends WP_UnitTestCase {

	/**
	 * Scratch dir holding archives and the extraction root.
	 *
	 * @var string
	 */
	protected $dir;

	/**
	 * Extraction root.
	 *
	 * @var string
	 */
	protected $extract_to;

	/**
	 * Restorer under test.
	 *
	 * @var MainWP_Child_Backup_File_Restorer
	 */
	protected $restorer;

	/**
	 * Build scratch dirs.
	 */
	public function set_up() {
		parent::set_up();
		$this->dir        = sys_get_temp_dir() . '/mainwp-file-restore-' . uniqid();
		$this->extract_to = $this->dir . '/site';
		mkdir( $this->extract_to, 0777, true );
		$this->restorer = new MainWP_Child_Backup_File_Restorer();
	}

	/**
	 * Remove scratch dirs.
	 */
	public function tear_down() {
		$this->rrmdir( $this->dir );
		parent::tear_down();
	}

	/**
	 * Recursive cleanup.
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
	 * Build a zip archive fixture.
	 *
	 * @param string $name    Archive file name.
	 * @param array  $entries relpath => content map.
	 *
	 * @return string Archive path.
	 */
	protected function make_zip( $name, $entries ) {
		$path = $this->dir . '/' . $name;
		$zip  = new ZipArchive();
		$zip->open( $path, ZipArchive::CREATE );
		foreach ( $entries as $relpath => $content ) {
			$zip->addFromString( $relpath, $content );
		}
		$zip->close();
		return $path;
	}

	/**
	 * Build a plain tar archive fixture.
	 *
	 * @param string $name    Archive file name (.tar).
	 * @param array  $entries relpath => content map.
	 *
	 * @return string Archive path.
	 */
	protected function make_tar( $name, $entries ) {
		$path = $this->dir . '/' . $name;
		$phar = new PharData( $path );
		foreach ( $entries as $relpath => $content ) {
			$phar->addFromString( $relpath, $content );
		}
		return $path;
	}

	/**
	 * Config for a set of parts with correct hashes.
	 *
	 * @param string[] $parts Archive paths.
	 * @param array    $over  Overrides.
	 *
	 * @return array Config.
	 */
	protected function config( $parts, $over = array() ) {
		$hashes = array();
		foreach ( $parts as $part ) {
			$hashes[ basename( $part ) ] = hash_file( 'sha256', $part );
		}
		return array_merge(
			array(
				'parts'      => $parts,
				'hashes'     => $hashes,
				'extract_to' => $this->extract_to,
			),
			$over
		);
	}

	/**
	 * Run restore_slice to completion.
	 *
	 * @param array $config Config.
	 *
	 * @return array Final result.
	 */
	protected function run_all( $config ) {
		$result = array( 'cursor' => array() );
		$guard  = 0;
		do {
			$result = $this->restorer->restore_slice( $result['cursor'], $config, microtime( true ) + 3600 );
			++$guard;
		} while ( ! $result['done'] && null === $result['error'] && $guard < 100 );
		return $result;
	}

	/**
	 * Entries land at their validated paths with intact content.
	 */
	public function test_zip_entry_extracted_to_correct_path() {
		$zip    = $this->make_zip( 'part001.zip', array(
			'wp-content/uploads/a.txt' => 'alpha',
			'index.php'                => '<?php // silence',
		) );
		$result = $this->run_all( $this->config( array( $zip ) ) );

		$this->assertNull( $result['error'] );
		$this->assertTrue( $result['done'] );
		$this->assertSame( 'alpha', file_get_contents( $this->extract_to . '/wp-content/uploads/a.txt' ) );
		$this->assertFileExists( $this->extract_to . '/index.php' );
	}

	/**
	 * A hash mismatch fails before any entry is written.
	 */
	public function test_archive_sha256_verified_before_extraction() {
		$zip    = $this->make_zip( 'part001.zip', array( 'evil.txt' => 'x' ) );
		$config = $this->config( array( $zip ) );

		$config['hashes'][ basename( $zip ) ] = str_repeat( '0', 64 );
		$result                               = $this->restorer->restore_slice( array(), $config, microtime( true ) + 3600 );

		$this->assertNotNull( $result['error'] );
		$this->assertSame( MainWP_Backup_Error::ERR_FILE_CORRUPT, $result['error_code'] );
		$this->assertFileDoesNotExist( $this->extract_to . '/evil.txt' );
	}

	/**
	 * A missing expected hash also refuses to extract.
	 */
	public function test_missing_hash_refuses_extraction() {
		$zip    = $this->make_zip( 'part001.zip', array( 'a.txt' => 'x' ) );
		$config = $this->config( array( $zip ) );
		unset( $config['hashes'][ basename( $zip ) ] );

		$result = $this->restorer->restore_slice( array(), $config, microtime( true ) + 3600 );
		$this->assertNotNull( $result['error'] );
		$this->assertFileDoesNotExist( $this->extract_to . '/a.txt' );
	}

	/**
	 * A pre-existing leaf symlink at the target path is not followed:
	 * extraction replaces it with a regular file and never writes through
	 * it to a location outside the extraction root.
	 */
	public function test_extraction_does_not_follow_leaf_symlink() {
		$outside = $this->dir . '/outside-secret.txt';
		file_put_contents( $outside, 'SECRET' );

		mkdir( $this->extract_to . '/wp-content', 0777, true );
		if ( true !== @symlink( $outside, $this->extract_to . '/wp-content/evil.txt' ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors -- probe symlink support.
			$this->markTestSkipped( 'Filesystem does not support symlinks.' );
		}

		$zip    = $this->make_zip( 'part001.zip', array( 'wp-content/evil.txt' => 'ARCHIVE' ) );
		$result = $this->run_all( $this->config( array( $zip ) ) );

		$this->assertNull( $result['error'] );
		$this->assertSame( 'SECRET', file_get_contents( $outside ), 'The symlink target outside the root must be untouched.' );
		$this->assertFalse( is_link( $this->extract_to . '/wp-content/evil.txt' ), 'The leaf symlink must be replaced by a regular file.' );
		$this->assertSame( 'ARCHIVE', file_get_contents( $this->extract_to . '/wp-content/evil.txt' ) );
	}

	/**
	 * The same leaf-symlink guard holds for tar extraction.
	 */
	public function test_tar_extraction_does_not_follow_leaf_symlink() {
		$outside = $this->dir . '/outside-secret-tar.txt';
		file_put_contents( $outside, 'SECRET' );

		mkdir( $this->extract_to . '/wp-content', 0777, true );
		if ( true !== @symlink( $outside, $this->extract_to . '/wp-content/evil.txt' ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors -- probe symlink support.
			$this->markTestSkipped( 'Filesystem does not support symlinks.' );
		}

		$tar = $this->make_tar( 'part001.tar', array( 'wp-content/evil.txt' => 'ARCHIVE' ) );
		$result = $this->run_all( $this->config( array( $tar ) ) );

		$this->assertNull( $result['error'] );
		$this->assertSame( 'SECRET', file_get_contents( $outside ), 'The symlink target outside the root must be untouched.' );
		$this->assertFalse( is_link( $this->extract_to . '/wp-content/evil.txt' ) );
		$this->assertSame( 'ARCHIVE', file_get_contents( $this->extract_to . '/wp-content/evil.txt' ) );
	}

	/**
	 * Budget exhaustion yields a cursor; resuming completes without
	 * losing or duplicating entries.
	 */
	public function test_restore_slice_resumes_from_entry_cursor() {
		$entries = array();
		for ( $i = 0; $i < 9; $i++ ) {
			$entries[ "files/f$i.txt" ] = "content-$i";
		}
		$zip    = $this->make_zip( 'part001.zip', $entries );
		$config = $this->config( array( $zip ) );

		$result = $this->restorer->restore_slice( array(), $config, 0 );
		$this->assertFalse( $result['done'] );
		$this->assertNull( $result['error'] );

		$guard = 0;
		while ( ! $result['done'] && $guard < 100 ) {
			$result = $this->restorer->restore_slice( $result['cursor'], $config, 0 );
			$this->assertNull( $result['error'] );
			++$guard;
		}
		$this->assertTrue( $result['done'] );

		for ( $i = 0; $i < 9; $i++ ) {
			$this->assertSame( "content-$i", file_get_contents( $this->extract_to . "/files/f$i.txt" ), "f$i" );
		}
	}

	/**
	 * Later parts overwrite earlier ones (incremental apply order).
	 */
	public function test_multiple_parts_processed_sequentially() {
		$base = $this->make_zip( 'base.zip', array(
			'a.txt' => 'old',
			'b.txt' => 'keep',
		) );
		$incr = $this->make_zip( 'incr.zip', array( 'a.txt' => 'new' ) );

		$result = $this->run_all( $this->config( array( $base, $incr ) ) );
		$this->assertTrue( $result['done'] );
		$this->assertSame( 'new', file_get_contents( $this->extract_to . '/a.txt' ) );
		$this->assertSame( 'keep', file_get_contents( $this->extract_to . '/b.txt' ) );
	}

	/**
	 * A traversal entry aborts extraction.
	 */
	public function test_path_traversal_in_zip_rejected() {
		$zip    = $this->make_zip( 'evil.zip', array(
			'ok.txt'       => 'fine',
			'../break.txt' => 'outside',
		) );
		$result = $this->run_all( $this->config( array( $zip ) ) );

		$this->assertNotNull( $result['error'] );
		$this->assertSame( MainWP_Backup_Error::ERR_RESTORE_EXTRACT, $result['error_code'] );
		$this->assertFileDoesNotExist( dirname( $this->extract_to ) . '/break.txt' );
	}

	/**
	 * Tombstones delete their files.
	 */
	public function test_tombstone_application_deletes_files() {
		mkdir( $this->extract_to . '/sub' );
		file_put_contents( $this->extract_to . '/gone.txt', 'x' ); // phpcs:ignore -- fixture.
		file_put_contents( $this->extract_to . '/sub/also-gone.txt', 'x' ); // phpcs:ignore -- fixture.
		file_put_contents( $this->extract_to . '/stays.txt', 'x' ); // phpcs:ignore -- fixture.

		$tombstones = $this->dir . '/tombstones.list';
		file_put_contents( $tombstones, "gone.txt\nsub/also-gone.txt\nnever-existed.txt\n" ); // phpcs:ignore -- fixture.

		$result = $this->restorer->apply_tombstones( $tombstones, $this->extract_to );
		$this->assertNull( $result['error'] );
		$this->assertSame( 2, $result['deleted'] );
		$this->assertFileDoesNotExist( $this->extract_to . '/gone.txt' );
		$this->assertFileDoesNotExist( $this->extract_to . '/sub/also-gone.txt' );
		$this->assertFileExists( $this->extract_to . '/stays.txt' );
	}

	/**
	 * A tombstone pointing outside the extraction root aborts.
	 */
	public function test_tombstone_outside_extract_dir_rejected() {
		file_put_contents( dirname( $this->extract_to ) . '/outside.txt', 'x' ); // phpcs:ignore -- fixture.

		$tombstones = $this->dir . '/tombstones.list';
		file_put_contents( $tombstones, "../outside.txt\n" ); // phpcs:ignore -- fixture.

		$result = $this->restorer->apply_tombstones( $tombstones, $this->extract_to );
		$this->assertNotNull( $result['error'] );
		$this->assertFileExists( dirname( $this->extract_to ) . '/outside.txt' );
	}

	/**
	 * Directory entries in the archive create directories, not files.
	 */
	public function test_directory_entries_handled() {
		$path = $this->dir . '/dirs.zip';
		$zip  = new ZipArchive();
		$zip->open( $path, ZipArchive::CREATE );
		$zip->addEmptyDir( 'empty-dir' );
		$zip->addFromString( 'filled/file.txt', 'x' );
		$zip->close();

		$result = $this->run_all( $this->config( array( $path ) ) );
		$this->assertTrue( $result['done'] );
		$this->assertDirectoryExists( $this->extract_to . '/empty-dir' );
		$this->assertSame( 'x', file_get_contents( $this->extract_to . '/filled/file.txt' ) );
	}

	/**
	 * A tar.gz part extracts through the same guard and cursor (the
	 * dashboard's default archive format is tar.gz).
	 */
	public function test_tar_gz_part_extracted() {
		$tar = $this->dir . '/dump.tar';
		$phar = new PharData( $tar );
		$phar->addFromString( 'dbBackup-wp_a.sql.php', '<?php exit(); ?>' . "\nCREATE TABLE `wp_a` (id INT);\n" );
		$phar->addFromString( 'sub/nested.txt', 'nested-bytes' );
		$phar->compress( Phar::GZ );
		unset( $phar );
		Phar::unlinkArchive( $tar );

		$result = $this->run_all( $this->config( array( $tar . '.gz' ) ) );
		$this->assertNull( $result['error'] );
		$this->assertTrue( $result['done'] );
		$this->assertStringContainsString( 'CREATE TABLE', file_get_contents( $this->extract_to . '/dbBackup-wp_a.sql.php' ) );
		$this->assertSame( 'nested-bytes', file_get_contents( $this->extract_to . '/sub/nested.txt' ) );
	}

	/**
	 * A dashboard-named tar.gz (site host plus date stamp) decompresses to a
	 * plain tar PharData accepts. Appending .plain.tar to this fixture's
	 * name puts 53 characters after the first dot, past PharData's 50 limit.
	 */
	public function test_tar_gz_with_dashboard_name_extracts() {
		$tar = $this->dir . '/dump.tar';
		$phar = new PharData( $tar );
		$phar->addFromString( 'wp-content/uploads/marker.png', 'png-bytes' );
		$phar->compress( Phar::GZ );
		unset( $phar );
		Phar::unlinkArchive( $tar );
		$part = $this->dir . '/s42-full-site.example.com-September-17-2026-23-55.tar.gz';
		$old_alias = basename( $part ) . '.plain.tar';
		$this->assertSame( 53, strlen( $old_alias ) - strpos( $old_alias, '.' ), 'The old alias must exceed the PharData name limit.' );
		rename( $tar . '.gz', $part );

		$result = $this->run_all( $this->config( array( $part ) ) );
		$this->assertNull( $result['error'] );
		$this->assertTrue( $result['done'] );
		$this->assertSame( 'png-bytes', file_get_contents( $this->extract_to . '/wp-content/uploads/marker.png' ) );
		$this->assertFileDoesNotExist( $this->dir . '/s42-full-site-example-com-September-17-2026-23-55-' . substr( sha1( basename( $part ) ), 0, 12 ) . '.plain.tar', 'The plain tar is discarded after extraction.' );
		$this->assertFileExists( $part, 'The part itself is left for the caller to clean up.' );
	}

	/**
	 * An uncompressed tar whose name PharData refuses outright (a dotted host
	 * long enough to push the dot-tail to 50+ characters) reads through a
	 * short-named hard link that is removed afterwards.
	 */
	public function test_plain_tar_with_long_dotted_name_extracts() {
		$name = 's42-full-site.example-staging-site.test-September-17-2026-23-55.tar';
		$this->assertGreaterThanOrEqual( 50, strlen( $name ) - strpos( $name, '.' ), 'The fixture name must be one PharData rejects.' );
		// PharData cannot create the fixture under that name either.
		$part = $this->dir . '/' . $name;
		rename( $this->make_tar( 'fixture.tar', array( 'index.php' => '<?php // site' ) ), $part );

		$result = $this->run_all( $this->config( array( $part ) ) );
		$this->assertNull( $result['error'] );
		$this->assertTrue( $result['done'] );
		$this->assertSame( '<?php // site', file_get_contents( $this->extract_to . '/index.php' ) );
		$this->assertFileDoesNotExist( $this->dir . '/s42-full-site-example-staging-site-test-September-17-2026-23-55-' . substr( sha1( basename( $part ) ), 0, 12 ) . '.tar' );
		$this->assertFileExists( $part );
	}

	/**
	 * A stale alias can be a readable tar while still missing the part's entries.
	 */
	public function test_plain_tar_replaces_incomplete_alias() {
		$name = 's42-full-site.example-staging-site.test-September-17-2026-23-55.tar';
		$this->assertGreaterThanOrEqual( 50, strlen( $name ) - strpos( $name, '.' ), 'The fixture name must be one PharData rejects.' );
		$part = $this->dir . '/' . $name;
		$content = str_repeat( 'part-content', 512 );
		rename( $this->make_tar( 'fixture.tar', array( 'index.php' => $content ) ), $part );
		$plain = $this->dir . '/s42-full-site-example-staging-site-test-September-17-2026-23-55-' . substr( sha1( $name ), 0, 12 ) . '.tar';
		rename( $this->make_tar( 'stale.tar', array( 'stale.txt' => 'stale-content' ) ), $plain );
		$this->assertLessThan( filesize( $part ), filesize( $plain ), 'The stale copy must be shorter than the part.' );

		$result = $this->run_all( $this->config( array( $part ) ) );
		$this->assertNull( $result['error'] );
		$this->assertTrue( $result['done'] );
		$this->assertSame( $content, file_get_contents( $this->extract_to . '/index.php' ) );
		$this->assertFileDoesNotExist( $this->extract_to . '/stale.txt' );
		$this->assertFileDoesNotExist( $plain );
		$this->assertFileExists( $part );
	}

	/**
	 * A temporary copy from an interrupted request must not replace the part.
	 */
	public function test_plain_tar_does_not_promote_leftover_tmp_alias() {
		$this->restorer = new CopyFallbackRestorer();
		$name = 's42-full-site.example-staging-site.test-September-17-2026-23-55.tar';
		$this->assertGreaterThanOrEqual( 50, strlen( $name ) - strpos( $name, '.' ), 'The fixture name must be one PharData rejects.' );
		$part = $this->dir . '/' . $name;
		$content = str_repeat( 'part-content', 512 );
		rename( $this->make_tar( 'fixture.tar', array( 'index.php' => $content ) ), $part );
		$plain = $this->dir . '/s42-full-site-example-staging-site-test-September-17-2026-23-55-' . substr( sha1( $name ), 0, 12 ) . '.tar';
		$tmp = $plain . '.' . getmypid() . '.tmp';
		rename( $this->make_tar( 'stale.tar', array( 'stale.txt' => 'stale-content' ) ), $tmp );
		$this->assertLessThan( filesize( $part ), filesize( $tmp ), 'The stale copy must be shorter than the part.' );

		$result = $this->run_all( $this->config( array( $part ) ) );
		$this->assertNull( $result['error'] );
		$this->assertTrue( $result['done'] );
		$this->assertSame( $content, file_get_contents( $this->extract_to . '/index.php' ) );
		$this->assertFileDoesNotExist( $this->extract_to . '/stale.txt' );
		$this->assertFileDoesNotExist( $plain );
		$this->assertFileExists( $part );
		$this->assertSame( array(), glob( $plain . '*.tmp' ), 'No temporary copy may survive a successful restore.' );
	}

	/**
	 * A copy's success flag cannot prove that its temporary file is complete.
	 */
	public function test_plain_tar_does_not_promote_short_copy() {
		$this->restorer = new ShortCopyRestorer();
		$name = 's42-full-site.example-staging-site.test-September-17-2026-23-55.tar';
		$this->assertGreaterThanOrEqual( 50, strlen( $name ) - strpos( $name, '.' ) );
		$part = $this->dir . '/' . $name;
		rename( $this->make_tar( 'fixture.tar', array( 'index.php' => 'part-content' ) ), $part );
		$plain = $this->dir . '/s42-full-site-example-staging-site-test-September-17-2026-23-55-' . substr( sha1( $name ), 0, 12 ) . '.tar';
		$this->assertGreaterThan( strlen( 'short' ), filesize( $part ) );

		$result = $this->run_all( $this->config( array( $part ) ) );
		$this->assertFalse( $result['done'] );
		$this->assertSame( MainWP_Backup_Error::ERR_RESTORE_EXTRACT, $result['error_code'] );
		$this->assertSame( 'Could not prepare archive for reading: ' . $name, $result['error'] );
		$this->assertFileDoesNotExist( $plain );
		$this->assertFileDoesNotExist( $this->extract_to . '/index.php' );
		$this->assertSame( array(), glob( $plain . '*.tmp' ), 'A rejected copy must be removed.' );
		$this->assertFileExists( $part );
	}

	/**
	 * Dotted and dashed archive names must not reuse each other's plain tar.
	 */
	public function test_phar_safe_tar_paths_distinguish_dotted_and_dashed_names() {
		$method = new ReflectionMethod( MainWP_Child_Backup_File_Restorer::class, 'phar_safe_tar_path' );
		$method->setAccessible( true );
		$dotted = $method->invoke( null, $this->dir . '/a.b-September-17-2026-23-55.tar.gz' );
		$dashed = $method->invoke( null, $this->dir . '/a-b-September-17-2026-23-55.tar.gz' );

		$this->assertNotSame( $dotted, $dashed );
		$this->assertStringEndsWith( '.plain.tar', $dotted );
		$this->assertStringEndsWith( '.plain.tar', $dashed );
	}

	/**
	 * A bzip2 alias needs its compression extension because its bytes stay compressed.
	 */
	public function test_phar_safe_tar_path_preserves_bz2_extension() {
		$name = 's42-full-site.example-staging-site.test-September-17-2026-23-55.tar.bz2';
		$this->assertGreaterThanOrEqual( 50, strlen( $name ) - strpos( $name, '.' ), 'The fixture name must be one PharData rejects.' );
		$method = new ReflectionMethod( MainWP_Child_Backup_File_Restorer::class, 'phar_safe_tar_path' );
		$method->setAccessible( true );
		$alias = $method->invoke( null, $this->dir . '/' . $name );

		$this->assertStringEndsWith( '.tar.bz2', $alias );
		$this->assertLessThan( 50, strlen( basename( $alias ) ) - strpos( basename( $alias ), '.' ), 'The alias name must be one PharData accepts.' );
	}

	/**
	 * Archive-open failures retain their cause without exposing host directories.
	 */
	public function test_invalid_tar_reports_exception_without_directory() {
		$part  = $this->dir . '/invalid.tar.gz';
		$plain = $part . '.plain.tar';
		file_put_contents( $part, 'compressed-part-placeholder' );
		file_put_contents( $plain, random_bytes( 64 ) );

		$exception_class = null;
		try {
			new PharData( $plain );
		} catch ( Throwable $e ) {
			$exception_class = get_class( $e );
		}
		$this->assertNotNull( $exception_class, 'The invalid tar fixture must be rejected by PharData.' );

		$this->restorer = new InvalidTarDecompressRestorer();
		$result         = $this->run_all( $this->config( array( $part ) ) );
		$this->assertFalse( $result['done'] );
		$this->assertStringStartsWith( 'Could not open archive: ', $result['error'] );
		$this->assertStringContainsString( $exception_class, $result['error'] );
		$this->assertStringNotContainsString( $this->dir, $result['error'] );
		$this->assertSame( MainWP_Backup_Error::ERR_RESTORE_EXTRACT, $result['error_code'] );
		$this->assertFileDoesNotExist( $plain );
	}

	/**
	 * A host without the phar extension must be told so, not handed a parse error.
	 */
	public function test_missing_phar_extension_is_reported_by_name() {
		$part  = $this->dir . '/pharless.tar.gz';
		$plain = $part . '.plain.tar';
		file_put_contents( $part, 'compressed-part-placeholder' );
		file_put_contents( $plain, random_bytes( 64 ) );

		$this->restorer = new PharlessRestorer();
		$result         = $this->run_all( $this->config( array( $part ) ) );
		$this->assertFalse( $result['done'] );
		$this->assertStringContainsString( 'phar extension disabled', $result['error'] );
		$this->assertSame( MainWP_Backup_Error::ERR_RESTORE_EXTRACT, $result['error_code'] );
		$this->assertFileDoesNotExist( $plain );
	}

	/**
	 * Host paths and over-long messages must not survive into the dashboard's copy.
	 */
	public function test_archive_open_error_reduces_paths_and_keeps_text_readable() {
		$restorer = new InvalidTarDecompressRestorer();
		$part     = '/var/www/site/wp-content/backups/part001.tar.gz';
		$plain    = $part . '.plain.tar';

		$windows = $restorer->public_archive_open_error(
			new \RuntimeException( 'cannot open C:\\Users\\dennis\\sites\\demo\\wp-content\\backups\\other.tar or \\\\fileserver\\share\\other.tar' ),
			$part,
			$plain
		);
		$this->assertStringNotContainsString( 'C:\\Users', $windows );
		$this->assertStringNotContainsString( 'fileserver', $windows );
		$this->assertStringContainsString( 'other.tar', $windows );

		// A cut inside a multi-byte sequence makes esc_html() render the whole
		// message as the empty string, which is the failure this guards.
		$long = $restorer->public_archive_open_error(
			new \RuntimeException( str_repeat( 'é', 400 ) ),
			$part,
			$plain
		);
		$this->assertSame( $long, wp_check_invalid_utf8( $long ) );
		$this->assertStringContainsString( str_repeat( 'é', 300 ), $long );
	}

	/**
	 * A .tar.gz that cannot be decompressed within the slice budget is refused
	 * with an actionable message instead of restarting from byte 0 every slice,
	 * and the partial decompression is discarded.
	 */
	public function test_tar_gz_decompress_refused_when_over_budget() {
		$tar  = $this->dir . '/big.tar';
		$phar = new PharData( $tar );
		$phar->addFromString( 'a.txt', str_repeat( 'a', 4096 ) );
		$phar->compress( Phar::GZ );
		unset( $phar );
		Phar::unlinkArchive( $tar );

		$gz = $tar . '.gz';

		// A deadline already in the past forces the decompression loop to refuse
		// on its first iteration, standing in for a stream too large to finish.
		$result = $this->restorer->restore_slice( array(), $this->config( array( $gz ) ), microtime( true ) - 1 );

		$this->assertFalse( $result['done'] );
		$this->assertSame( MainWP_Backup_Error::ERR_RESTORE_EXTRACT, $result['error_code'] );
		$this->assertStringContainsString( 'uncompressed .tar', $result['error'] );
		$plain = $this->dir . '/big-' . substr( sha1( basename( $gz ) ), 0, 12 ) . '.plain.tar';
		$this->assertFileDoesNotExist( $plain );
		$this->assertSame( array(), glob( $plain . '*.tmp' ), 'The partial decompression is discarded.' );
	}

	/**
	 * When a .tar.gz decompression hits the deadline mid-stream after a prior
	 * part already spent the slice, the restore yields for a fresh slice rather
	 * than refusing - a compressed archive that would fit a full slice is never
	 * falsely rejected.
	 */
	public function test_tar_gz_decompress_timeout_yields_after_prior_work() {
		$restorer = new TimeoutDecompressRestorer();

		$plain = $this->make_tar( 'first.tar', array( 'one.txt' => 'a' ) );

		$gztar = $this->dir . '/second.tar';
		$phar  = new PharData( $gztar );
		$phar->addFromString( 'b.txt', 'b' );
		$phar->compress( Phar::GZ );
		unset( $phar );
		Phar::unlinkArchive( $gztar );
		$gz = $gztar . '.gz';

		// A full future budget: the first part extracts, then the .tar.gz forces
		// a mid-stream timeout with work already done this slice.
		$result = $restorer->restore_slice( array(), $this->config( array( $plain, $gz ) ), microtime( true ) + 3600 );

		$this->assertFalse( $result['done'] );
		$this->assertNull( $result['error'], 'A mid-stream decompress timeout after prior work must yield, not refuse.' );
	}

	/**
	 * A .tar.gz that times out on a fresh slice (a full budget was not enough)
	 * is refused with the actionable message.
	 */
	public function test_tar_gz_decompress_timeout_refuses_on_full_slice() {
		$restorer = new TimeoutDecompressRestorer();

		$gztar = $this->dir . '/only.tar';
		$phar  = new PharData( $gztar );
		$phar->addFromString( 'b.txt', 'b' );
		$phar->compress( Phar::GZ );
		unset( $phar );
		Phar::unlinkArchive( $gztar );
		$gz = $gztar . '.gz';

		$result = $restorer->restore_slice( array(), $this->config( array( $gz ) ), microtime( true ) + 3600 );

		$this->assertFalse( $result['done'] );
		$this->assertSame( MainWP_Backup_Error::ERR_RESTORE_EXTRACT, $result['error_code'] );
		$this->assertStringContainsString( 'uncompressed .tar', $result['error'] );
	}

	/**
	 * An unreadable archive is a coded failure.
	 */
	public function test_unopenable_archive_fails() {
		$bogus = $this->dir . '/not-a-zip.zip';
		file_put_contents( $bogus, 'this is not a zip file' ); // phpcs:ignore -- fixture.

		$config                              = $this->config( array( $bogus ) );
		$config['hashes']['not-a-zip.zip']   = hash_file( 'sha256', $bogus );
		$result                              = $this->restorer->restore_slice( array(), $config, microtime( true ) + 3600 );

		$this->assertNotNull( $result['error'] );
		$this->assertSame( MainWP_Backup_Error::ERR_RESTORE_EXTRACT, $result['error_code'] );
	}
}
