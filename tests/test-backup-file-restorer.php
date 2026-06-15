<?php
/**
 * Class BackupFileRestorerTest
 *
 * @package Mainwp_Child
 */

use MainWP\Child\MainWP_Child_Backup_File_Restorer;
use MainWP\Child\MainWP_Backup_Error;

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
