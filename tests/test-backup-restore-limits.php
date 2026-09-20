<?php
/**
 * @package Mainwp_Child
 */

use MainWP\Child\MainWP_Child_Backup_File_Restorer;
use MainWP\Child\MainWP_Child_Restore_Task_Runner;
use MainWP\Child\MainWP_Backup_Error;
use MainWP\Child\MainWP_Backup;

class LimitedBackupFileRestorer extends MainWP_Child_Backup_File_Restorer {

	public $limits = array();

	protected function extraction_limits() {
		return array_merge( parent::extraction_limits(), $this->limits );
	}
}

class LimitedRestoreCleanupRunner extends MainWP_Child_Restore_Task_Runner {

	protected function removal_depth_limit() {
		return 2;
	}
}

class BackupRestoreLimitsTest extends WP_UnitTestCase {

	protected $dir;
	protected $extract_to;
	protected $restorer;

	public function set_up() {
		parent::set_up();
		$this->dir        = sys_get_temp_dir() . '/mainwp-restore-limits-' . uniqid();
		$this->extract_to = $this->dir . '/site';
		mkdir( $this->extract_to, 0777, true );
		$this->restorer = new LimitedBackupFileRestorer();
	}

	public function tear_down() {
		$this->remove_fixture( $this->dir );
		parent::tear_down();
	}

	protected function remove_fixture( $path ) {
		if ( is_link( $path ) || ! is_dir( $path ) ) {
			if ( is_link( $path ) || file_exists( $path ) ) {
				unlink( $path );
			}
			return;
		}
		foreach ( scandir( $path ) as $entry ) {
			if ( '.' !== $entry && '..' !== $entry ) {
				$this->remove_fixture( $path . '/' . $entry );
			}
		}
		rmdir( $path );
	}

	protected function make_archive( $format, $entries, $name = 'fixture' ) {
		$path = $this->dir . '/' . $name . '.' . $format;
		if ( 'zip' === $format ) {
			$archive = new ZipArchive();
			$this->assertTrue( $archive->open( $path, ZipArchive::CREATE ) );
		} else {
			$archive = new PharData( $path );
		}
		foreach ( $entries as $entry => $content ) {
			$archive->addFromString( $entry, $content );
		}
		if ( 'zip' === $format ) {
			$this->assertTrue( $archive->close() );
		}
		return $path;
	}

	protected function config( $parts ) {
		$hashes = array();
		foreach ( $parts as $part ) {
			$hashes[ basename( $part ) ] = hash_file( 'sha256', $part );
		}
		return array( 'parts' => $parts, 'hashes' => $hashes, 'extract_to' => $this->extract_to );
	}

	public static function archive_formats() {
		return array( 'zip' => array( 'zip' ), 'tar' => array( 'tar' ) );
	}

	/** @dataProvider archive_formats */
	public function test_entry_count_cap_survives_slices_and_parts( $format ) {
		$this->restorer->limits = array( 'entries' => 1 );
		$one = $this->make_archive( $format, array( 'one.txt' => 'one' ), 'one' );
		$two = $this->make_archive( $format, array( 'two.txt' => 'two' ), 'two' );
		$config = $this->config( array( $one, $two ) );
		$first = $this->restorer->restore_slice( array(), $config, 0 );
		$this->assertNull( $first['error'] );
		$result = $this->restorer->restore_slice( $first['cursor'], $config, microtime( true ) + 60 );
		$this->assertSame( MainWP_Backup_Error::ERR_RESTORE_EXTRACT, $result['error_code'], 'Entry-count cap must reject the second entry.' );
		$this->assertStringContainsString( 'two.txt', $result['error'] );
		$this->assertFileDoesNotExist( $this->extract_to . '/two.txt' );
	}

	/** @dataProvider archive_formats */
	public function test_expanded_bytes_cap_counts_streamed_bytes( $format ) {
		$this->restorer->limits = array( 'expanded_bytes' => 7 );
		$part = $this->make_archive( $format, array( 'one.txt' => '1234', 'two.txt' => '5678' ) );
		$config = $this->config( array( $part ) );
		$first = $this->restorer->restore_slice( array(), $config, 0 );
		$this->assertNull( $first['error'] );
		$result = $this->restorer->restore_slice( $first['cursor'], $config, microtime( true ) + 60 );
		$this->assertSame( MainWP_Backup_Error::ERR_RESTORE_EXTRACT, $result['error_code'], 'Expanded-byte cap must reject cumulative output.' );
		$this->assertSame( 7, $result['cursor']['expanded_bytes'] );
		$this->assertFileDoesNotExist( $this->extract_to . '/two.txt' );
		$this->assertSame( array(), glob( $this->extract_to . '/.mainwp-restore-*.tmp' ) );
		$this->assertStringContainsString( 'two.txt', $result['error'] );
	}

	public function test_compression_ratio_cap() {
		$this->restorer->limits = array( 'ratio' => 2 );
		$part = $this->make_archive( 'zip', array( 'large.txt' => random_bytes( 70000 ) . str_repeat( 'a', 210000 ) ) );
		$zip = new ZipArchive();
		$zip->open( $part );
		$stat = $zip->statIndex( 0 );
		$zip->close();
		$this->assertGreaterThan( 65536, $stat['comp_size'] );
		$this->assertGreaterThan( 2 * $stat['comp_size'], $stat['size'] );
		$result = $this->restorer->restore_slice( array(), $this->config( array( $part ) ), microtime( true ) + 60 );
		$this->assertSame( MainWP_Backup_Error::ERR_RESTORE_EXTRACT, $result['error_code'], 'Compression-ratio cap must reject the entry before extraction.' );
		$this->assertFileDoesNotExist( $this->extract_to . '/large.txt' );
	}

	public static function compressed_limits() {
		return array(
			'bytes' => array( array( 'expanded_bytes' => 32 ) ),
			'ratio' => array( array( 'ratio_min_bytes' => 1, 'ratio' => 2 ) ),
		);
	}

	/** @dataProvider compressed_limits */
	public function test_compressed_tar_preparation_enforces_limits( $limits ) {
		$this->restorer->limits = $limits;
		$part = $this->dir . '/ordinary.tar.gz';
		file_put_contents( $part, gzencode( str_repeat( 'ordinary bytes ', 1000 ) ) );
		$result = $this->restorer->restore_slice( array(), $this->config( array( $part ) ), microtime( true ) + 60 );
		$this->assertSame( MainWP_Backup_Error::ERR_RESTORE_EXTRACT, $result['error_code'] );
		$this->assertStringContainsString( 'Could not decompress archive: ordinary.tar.gz', $result['error'] );
		$this->assertSame( array(), glob( $this->dir . '/*.tar' ) );
		$this->assertSame( array(), glob( $this->dir . '/*.tmp' ) );
	}

	public function test_ratio_threshold_is_inclusive_for_metadata_and_stream() {
		$this->restorer->limits = array( 'ratio_min_bytes' => 65536, 'ratio' => 1 );
		$cursor = array( 'entries' => 0, 'expanded_bytes' => 0 );
		$check = new ReflectionMethod( MainWP_Child_Backup_File_Restorer::class, 'check_entry_limits' );
		$check->setAccessible( true );
		try {
			$check->invokeArgs( $this->restorer, array( 65537, 65536, &$cursor, 'ordinary.txt' ) );
			$this->fail( 'The ratio threshold must include equality.' );
		} catch ( \MainWP\Child\MainWP_Exception $e ) {
			$this->assertStringContainsString( 'ordinary.txt', $e->getMessage() );
			$this->assertSame( MainWP_Backup_Error::ERR_RESTORE_EXTRACT, $e->get_message_error_code() );
		}
		$stream = fopen( 'php://temp', 'w+b' );
		$out = fopen( 'php://temp', 'w+b' );
		fwrite( $stream, str_repeat( 'a', 65537 ) );
		rewind( $stream );
		$copy = new ReflectionMethod( MainWP_Child_Backup_File_Restorer::class, 'copy_bounded_entry' );
		$copy->setAccessible( true );
		try {
			$copy->invokeArgs( $this->restorer, array( $stream, $out, &$cursor, 65536, 'ordinary.txt' ) );
			$this->fail( 'Streamed bytes must obey the inclusive ratio threshold.' );
		} catch ( \MainWP\Child\MainWP_Exception $e ) {
			$this->assertStringContainsString( 'ordinary.txt', $e->getMessage() );
			$this->assertSame( 65536, $cursor['expanded_bytes'] );
		} finally {
			fclose( $stream );
			fclose( $out );
		}
	}

	public function test_rrmdir_depth_cap() {
		$root = $this->dir . '/deep';
		mkdir( $root . '/a/b/c', 0777, true );
		file_put_contents( $root . '/a/b/c/keep.txt', 'keep' );
		foreach ( array( '', '/a', '/a/b' ) as $level ) {
			file_put_contents( $root . $level . '/remove.txt', 'remove' );
			mkdir( $root . $level . '/empty' );
		}
		MainWP_Backup::reset_skipped_paths();
		$this->invoke_rrmdir( $root );
		foreach ( array( '', '/a', '/a/b' ) as $level ) {
			$this->assertFileDoesNotExist( $root . $level . '/remove.txt' );
		}
		$this->assertDirectoryDoesNotExist( $root . '/empty' );
		$this->assertDirectoryDoesNotExist( $root . '/a/empty' );
		$this->assertDirectoryExists( $root . '/a/b/empty' );
		$this->assertSame( 'keep', file_get_contents( $root . '/a/b/c/keep.txt' ) );
		$this->assertContains( $root . '/a/b/c', MainWP_Backup::get_skipped_paths() );
		MainWP_Backup::reset_skipped_paths();
	}

	public function test_rrmdir_unlinks_symlinks_without_following_them() {
		$outside = $this->dir . '/outside';
		mkdir( $outside );
		file_put_contents( $outside . '/keep.txt', 'keep' );
		$root = $this->dir . '/cleanup';
		mkdir( $root );
		symlink( $outside, $root . '/nested-link' );
		$this->invoke_rrmdir( $root );
		$this->assertFileExists( $outside . '/keep.txt' );
		$this->assertDirectoryDoesNotExist( $root );
		symlink( $outside, $root );
		$this->invoke_rrmdir( $root );
		$this->assertFileExists( $outside . '/keep.txt', 'Cleanup must not descend through a root symlink.' );
		$this->assertFalse( is_link( $root ) );
	}

	protected function invoke_rrmdir( $path ) {
		$method = new ReflectionMethod( MainWP_Child_Restore_Task_Runner::class, 'rrmdir' );
		$method->setAccessible( true );
		$method->invoke( new LimitedRestoreCleanupRunner(), $path );
	}

	/** @dataProvider archive_formats */
	public function test_normal_small_archive_extracts( $format ) {
		$this->restorer->limits = array( 'entries' => 1, 'expanded_bytes' => 1024, 'ratio' => 2 );
		$content = str_repeat( 'a', 1024 );
		$part = $this->make_archive( $format, array( 'small.txt' => $content ) );
		$result = $this->restorer->restore_slice( array(), $this->config( array( $part ) ), microtime( true ) + 60 );
		$this->assertTrue( $result['done'] );
		$this->assertNull( $result['error'] );
		$this->assertSame( $content, file_get_contents( $this->extract_to . '/small.txt' ) );
	}
}
