<?php
/**
 * Class BackupFileRestorerTest
 *
 * @package Mainwp_Child
 */

use MainWP\Child\MainWP_Child_Backup_File_Restorer;
use MainWP\Child\MainWP_Backup_Error;
use MainWP\Child\MainWP_Child_Backup_Task_Runner;
use MainWP\Child\MainWP_Child_Backup_Task;
use MainWP\Child\MainWP_Child_Restore_Task_Runner;

/** Filesystem fault injection for the extraction matrix, backed by real files. */
class ExtractionMatrixStream {
	public $context;
	public static $directory;
	public static $failure;
	public static $vanished;
	public static $calls = array();
	private $stream;
	private $path;

	private static function local( $path ) {
		return self::$directory . '/' . substr( $path, strlen( 'extractmatrix://root/' ) );
	}

	public function url_stat( $path, $flags ) {
		return @lstat( rtrim( self::local( $path ), '/' ) );
	}

	public function stream_open( $path, $mode, $options, &$opened_path ) {
		$this->path = self::local( $path );
		$this->stream = fopen( $this->path, $mode );
		return false !== $this->stream;
	}

	public function stream_write( $data ) {
		return fwrite( $this->stream, $data );
	}

	public function stream_close() {
		fclose( $this->stream );
		if ( self::$vanished ) {
			unlink( $this->path );
		}
	}

	public function stream_metadata( $path, $option, $value ) {
		if ( STREAM_META_ACCESS !== $option ) {
			return false;
		}
		self::$calls[] = 'chmod';
		return 'chmod' !== self::$failure && @chmod( self::local( $path ), $value );
	}

	public function rename( $from, $to ) {
		self::$calls[] = 'rename';
		return 'rename' !== self::$failure && @rename( self::local( $from ), self::local( $to ) );
	}

	public function unlink( $path ) {
		return @unlink( self::local( $path ) );
	}
}

/**
 * Restorer that forces every compressed tar decompression to hit the slice deadline,
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
		if ( preg_match( '/\.tar\.(gz|bz2)$/i', $part ) ) {
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
 * Observe temporary names and reproduce an unreadable peer mtime.
 */
class ObservedTempRestorer extends CopyFallbackRestorer {

	public $temp_paths = array();

	protected function copy_part( $part, $tmp ) {
		$this->temp_paths[] = $tmp;
		return parent::copy_part( $part, $tmp );
	}

	protected function close_out( $out ) {
		$this->temp_paths[] = stream_get_meta_data( $out )['uri'];
		return parent::close_out( $out );
	}

	protected function plain_temp_mtime( $path ) {
		return '.unknown.tmp' === substr( $path, -12 ) ? false : parent::plain_temp_mtime( $path );
	}
}

/**
 * Report a deferred close failure after all decompressed bytes reached disk.
 */
class FailedCloseRestorer extends MainWP_Child_Backup_File_Restorer {

	public $closed_size;

	protected function close_out( $out ) {
		$path = stream_get_meta_data( $out )['uri'];
		parent::close_out( $out );
		clearstatcache( true, $path );
		$this->closed_size = filesize( $path );
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

	protected function run_sliced( $config ) {
		$result = array( 'cursor' => array() );
		$slices = 0;
		do {
			$this->restorer = new MainWP_Child_Backup_File_Restorer();
			$result = $this->restorer->restore_slice( $result['cursor'], $config, 0 );
			++$slices;
		} while ( ! $result['done'] && null === $result['error'] && $slices < 100 );
		$result['slices'] = $slices;
		return $result;
	}

	/** @dataProvider data_read_only_target_formats */
	public function test_restore_skips_clone_config_but_keeps_site_content( $format ) {
		$path = $this->dir . '/clone.' . $format;
		if ( 'tar' === $format ) {
			$archive = new PharData( $path );
			$archive->addEmptyDir( 'clone' );
			$archive->addFromString( 'clone/config.txt', 'legacy clone configuration' );
			$archive->addFromString( 'clone/keep.txt', 'site content' );
		} else {
			$archive = new ZipArchive();
			$this->assertTrue( $archive->open( $path, ZipArchive::CREATE ) );
			$archive->addEmptyDir( 'clone' );
			$archive->addFromString( 'clone/config.txt', 'legacy clone configuration' );
			$archive->addFromString( 'clone/keep.txt', 'site content' );
			$archive->close();
		}
		unset( $archive );
		$result = $this->run_sliced( $this->config( array( $path ) ) );
		$this->assertNull( $result['error'] );
		$this->assertTrue( $result['done'] );
		$this->assertFileDoesNotExist( $this->extract_to . '/clone/config.txt' );
		$this->assertSame( 'site content', file_get_contents( $this->extract_to . '/clone/keep.txt' ) );
	}

	public function test_restore_skips_clone_config_under_a_dotted_entry_name() {
		$path    = $this->dir . '/clone-dotted.zip';
		$archive = new ZipArchive();
		$this->assertTrue( $archive->open( $path, ZipArchive::CREATE ) );
		$archive->addFromString( './clone/config.txt', 'legacy clone configuration' );
		$archive->addFromString( 'clone//config.txt', 'legacy clone configuration' );
		$archive->addFromString( 'keep.txt', 'site content' );
		$archive->close();
		$result = $this->run_sliced( $this->config( array( $path ) ) );
		$this->assertNull( $result['error'] );
		$this->assertTrue( $result['done'] );
		$this->assertFileDoesNotExist( $this->extract_to . '/clone/config.txt' );
		$this->assertSame( 'site content', file_get_contents( $this->extract_to . '/keep.txt' ) );
	}

	/** @dataProvider data_read_only_target_formats */
	public function test_restore_skips_clone_directory_without_creating_it( $format ) {
		$path = $this->dir . '/clone-dir.' . $format;
		if ( 'tar' === $format ) {
			$archive = new PharData( $path );
			$archive->addEmptyDir( 'clone' );
			$archive->addFromString( 'clone/config.txt', 'legacy clone configuration' );
		} else {
			$archive = new ZipArchive();
			$this->assertTrue( $archive->open( $path, ZipArchive::CREATE ) );
			$archive->addEmptyDir( 'clone' );
			$archive->addFromString( 'clone/config.txt', 'legacy clone configuration' );
			$archive->close();
		}
		unset( $archive );
		$result = $this->run_all( $this->config( array( $path ) ) );
		$this->assertNull( $result['error'] );
		$this->assertTrue( $result['done'] );
		$this->assertDirectoryDoesNotExist( $this->extract_to . '/clone' );
	}

	public static function data_unwritable_parent_entries() {
		return array(
			'zip file' => array( 'zip', false ),
			'tar file' => array( 'tar', false ),
			'zip directory' => array( 'zip', true ),
			'tar directory' => array( 'tar', true ),
		);
	}

	/** @dataProvider data_unwritable_parent_entries */
	public function test_restore_forwards_target_not_writable_code( $format, $directory ) {
		if ( function_exists( 'posix_geteuid' ) && 0 === posix_geteuid() ) {
			$this->markTestSkipped( 'Root ignores directory permissions.' );
		}
		$path = $this->dir . '/unwritable.' . $format;
		if ( $directory ) {
			$archive = 'tar' === $format ? new PharData( $path ) : new ZipArchive();
			if ( 'zip' === $format ) {
				$this->assertTrue( $archive->open( $path, ZipArchive::CREATE ) );
			}
			$archive->addEmptyDir( 'locked/missing' );
			if ( 'zip' === $format ) {
				$archive->close();
			}
			unset( $archive );
		} else {
			$entries = array( 'locked/missing/file.txt' => 'unwritable content' );
			$path = 'tar' === $format ? $this->make_tar( 'unwritable.tar', $entries ) : $this->make_zip( 'unwritable.zip', $entries );
		}
		$parent = $this->extract_to . '/locked';
		mkdir( $parent );
		$this->assertTrue( chmod( $parent, 0500 ) );
		clearstatcache( true, $parent );
		try {
			$this->assertFalse( is_writable( $parent ), 'The fixture must refuse writes.' );
			$result = $this->run_all( $this->config( array( $path ) ) );
			$this->assertFalse( $result['done'] );
			$this->assertSame( MainWP_Backup_Error::ERR_RESTORE_TARGET_NOT_WRITABLE, $result['error_code'] );
			$this->assertSame( esc_html( MainWP_Backup_Error::message( MainWP_Backup_Error::ERR_RESTORE_TARGET_NOT_WRITABLE ) ), $result['error'] );
			$this->assertDirectoryDoesNotExist( $parent . '/missing' );
		} finally {
			chmod( $parent, 0755 );
			clearstatcache( true, $parent );
		}
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
	 * @dataProvider data_read_only_target_formats
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_archive_skips_active_child_plugin_directory( $format ) {
		$plugin = 'wp-content/plugins/renamed-child';
		define( 'MainWP\\Child\\MAINWP_CHILD_PLUGIN_DIR', ABSPATH . $plugin . '/' );
		mkdir( $this->extract_to . '/' . $plugin, 0777, true );
		file_put_contents( $this->extract_to . '/' . $plugin . '/live.php', 'current child' );
		$entries = array(
			$plugin . '/live.php' => 'old child',
			$plugin . '/new.php' => 'old extra file',
			$plugin . '/new..php' => 'old dotted file',
			$plugin . '-extra/keep.php' => 'other plugin',
		);
		if ( 'zip' === $format ) {
			$entries[ './' . $plugin . '//live.php' ] = 'old child via alternate spelling';
		}
		$part = 'zip' === $format
			? $this->make_zip( 'child.zip', $entries )
			: $this->make_tar( 'child.tar', $entries );
		$result = $this->run_sliced( $this->config( array( $part ) ) );
		$this->assertNull( $result['error'] );
		$this->assertTrue( $result['done'] );
		$this->assertSame( 'current child', file_get_contents( $this->extract_to . '/' . $plugin . '/live.php' ) );
		$this->assertFileDoesNotExist( $this->extract_to . '/' . $plugin . '/new.php' );
		$this->assertFileDoesNotExist( $this->extract_to . '/' . $plugin . '/new..php' );
		$this->assertSame( 'other plugin', file_get_contents( $this->extract_to . '/' . $plugin . '-extra/keep.php' ) );
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_tombstones_preserve_active_child_plugin_directory() {
		$plugin = 'wp-content/plugins/renamed-child';
		define( 'MainWP\\Child\\MAINWP_CHILD_PLUGIN_DIR', ABSPATH . $plugin . '/' );
		foreach ( array( $plugin, $plugin . '-extra' ) as $directory ) {
			mkdir( $this->extract_to . '/' . $directory, 0777, true );
			file_put_contents( $this->extract_to . '/' . $directory . '/live.php', 'current child' );
		}
		file_put_contents( $this->extract_to . '/' . $plugin . '/live..php', 'current dotted child' );
		$tombstones = $this->dir . '/tombstones.list';
		file_put_contents( $tombstones, $plugin . "/live..php\n" . $plugin . "/live.php\n./" . $plugin . "//live.php\n" . $plugin . "-extra/live.php\n" );
		$result = $this->restorer->apply_tombstones( $tombstones, $this->extract_to );
		$this->assertNull( $result['error'] );
		$this->assertSame( 1, $result['deleted'] );
		$this->assertSame( 'current child', file_get_contents( $this->extract_to . '/' . $plugin . '/live.php' ) );
		$this->assertSame( 'current dotted child', file_get_contents( $this->extract_to . '/' . $plugin . '/live..php' ) );
		$this->assertFileDoesNotExist( $this->extract_to . '/' . $plugin . '-extra/live.php' );
	}

	/** @dataProvider data_read_only_target_formats */
	public function test_legacy_htaccess_aliases_restore_to_real_names( $format ) {
		$entries = array(
			'mainwp-htaccess' => 'root rules',
			'wp-content/mainwp-htaccess' => 'directory rules',
			'wp-content/deep/mainwp-htaccess' => 'nested rules',
			'wp-content/mainwp-htaccess.txt' => 'ordinary file',
		);
		$part = 'zip' === $format
			? $this->make_zip( 'aliases.zip', $entries )
			: $this->make_tar( 'aliases.tar', $entries );
		$result = $this->run_sliced( $this->config( array( $part ) ) );
		$this->assertNull( $result['error'] );
		$this->assertTrue( $result['done'] );
		foreach ( array( '' => 'root rules', 'wp-content/' => 'directory rules', 'wp-content/deep/' => 'nested rules' ) as $directory => $content ) {
			$this->assertSame( $content, file_get_contents( $this->extract_to . '/' . $directory . '.htaccess' ) );
			$this->assertFileDoesNotExist( $this->extract_to . '/' . $directory . 'mainwp-htaccess' );
		}
		$this->assertSame( 'ordinary file', file_get_contents( $this->extract_to . '/wp-content/mainwp-htaccess.txt' ) );
	}

	/** @dataProvider data_read_only_target_formats */
	public function test_real_htaccess_entries_take_precedence_over_aliases( $format ) {
		$entries = array(
			'.htaccess' => 'current root rules',
			'mainwp-htaccess' => 'stale root rules',
			'wp-content/.htaccess' => 'current directory rules',
			'wp-content/mainwp-htaccess' => 'stale directory rules',
			'wp-content/deep/.htaccess' => 'current nested rules',
			'wp-content/deep/mainwp-htaccess' => 'stale nested rules',
			'legacy/mainwp-htaccess' => 'legacy directory rules',
		);
		foreach ( array( 'real-first' => $entries, 'alias-first' => array_reverse( $entries, true ) ) as $order => $ordered_entries ) {
			$destination = $this->extract_to . '/' . $order;
			mkdir( $destination );
			$part = 'zip' === $format
				? $this->make_zip( $order . '.zip', $ordered_entries )
				: $this->make_tar( $order . '.tar', $ordered_entries );
			$result = $this->run_sliced( $this->config( array( $part ), array( 'extract_to' => $destination ) ) );
			$this->assertNull( $result['error'], $order );
			$this->assertTrue( $result['done'], $order );
			foreach ( $entries as $name => $content ) {
				if ( 'mainwp-htaccess' === basename( $name ) && 'legacy/mainwp-htaccess' !== $name ) {
					$this->assertFileDoesNotExist( $destination . '/' . $name, $order );
					continue;
				}
				$target = 'legacy/mainwp-htaccess' === $name ? 'legacy/.htaccess' : $name;
				$this->assertFileExists( $destination . '/' . $target, $order );
				$this->assertSame( $content, file_get_contents( $destination . '/' . $target ), $order . ': ' . $target );
			}
			$this->assertFileDoesNotExist( $destination . '/legacy/mainwp-htaccess' );
		}
	}

	/** @dataProvider data_read_only_target_formats */
	public function test_htaccess_presence_cache_is_scoped_to_archive_part( $format ) {
		$parts = array();
		foreach ( array( array( '.htaccess' => 'first part rules', 'mainwp-htaccess' => 'stale first part rules' ), array( 'mainwp-htaccess' => 'later part rules' ) ) as $index => $entries ) {
			$parts[] = 'zip' === $format
				? $this->make_zip( 'part-' . $index . '.zip', $entries )
				: $this->make_tar( 'part-' . $index . '.tar', $entries );
		}
		$result = $this->run_all( $this->config( $parts ) );
		$this->assertNull( $result['error'] );
		$this->assertTrue( $result['done'] );
		$this->assertSame( 'later part rules', file_get_contents( $this->extract_to . '/.htaccess' ) );
		$this->assertFileDoesNotExist( $this->extract_to . '/mainwp-htaccess' );
	}

	/** @dataProvider data_read_only_target_formats */
	public function test_mu_plugins_preservation_uses_destination_names( $format ) {
		$directory = 'wp-content/mu-plugins';
		$path = $directory . '/.htaccess';
		mkdir( $this->extract_to . '/' . $directory, 0777, true );
		file_put_contents( $this->extract_to . '/' . $path, 'live rules' );
		$entries = array( $directory . '/mainwp-htaccess' => 'archived rules', $directory . '/snapshot.php' => 'snapshot bytes' );
		$part = 'zip' === $format ? $this->make_zip( 'mu.zip', $entries ) : $this->make_tar( 'mu.tar', $entries );
		$config = array(
			'parts' => array( $part ),
			'hashes' => array( basename( $part ) => hash_file( 'sha256', $part ) ),
			'extract_to' => $this->extract_to,
			'mu_plugins_preserve' => array( 'directory' => $directory, 'mode' => 'set', 'paths' => array( $path => true ) ),
		);
		$out = $this->restorer->restore_slice( array(), $config, time() + 60 );
		$this->assertNull( $out['error'] );
		$this->assertTrue( $out['done'] );
		$this->assertSame( 'live rules', file_get_contents( $this->extract_to . '/' . $path ) );
		$this->assertSame( 'snapshot bytes', file_get_contents( $this->extract_to . '/' . $directory . '/snapshot.php' ) );
	}

	public function test_htaccess_alias_remapping_rejects_traversal() {
		$entries = array( '../mainwp-htaccess' => 'unsafe rules' );
		$part = $this->make_zip( 'traversal.zip', $entries );
		$result = $this->run_all( $this->config( array( $part ) ) );
		$this->assertSame( MainWP_Backup_Error::ERR_RESTORE_EXTRACT, $result['error_code'] );
		$this->assertFileDoesNotExist( $this->dir . '/.htaccess' );
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
	 * Host-managed file links must retain their location-dependent PHP includes.
	 */
	public function test_extraction_does_not_follow_leaf_symlink() {
		$outside = $this->dir . '/outside-secret.txt';
		file_put_contents( $outside, 'SECRET' );

		mkdir( $this->extract_to . '/wp-content', 0777, true );
		if ( ! function_exists( 'symlink' ) || ! @symlink( $outside, $this->extract_to . '/wp-content/evil.txt' ) ) {
			$this->markTestSkipped( 'Filesystem does not support symlinks.' );
		}

		$zip    = $this->make_zip( 'part001.zip', array( 'wp-content/evil.txt' => 'ARCHIVE' ) );
		$result = $this->run_all( $this->config( array( $zip ) ) );

		$this->assertNull( $result['error'] );
		$this->assertSame( 'SECRET', file_get_contents( $outside ), 'The symlink target outside the root must be untouched.' );
		$this->assertTrue( is_link( $this->extract_to . '/wp-content/evil.txt' ) );
		$this->assertSame( 'SECRET', file_get_contents( $this->extract_to . '/wp-content/evil.txt' ) );
		$this->assertSame( 1, $result['cursor']['kept_symlinks'] ?? null );
		$this->assertSame( array( 'wp-content/evil.txt' ), $result['cursor']['kept_symlink_paths'] ?? null );
		$this->assertSame( 7, $result['cursor']['expanded_bytes'] );
		$this->assertSame( array(), glob( $this->extract_to . '/wp-content/.mainwp-restore-*.tmp' ) );
	}

	/**
	 * The same leaf-symlink guard holds for tar extraction.
	 */
	public function test_tar_extraction_does_not_follow_leaf_symlink() {
		$outside = $this->dir . '/outside-secret-tar.txt';
		file_put_contents( $outside, 'SECRET' );

		mkdir( $this->extract_to . '/wp-content', 0777, true );
		if ( ! function_exists( 'symlink' ) || ! @symlink( $outside, $this->extract_to . '/wp-content/evil.txt' ) ) {
			$this->markTestSkipped( 'Filesystem does not support symlinks.' );
		}

		$tar = $this->make_tar( 'part001.tar', array( 'wp-content/evil.txt' => 'ARCHIVE' ) );
		$result = $this->run_all( $this->config( array( $tar ) ) );

		$this->assertNull( $result['error'] );
		$this->assertSame( 'SECRET', file_get_contents( $outside ), 'The symlink target outside the root must be untouched.' );
		$this->assertTrue( is_link( $this->extract_to . '/wp-content/evil.txt' ) );
		$this->assertSame( 'SECRET', file_get_contents( $this->extract_to . '/wp-content/evil.txt' ) );
		$this->assertSame( 1, $result['cursor']['kept_symlinks'] ?? null );
		$this->assertSame( array( 'wp-content/evil.txt' ), $result['cursor']['kept_symlink_paths'] ?? null );
		$this->assertSame( 7, $result['cursor']['expanded_bytes'] );
		$this->assertSame( array(), glob( $this->extract_to . '/wp-content/.mainwp-restore-*.tmp' ) );
	}

	/**
	 * Archive formats that write extraction targets.
	 *
	 * @return array Format rows.
	 */
	public function data_read_only_target_formats() {
		return array(
			'zip' => array( 'zip' ),
			'tar' => array( 'tar' ),
		);
	}

	/**
	 * A read-only file already at the target (a git object, a hardened
	 * wp-config.php) is replaced instead of failing the whole restore.
	 *
	 * @dataProvider data_read_only_target_formats
	 *
	 * @param string $format Archive format to build.
	 */
	public function test_extraction_replaces_read_only_target( $format ) {
		mkdir( $this->extract_to . '/wp-content', 0777, true );
		$target = $this->extract_to . '/wp-content/locked.txt';
		file_put_contents( $target, 'OLD' ); // phpcs:ignore -- fixture.
		$this->assertTrue( chmod( $target, 0444 ) );
		clearstatcache( true, $target );
		if ( is_writable( $target ) ) {
			$this->markTestSkipped( 'Filesystem does not enforce read-only files.' );
		}

		$part = 'zip' === $format
			? $this->make_zip( 'part001.zip', array( 'wp-content/locked.txt' => 'ARCHIVE' ) )
			: $this->make_tar( 'part001.tar', array( 'wp-content/locked.txt' => 'ARCHIVE' ) );

		$result = $this->run_all( $this->config( array( $part ) ) );

		$this->assertNull( $result['error'] );
		$this->assertTrue( $result['done'] );
		$this->assertSame( 'ARCHIVE', file_get_contents( $target ) );
		$this->assertFalse( is_link( $target ) );
		$this->assertTrue( is_file( $target ) );
		clearstatcache( true, $target );
		$this->assertSame( 0444, fileperms( $target ) & 0777 );
	}

	/** @dataProvider data_read_only_target_formats */
	public function test_mid_entry_failure_preserves_live_target( $format ) {
		$this->restorer = new class() extends MainWP_Child_Backup_File_Restorer {
			protected function extraction_limits() {
				return array_merge( parent::extraction_limits(), array( 'expanded_bytes' => 3 ) );
			}
		};
		$target = $this->extract_to . '/existing.txt';
		file_put_contents( $target, 'original bytes' );
		$part = 'zip' === $format
			? $this->make_zip( 'part001.zip', array( 'existing.txt' => 'replacement' ) )
			: $this->make_tar( 'part001.tar', array( 'existing.txt' => 'replacement' ) );
		$result = $this->run_all( $this->config( array( $part ) ) );
		$this->assertSame( MainWP_Backup_Error::ERR_RESTORE_EXTRACT, $result['error_code'] );
		$this->assertSame( 3, $result['cursor']['expanded_bytes'] );
		$this->assertStringContainsString( 'existing.txt', $result['error'] );
		$this->assertSame( 'original bytes', file_get_contents( $target ) );
		$this->assertSame( array(), glob( $this->extract_to . '/.mainwp-restore-*.tmp' ) );
		$this->assertArrayNotHasKey( 'pending_temp', $result['cursor'] );
		$config = $this->config( array( $part ), array( 'temp_owner' => 'abc123' ) );
		for ( $attempt = 0; $attempt < 2; ++$attempt ) {
			$temp = $this->extract_to . '/.mainwp-restore-abc123-' . bin2hex( random_bytes( 8 ) ) . '.tmp';
			file_put_contents( $temp, 'interrupted temporary output' );
			$this->restorer = new class() extends MainWP_Child_Backup_File_Restorer {
				protected function extraction_limits() {
					return array_merge( parent::extraction_limits(), array( 'expanded_bytes' => 3 ) );
				}
			};
			$result = $this->restorer->restore_slice( array(), $config, microtime( true ) + 60 );
			$this->assertSame( MainWP_Backup_Error::ERR_RESTORE_EXTRACT, $result['error_code'] );
			$this->assertSame( 'original bytes', file_get_contents( $target ) );
			$this->assertSame( array(), glob( $this->extract_to . '/.mainwp-restore-*.tmp' ) );
		}
		file_put_contents( $temp, 'interrupted temporary output' );
		$this->restorer = new MainWP_Child_Backup_File_Restorer();
		$result = $this->restorer->restore_slice( array( 'pending_temp' => $temp ), $config, microtime( true ) + 60 );
		$this->assertNull( $result['error'] );
		$this->assertTrue( $result['done'] );
		$this->assertSame( 'replacement', file_get_contents( $target ) );
		$this->assertSame( array(), glob( $this->extract_to . '/.mainwp-restore-*.tmp' ) );
	}

	/** @dataProvider data_read_only_target_formats */
	public function test_archive_preserves_temp_named_member( $format ) {
		$name = 'dir/.mainwp-restore-' . md5( 'dir/file' ) . '.tmp';
		$entries = array( $name => 'real archived dotfile', 'dir/file' => 'ordinary file' );
		$part = 'zip' === $format
			? $this->make_zip( 'names.zip', $entries )
			: $this->make_tar( 'names.tar', $entries );
		$result = $this->run_all( $this->config( array( $part ) ) );
		$this->assertNull( $result['error'] );
		$this->assertTrue( $result['done'] );
		foreach ( $entries as $entry => $content ) {
			$this->assertSame( $content, file_get_contents( $this->extract_to . '/' . $entry ), $entry );
		}
	}

	/** @dataProvider data_read_only_target_formats */
	public function test_archive_temp_named_member_survives_new_restorer_each_slice( $format ) {
		$name = 'dir/.mainwp-restore-' . md5( 'archived member' ) . '.tmp';
		$entries = array( $name => 'member', 'dir/one.txt' => 'one', 'dir/two.txt' => 'two' );
		$part = 'zip' === $format
			? $this->make_zip( 'sliced.zip', $entries )
			: $this->make_tar( 'sliced.tar', $entries );
		$result = $this->run_sliced( $this->config( array( $part ), array( 'temp_owner' => 'abc123' ) ) );
		$this->assertNull( $result['error'] );
		$this->assertTrue( $result['done'] );
		$this->assertGreaterThanOrEqual( 3, $result['slices'], 'At least two boundaries must separate fresh restorer instances.' );
		$this->assertFileExists( $this->extract_to . '/' . $name );
		foreach ( $entries as $entry => $content ) {
			$this->assertSame( $content, file_get_contents( $this->extract_to . '/' . $entry ), $entry );
		}
	}

	public static function reserved_temp_names() {
		return array(
			'legacy' => array( '.mainwp-restore-0123abcdef.tmp', true ),
			'owned' => array( '.mainwp-restore-abc123-0123456789abcdef.tmp', true ),
			'uppercase legacy' => array( '.mainwp-restore-ABCDEF.tmp', false ),
			'uppercase owner' => array( '.mainwp-restore-ABCDEF-0123456789abcdef.tmp', false ),
			'uppercase suffix token' => array( '.mainwp-restore-abc123-ABCDEF.tmp', false ),
			'missing suffix' => array( '.mainwp-restore-abc123-0123456789abcdef', false ),
			'extra segment' => array( '.mainwp-restore-abc123-0123456789abcdef-dead.tmp', false ),
			'plain tmp' => array( '.tmp', false ),
			'trailing newline' => array( ".mainwp-restore-abc123.tmp\n", false ),
		);
	}

	/** @dataProvider reserved_temp_names */
	public function test_reserved_temp_name_matches_only_supported_forms( $name, $reserved ) {
		$this->assertSame( $reserved, MainWP_Child_Backup_File_Restorer::is_reserved_temp_name( $name ) );
	}

	public function test_unconfigured_owner_is_random_and_stable_per_restorer() {
		$part = $this->make_zip( 'owners.zip', array( 'one.txt' => 'one', 'two.txt' => 'two' ) );
		$owners = array();
		for ( $i = 0; $i < 2; ++$i ) {
			$this->restorer = new class() extends MainWP_Child_Backup_File_Restorer {
				public $names = array();
				protected function entry_temp_path( $target ) {
					$path = parent::entry_temp_path( $target );
					$this->names[] = basename( $path );
					return $path;
				}
			};
			$result = $this->run_all( $this->config( array( $part ) ) );
			$this->assertNull( $result['error'] );
			$this->assertTrue( $result['done'] );
			$this->assertCount( 2, $this->restorer->names );
			$tokens = array();
			foreach ( $this->restorer->names as $name ) {
				$this->assertSame( 1, preg_match( '/^\.mainwp-restore-([0-9a-f]+)-[0-9a-f]{16}\.tmp$/', $name, $matches ) );
				$tokens[] = $matches[1];
			}
			$this->assertSame( $tokens[0], $tokens[1] );
			$owners[] = $tokens[0];
		}
		$this->assertNotSame( $owners[0], $owners[1] );
	}

	public static function temp_collisions() {
		return array( 'file' => array( false ), 'symlink' => array( true ) );
	}

	/** @dataProvider temp_collisions */
	public function test_temp_collision_retries_without_truncation( $link ) {
		$peer = $this->extract_to . '/.mainwp-restore-0123456789abcdef.tmp';
		$original = $this->dir . '/original.txt';
		file_put_contents( $original, 'original bytes' );
		$this->restorer = new class( $peer, $original, $link ) extends MainWP_Child_Backup_File_Restorer {
			public $attempts = 0;
			private $peer;
			private $original;
			private $link;
			public function __construct( $peer, $original, $link ) {
				$this->peer = $peer;
				$this->original = $original;
				$this->link = $link;
			}
			protected function entry_temp_path( $target ) {
				if ( 1 === ++$this->attempts ) {
					// A peer creates this path after the directory sweep.
					if ( $this->link ) {
						symlink( $this->original, $this->peer );
					} else {
						copy( $this->original, $this->peer );
					}
					return $this->peer;
				}
				return parent::entry_temp_path( $target );
			}
		};
		$part = $this->make_zip( 'collision.zip', array( 'file.txt' => 'restored bytes' ) );
		$result = $this->run_all( $this->config( array( $part ) ) );
		$this->assertNull( $result['error'] );
		$this->assertTrue( $result['done'] );
		$this->assertSame( 2, $this->restorer->attempts );
		$this->assertSame( 'original bytes', file_get_contents( $peer ) );
		$this->assertSame( 'original bytes', file_get_contents( $original ) );
		$this->assertSame( $link, is_link( $peer ) );
		$this->assertSame( 'restored bytes', file_get_contents( $this->extract_to . '/file.txt' ) );
		if ( $link ) {
			$method = new ReflectionMethod( MainWP_Child_Backup_File_Restorer::class, 'create_entry_temp' );
			$source = implode( '', array_slice( file( $method->getFileName() ), $method->getStartLine() - 1, $method->getEndLine() - $method->getStartLine() + 1 ) );
			$this->assertStringContainsString( "is_link( \$temp ) ? false : @fopen( \$temp, 'xb' )", $source, 'Keep the explicit symlink refusal as well as exclusive creation.' );
		}
	}

	public function test_temp_collisions_stop_after_three_attempts() {
		$peer = $this->extract_to . '/.mainwp-restore-abcdef.tmp';
		$this->restorer = new class( $peer ) extends MainWP_Child_Backup_File_Restorer {
			public $attempts = 0;
			private $peer;
			public function __construct( $peer ) {
				$this->peer = $peer;
			}
			protected function entry_temp_path( $target ) {
				if ( 1 === ++$this->attempts ) {
					file_put_contents( $this->peer, 'peer bytes' );
				}
				return $this->peer;
			}
		};
		file_put_contents( $this->extract_to . '/file.txt', 'live bytes' );
		$part = $this->make_zip( 'collision.zip', array( 'file.txt' => 'restored bytes' ) );
		$result = $this->run_all( $this->config( array( $part ) ) );
		$this->assertSame( MainWP_Backup_Error::ERR_RESTORE_EXTRACT, $result['error_code'] );
		$this->assertStringStartsWith( 'Could not create a temporary file in the site root: ', $result['error'] );
		$this->assertStringNotContainsString( 'three generated names already existed; another restore may be running on this site.', $result['error'] );
		$this->assertSame( 3, $this->restorer->attempts );
		$this->assertSame( 'peer bytes', file_get_contents( $peer ) );
		$this->assertSame( 'live bytes', file_get_contents( $this->extract_to . '/file.txt' ) );
	}

	public static function sweep_candidates() {
		return array(
			'own closed file' => array( '.mainwp-restore-abc123-0123456789abcdef.tmp', 'file', true ),
			'own open file' => array( '.mainwp-restore-abc123-0123456789abcdef.tmp', 'open', true ),
			'foreign file' => array( '.mainwp-restore-def456-0123456789abcdef.tmp', 'file', false ),
			'owner prefix only' => array( '.mainwp-restore-abc1230-0123456789abcdef.tmp', 'file', false ),
			'legacy file' => array( '.mainwp-restore-0123456789abcdef.tmp', 'file', false ),
			'own symlink' => array( '.mainwp-restore-abc123-0123456789abcdef.tmp', 'link', false ),
			'own directory' => array( '.mainwp-restore-abc123-0123456789abcdef.tmp', 'directory', false ),
			'unrelated dotfile' => array( '.ordinary', 'file', false ),
			'malformed name' => array( '.mainwp-restore-abc123-nothex.tmp', 'file', false ),
		);
	}

	/** @dataProvider sweep_candidates */
	public function test_sweep_removes_only_own_regular_temps( $name, $type, $deleted ) {
		$directory = $this->extract_to . '/dir';
		mkdir( $directory );
		$path = $directory . '/' . $name;
		$outside = $this->dir . '/outside.txt';
		file_put_contents( $outside, 'keep' );
		$handle = null;
		if ( 'link' === $type ) {
			symlink( $outside, $path );
		} elseif ( 'directory' === $type ) {
			mkdir( $path );
		} else {
			file_put_contents( $path, 'keep' );
			if ( 'open' === $type ) {
				$handle = fopen( $path, 'rb' );
				$this->assertIsResource( $handle );
			}
		}
		try {
			$part = $this->make_zip( 'sweep.zip', array( 'dir/file.txt' => 'restored' ) );
			$result = $this->run_all( $this->config( array( $part ), array( 'temp_owner' => 'abc123' ) ) );
			$this->assertNull( $result['error'] );
			$this->assertTrue( $result['done'] );
			clearstatcache( true, $path );
			if ( $deleted ) {
				$this->assertFileDoesNotExist( $path );
			} elseif ( 'directory' === $type ) {
				$this->assertDirectoryExists( $path );
			} else {
				$this->assertSame( 'keep', file_get_contents( $path ) );
				$this->assertSame( 'link' === $type, is_link( $path ) );
			}
			$this->assertSame( 'keep', file_get_contents( $outside ) );
			$this->assertSame( 'restored', file_get_contents( $directory . '/file.txt' ) );
		} finally {
			if ( is_resource( $handle ) ) {
				fclose( $handle );
			}
		}
	}

	/** @dataProvider data_read_only_target_formats */
	public function test_sweep_reclaims_inactive_foreign_owner_temps( $format ) {
		$directory = $this->extract_to . '/dir';
		mkdir( $directory );
		$owners = array( 'current' => 'abc123', 'terminal' => 'def456', 'active' => 'fed123', 'unknown' => 'abc789' );
		$temps = array();
		foreach ( $owners as $state => $owner ) {
			$temps[ $state ] = $directory . '/.mainwp-restore-' . $owner . '-0123456789abcdef.tmp';
			file_put_contents( $temps[ $state ], $state );
		}
		$this->restorer = new MainWP_Child_Backup_File_Restorer(
			function ( $owner ) use ( $owners ) {
				if ( $owners['terminal'] === $owner ) {
					return false;
				}
				return $owners['active'] === $owner ? true : null;
			}
		);
		$part = 'zip' === $format
			? $this->make_zip( 'sweep.zip', array( 'dir/file.txt' => 'restored' ) )
			: $this->make_tar( 'sweep.tar', array( 'dir/file.txt' => 'restored' ) );
		$result = $this->run_all( $this->config( array( $part ), array( 'temp_owner' => $owners['current'] ) ) );
		$this->assertNull( $result['error'] );
		$this->assertTrue( $result['done'] );
		$this->assertFileDoesNotExist( $temps['current'] );
		$this->assertFileDoesNotExist( $temps['terminal'] );
		$this->assertSame( 'active', file_get_contents( $temps['active'] ) );
		$this->assertSame( 'unknown', file_get_contents( $temps['unknown'] ) );
		$this->assertSame( 'restored', file_get_contents( $directory . '/file.txt' ) );
	}

	public function test_runner_temp_owner_liveness_uses_cached_active_tasks() {
		global $wpdb;
		$store = MainWP_Child_Backup_Task::instance();
		$completed = $store->create_task( 'restore' );
		$this->assertIsString( $completed );
		$tasks = array( $completed );
		try {
			$this->assertSame( 1, $store->checkpoint( $completed, 0, array( 'phase' => MainWP_Child_Backup_Task::PHASE_COMPLETED ), false ) );
			$leased = $store->create_task( 'restore_db' );
			$tasks[] = $leased;
			$this->assertIsString( $leased );
			$this->assertSame( 1, $store->checkpoint( $leased, 0, array( 'phase' => MainWP_Child_Backup_Task::PHASE_COMPLETED ), false ) );
			// Leases are written and compared against the database clock, which need not be UTC.
			$this->assertSame( 1, $wpdb->query( $wpdb->prepare( 'UPDATE ' . $store->get_table_name() . ' SET lease_expires_at = DATE_ADD( NOW(), INTERVAL 1 HOUR ) WHERE task_id = %s', $leased ) ) );
			$expired = $store->create_task( 'restore' );
			$tasks[] = $expired;
			$this->assertIsString( $expired );
			$this->assertSame( 1, $store->checkpoint( $expired, 0, array( 'phase' => MainWP_Child_Backup_Task::PHASE_COMPLETED ), false ) );
			$this->assertSame( 1, $wpdb->query( $wpdb->prepare( 'UPDATE ' . $store->get_table_name() . ' SET lease_expires_at = DATE_SUB( NOW(), INTERVAL 1 HOUR ) WHERE task_id = %s', $expired ) ) );
			$active = $store->create_task( 'restore_files' );
			$tasks[] = $active;
			$this->assertIsString( $active );
			$factory = new ReflectionMethod( MainWP_Child_Restore_Task_Runner::class, 'file_restorer' );
			$factory->setAccessible( true );
			$restorer = $factory->invoke( MainWP_Child_Restore_Task_Runner::instance() );
			$property = new ReflectionProperty( MainWP_Child_Backup_File_Restorer::class, 'is_temp_owner_live' );
			$property->setAccessible( true );
			$lookup = $property->getValue( $restorer );
			$this->assertIsCallable( $lookup );
			$queries = $wpdb->num_queries;
			$this->assertTrue( $lookup( substr( hash( 'sha256', $active ), 0, 16 ) ) );
			$this->assertSame( $queries + 1, $wpdb->num_queries, 'Read the active task set once.' );
			$this->assertFalse( $lookup( substr( hash( 'sha256', $completed ), 0, 16 ) ) );
			$this->assertTrue( $lookup( substr( hash( 'sha256', $leased ), 0, 16 ) ) );
			$this->assertFalse( $lookup( substr( hash( 'sha256', $expired ), 0, 16 ) ) );
			$this->assertFalse( $lookup( substr( hash( 'sha256', 'missing-restore-task' ), 0, 16 ) ) );
			$this->assertTrue( $lookup( substr( hash( 'sha256', $active ), 0, 16 ) ) );
			$this->assertSame( $queries + 1, $wpdb->num_queries, 'Further owners reuse this restorer\'s cached set.' );
		} finally {
			foreach ( $tasks as $task_id ) {
				$wpdb->delete( $store->get_table_name(), array( 'task_id' => $task_id ) );
			}
		}
	}

	public static function directory_aliases() {
		return array( 'dot' => array( 'a/./b/y' ), 'repeated slash' => array( 'a//b/y' ) );
	}

	/** @dataProvider directory_aliases */
	public function test_directory_sweep_runs_once_for_normalised_path( $alias ) {
		$directory = $this->extract_to . '/a/b';
		mkdir( $directory, 0777, true );
		$this->assertSame( $this->extract_to . '/' . $alias, MainWP_Child_Backup_File_Restorer::validate_extract_path( $alias, $this->extract_to ) );
		$stale = $directory . '/.mainwp-restore-abc123-0123456789abcdef.tmp';
		file_put_contents( $stale, 'abandoned bytes' );
		$part = $this->make_zip( 'sweep.zip', array( 'a/b/x' => 'one', $alias => 'two' ) );
		$config = $this->config( array( $part ), array( 'temp_owner' => 'abc123' ) );
		$first = $this->restorer->restore_slice( array(), $config, 0 );
		$this->assertNull( $first['error'] );
		$this->assertFalse( $first['done'] );
		$this->assertFileDoesNotExist( $stale );
		file_put_contents( $stale, 'new peer bytes' );
		$last = $this->restorer->restore_slice( $first['cursor'], $config, microtime( true ) + 60 );
		$this->assertNull( $last['error'] );
		$this->assertTrue( $last['done'] );
		$this->assertSame( 'new peer bytes', file_get_contents( $stale ), 'The same request must not sweep a directory twice.' );
		$this->assertSame( 'one', file_get_contents( $directory . '/x' ) );
		$this->assertSame( 'two', file_get_contents( $directory . '/y' ) );
	}

	public static function extraction_matrix() {
		$rows = array();
		foreach ( array( false, true ) as $exists ) {
			foreach ( array( false, true ) as $vanished ) {
				foreach ( array( 'create', 'chmod', 'rename', 'fine' ) as $failure ) {
					$message = null;
					if ( 'create' === $failure ) {
						$message = 'Could not create a temporary file in the site root: ';
					} elseif ( $vanished ) {
						$message = 'The temporary file for file.txt was removed before it could be promoted; another restore worker may be running on this site.';
					} elseif ( 'rename' === $failure || ( $exists && 'chmod' === $failure ) ) {
						$message = 'Could not write extracted file: file.txt.';
					}
					$rows[ ( $exists ? 'existing' : 'new' ) . '-' . ( $vanished ? 'vanished' : 'present' ) . '-' . $failure ] = array( $exists, $vanished, $failure, $message );
				}
			}
		}
		$rows['open-basedir-error'] = array( true, false, 'open-basedir', 'Could not create a temporary file in the site root: ' );
		$rows['invalid-utf8-error'] = array( true, false, 'invalid-utf8', 'Could not create a temporary file in the site root: ' );
		$rows['non-writable-no-errno'] = array( true, false, 'permissions', 'Could not create a temporary file in the site root: the restore needs write permission on that directory.' );
		$rows['writable-no-errno'] = array( true, false, 'no-errno', 'Could not create a temporary file in the site root: temporary file creation failed; check available disk space and filesystem restrictions.' );
		$rows['missing-directory'] = array( false, false, 'missing', 'Could not create a temporary file in the site root: the directory no longer exists.' );
		return $rows;
	}

	/** @dataProvider extraction_matrix */
	public function test_extraction_creation_and_promotion_matrix( $exists, $vanished, $failure, $message ) {
		$target = $this->extract_to . '/file.txt';
		if ( $exists ) {
			file_put_contents( $target, 'live bytes' );
		}
		$peer = $this->extract_to . '/peer';
		if ( 'create' === $failure ) {
			file_put_contents( $peer, 'peer bytes' );
		} elseif ( in_array( $failure, array( 'permissions', 'no-errno', 'open-basedir', 'invalid-utf8' ), true ) ) {
			symlink( $target, $peer );
		}
		ExtractionMatrixStream::$directory = $this->extract_to;
		ExtractionMatrixStream::$failure = $failure;
		ExtractionMatrixStream::$vanished = $vanished;
		ExtractionMatrixStream::$calls = array();
		$this->assertTrue( stream_wrapper_register( 'extractmatrix', ExtractionMatrixStream::class ) );
		$denied_temp = '/var/www/vhosts/example.com/httpdocs/wp-content/uploads/.mainwp-restore-ab12-first.tmp';
		$allowed_paths = '/var/www/vhosts/example.com/:/tmp/';
		$recorded_error = 'open-basedir' === $failure
			? 'fopen(): open_basedir restriction in effect. File(' . $denied_temp . ') is not within the allowed path(s): (' . $allowed_paths . ')'
			: "fopen(): denied filename bad-\xff-name";
		$restorer = new class( $failure, $peer, $recorded_error ) extends MainWP_Child_Backup_File_Restorer {
			public $attempts = 0;
			private $failure;
			private $peer;
			private $recorded_error;
			public function __construct( $failure, $peer, $recorded_error ) {
				$this->failure = $failure;
				$this->peer = $peer;
				$this->recorded_error = $recorded_error;
			}
			protected function entry_temp_path( $target ) {
				++$this->attempts;
				if ( 1 === $this->attempts && in_array( $this->failure, array( 'open-basedir', 'invalid-utf8' ), true ) ) {
					// Later symlink refusals leave the first attempt's recorded error intact.
					set_error_handler( static function () { return false; } );
					try {
						@trigger_error( $this->recorded_error, E_USER_WARNING );
					} finally {
						restore_error_handler();
					}
				}
				if ( 'create' === $this->failure ) {
					return $this->peer . '/temp';
				}
				if ( in_array( $this->failure, array( 'permissions', 'no-errno', 'open-basedir', 'invalid-utf8' ), true ) ) {
					return $this->peer;
				}
				return dirname( $target ) . '/.mainwp-restore-abc123-0123456789abcdef.tmp';
			}
		};
		$stream = fopen( 'php://temp', 'w+b' );
		fwrite( $stream, 'replacement' );
		rewind( $stream );
		$cursor = array( 'expanded_bytes' => 0 );
		try {
			if ( 'permissions' === $failure ) {
				chmod( $this->extract_to, 0555 );
				clearstatcache();
				if ( is_writable( $this->extract_to ) ) {
					$this->markTestSkipped( 'Filesystem does not enforce directory permissions.' );
				}
			}
			$method = new ReflectionMethod( MainWP_Child_Backup_File_Restorer::class, 'write_entry_stream' );
			$method->setAccessible( true );
			$path = in_array( $failure, array( 'permissions', 'no-errno', 'open-basedir', 'invalid-utf8' ), true ) ? $target : 'extractmatrix://root/file.txt';
			if ( 'missing' === $failure ) {
				$path = $this->extract_to . '/missing/file.txt';
			}
			$actual = null;
			try {
				$method->invokeArgs( $restorer, array( $stream, $path, &$cursor, 0, 'file.txt', $this->extract_to ) );
			} catch ( Exception $e ) {
				$actual = $e->getMessage();
			}
			if ( in_array( $failure, array( 'create', 'open-basedir', 'invalid-utf8' ), true ) ) {
				$this->assertIsString( $actual );
				$this->assertStringStartsWith( $message, $actual );
				$cause = trim( substr( $actual, strlen( $message ) ) );
				$this->assertNotSame( '', $cause );
				$this->assertNotSame( 'three generated names already existed; another restore may be running on this site.', $cause );
				if ( 'open-basedir' === $failure ) {
					$this->assertStringNotContainsString( $denied_temp, $actual );
					$this->assertStringNotContainsString( dirname( $target ), $actual );
					$this->assertStringNotContainsString( $allowed_paths, $actual );
					$this->assertStringNotContainsString( '/var/www/vhosts/example.com/', $actual );
					$this->assertStringContainsString( 'open_basedir restriction in effect', $cause );
				} elseif ( 'invalid-utf8' === $failure ) {
					$this->assertTrue( mb_check_encoding( $actual, 'UTF-8' ) );
					$this->assertStringContainsString( 'denied filename', $cause );
				}
				$this->assertNotSame( 'temporary file creation failed; check available disk space and filesystem restrictions.', $cause );
				$this->assertNotSame( 'the restore needs write permission on that directory.', $cause );
			} else {
				$this->assertSame( $message, $actual );
			}
			$this->assertSame( array(), glob( $this->extract_to . '/.mainwp-restore-*.tmp' ) );
			if ( null === $message ) {
				$this->assertSame( 'replacement', file_get_contents( $target ) );
			} elseif ( $exists ) {
				$this->assertSame( 'live bytes', file_get_contents( $target ) );
			} else {
				$this->assertFileDoesNotExist( $target );
			}
			$creation_failed = in_array( $failure, array( 'create', 'permissions', 'no-errno', 'missing', 'open-basedir', 'invalid-utf8' ), true );
			$this->assertSame( $creation_failed ? 3 : 1, $restorer->attempts );
			$expected_calls = $creation_failed ? array() : ( $exists ? array( 'chmod' ) : array() );
			if ( ! $creation_failed && ( ! $exists || ( ! $vanished && 'chmod' !== $failure ) ) ) {
				$expected_calls[] = 'rename';
			}
			$this->assertSame( $expected_calls, ExtractionMatrixStream::$calls );
		} finally {
			chmod( $this->extract_to, 0755 );
			fclose( $stream );
			stream_wrapper_unregister( 'extractmatrix' );
		}
	}

	public static function host_error_reduction_cases() {
		return array(
			'mbstring invalid bytes' => array( 'mbstring' ),
			'configured base with spaces' => array( 'raw' ),
			'resolved base with spaces' => array( 'resolved' ),
		);
	}

	/** @dataProvider host_error_reduction_cases */
	public function test_host_error_reduction_preserves_rendered_cause( $case ) {
		$method = new ReflectionMethod( MainWP_Child_Backup_File_Restorer::class, 'reduce_host_paths' );
		$method->setAccessible( true );
		if ( 'mbstring' === $case ) {
			if ( ! function_exists( 'mb_check_encoding' ) || ! function_exists( 'mb_convert_encoding' ) || ! function_exists( 'mb_substitute_character' ) ) {
				$this->markTestSkipped( 'This row pins output with mbstring available.' );
			}
			$previous = mb_substitute_character();
			mb_substitute_character( 63 );
			try {
				$this->assertSame( 'denied filename bad-?-name', esc_html( $method->invoke( $this->restorer, "denied filename bad-\xff-name" ) ) );
			} finally {
				mb_substitute_character( $previous );
			}
			return;
		}

		$base = $this->dir . '/private account/uploads';
		mkdir( $base, 0777, true );
		$alias = $this->dir . '/linked account';
		$this->assertTrue( symlink( $base, $alias ) );
		$filter = static function ( $uploads ) use ( $alias ) {
			$uploads['basedir'] = $alias;
			return $uploads;
		};
		add_filter( 'upload_dir', $filter );
		try {
			$reported_base = 'raw' === $case ? $alias : realpath( $base );
			$temp = $reported_base . '/nested/.mainwp-restore-ab12-cd34.tmp';
			$rendered = esc_html( $method->invoke( $this->restorer, 'fopen(' . $temp . '): access denied' ) );
			$this->assertNotSame( '', $rendered );
			$this->assertStringNotContainsString( $reported_base, $rendered );
			$this->assertStringNotContainsString( 'private account', $rendered );
			$this->assertStringNotContainsString( 'linked account', $rendered );
			$this->assertStringContainsString( basename( $temp ), $rendered );
			$this->assertStringContainsString( 'access denied', $rendered );
		} finally {
			remove_filter( 'upload_dir', $filter );
		}
	}

	public function test_entry_temp_producer_rejects_invalid_owner() {
		$owner = new ReflectionProperty( MainWP_Child_Backup_File_Restorer::class, 'temp_owner' );
		$owner->setAccessible( true );
		$method = new ReflectionMethod( MainWP_Child_Backup_File_Restorer::class, 'entry_temp_path' );
		$method->setAccessible( true );
		foreach ( array( null, '', 'not-hex', 'ABC', "abc\n" ) as $invalid ) {
			$owner->setValue( $this->restorer, $invalid );
			try {
				$method->invoke( $this->restorer, $this->extract_to . '/file.txt' );
				$this->fail( 'Invalid owner must not produce an unreserved temp name.' );
			} catch ( LogicException $e ) {
				$this->assertSame( 'An extraction temp requires a valid owner token.', $e->getMessage() );
			}
		}
		$owner->setValue( $this->restorer, 'abc123' );
		$this->assertTrue( MainWP_Child_Backup_File_Restorer::is_reserved_temp_name( basename( $method->invoke( $this->restorer, $this->extract_to . '/file.txt' ) ) ) );
	}

	public function test_replacement_preserves_owner_and_group() {
		if ( ! function_exists( 'posix_geteuid' ) || 0 !== posix_geteuid() ) {
			$this->markTestSkipped( 'Changing fixture ownership requires root.' );
		}
		$target = $this->extract_to . '/owned.txt';
		file_put_contents( $target, 'original' );
		$this->assertTrue( chown( $target, 65534 ) );
		$this->assertTrue( chgrp( $target, 65534 ) );
		clearstatcache( true, $target );
		$owner = fileowner( $target );
		$group = filegroup( $target );
		$part = $this->make_zip( 'owned.zip', array( 'owned.txt' => 'replacement' ) );
		$result = $this->run_all( $this->config( array( $part ) ) );
		$this->assertNull( $result['error'] );
		clearstatcache( true, $target );
		$this->assertSame( $owner, fileowner( $target ) );
		$this->assertSame( $group, filegroup( $target ) );
	}

	public static function temp_open_parents() {
		return array( 'nested' => array( 'locked/existing.txt', 'locked' ), 'root' => array( 'index.php', 'the site root' ) );
	}

	/** @dataProvider temp_open_parents */
	public function test_temp_open_failure_names_parent_directory( $entry, $label ) {
		$parent = dirname( $this->extract_to . '/' . $entry );
		if ( ! is_dir( $parent ) ) {
			mkdir( $parent );
		}
		file_put_contents( $this->extract_to . '/' . $entry, 'original' );
		chmod( $parent, 0555 );
		try {
			if ( is_writable( $parent ) ) {
				$this->markTestSkipped( 'Filesystem does not enforce directory permissions.' );
			}
			$part = $this->make_zip( 'locked.zip', array( $entry => 'replacement' ) );
			$result = $this->run_all( $this->config( array( $part ) ) );
			$this->assertSame( MainWP_Backup_Error::ERR_RESTORE_EXTRACT, $result['error_code'] );
			$this->assertStringStartsWith( 'Could not create a temporary file in ' . $label . ': ', $result['error'] );
			$this->assertStringNotContainsString( 'three generated names already existed; another restore may be running on this site.', $result['error'] );
			$this->assertSame( 'original', file_get_contents( $this->extract_to . '/' . $entry ) );
		} finally {
			chmod( $parent, 0755 );
		}
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

	public function test_dump_member_record_survives_slices() {
		$member = 'wp-content/dbBackup-task-original-wp_posts.sql.php';
		$zip = $this->make_zip( 'dumps.zip', array(
			$member => 'fresh dump',
			'ordinary.txt' => 'site content',
			'dbBackup.sql' => '',
		) );
		$result = $this->run_sliced( $this->config( array( $zip ) ) );
		$this->assertTrue( $result['done'] );
		$this->assertGreaterThan( 1, $result['slices'] );
		$this->assertSame( array( $this->extract_to . '/' . $member ), $result['cursor']['dump_files'] );
	}

	public function test_nested_dump_member_is_restored_as_ordinary_content() {
		$nested = 'wp-content/plugins/example/dbBackup-data.sql.php';
		$root = 'dbBackup-root.sql.php';
		$content = 'wp-content/dbBackup-content.sql.php';
		$zip = $this->make_zip( 'nested-dumps.zip', array( $nested => 'plugin content', $root => 'root dump', $content => 'content dump' ) );
		$result = $this->run_sliced( $this->config( array( $zip ) ) );
		$this->assertTrue( $result['done'] );
		$this->assertNull( $result['error'] );
		$this->assertSame( 'plugin content', file_get_contents( $this->extract_to . '/' . $nested ) );
		$this->assertSame( array( $this->extract_to . '/' . $root, $this->extract_to . '/' . $content ), $result['cursor']['dump_files'] );
	}

	public function test_failed_dump_member_is_not_recorded() {
		$zip = $this->make_zip( 'bad-dump.zip', array( '../dbBackup-wp_posts.sql.php' => 'unsafe' ) );
		$result = $this->run_all( $this->config( array( $zip ) ) );
		$this->assertNotNull( $result['error'] );
		$this->assertSame( array(), $result['cursor']['dump_files'] );
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

	public function test_config_include_tombstones_keep_exact_mapped_destinations_on_retry() {
		mkdir( $this->extract_to . '/sub' );
		foreach ( array( 'host.php', 'sub/host.php', 'host.php.bak', 'gone.php', '.htaccess', 'mainwp-htaccess' ) as $path ) {
			file_put_contents( $this->extract_to . '/' . $path, 'live bytes' );
		}
		$tombstones = $this->dir . '/config-tombstones.list';
		file_put_contents( $tombstones, "./host.php\nsub//./host.php\nhost.php.bak\ngone.php\nmainwp-htaccess\n" );
		$state = array( 'paths' => array( 'host.php', 'sub/host.php', '.htaccess' ), 'config' => 'live' );
		$cursor = array();
		$changes = 0;
		$after_change = static function () use ( &$changes ) { ++$changes; };
		foreach ( array( 2, 0 ) as $expected_deleted ) {
			$result = $this->restorer->apply_tombstones( $tombstones, $this->extract_to, array(), $cursor, $after_change, $state );
			$this->assertNull( $result['error'] );
			$this->assertSame( $expected_deleted, $result['deleted'] );
			$cursor = json_decode( wp_json_encode( $cursor ), true );
		}
		$this->assertSame( 2, $changes );
		foreach ( array( 'host.php', 'sub/host.php', '.htaccess', 'mainwp-htaccess' ) as $path ) {
			$this->assertSame( 'live bytes', file_get_contents( $this->extract_to . '/' . $path ) );
		}
		$this->assertFileDoesNotExist( $this->extract_to . '/host.php.bak' );
		$this->assertFileDoesNotExist( $this->extract_to . '/gone.php' );
	}

	public function test_unreadable_tombstones_keep_case_folded_prefixes_and_mapped_names_on_retry() {
		foreach ( array( 'a/b', 'a/bc', 'uploads/locked' ) as $dir ) { mkdir( $this->extract_to . '/' . $dir, 0755, true ); }
		foreach ( array( 'a/b/x', 'a/bc/x', 'uploads/locked/f.txt', 'mainwp-htaccess' ) as $path ) {
			file_put_contents( $this->extract_to . '/' . $path, 'live bytes' );
		}
		$tombstones = $this->dir . '/unreadable-tombstones.list';
		file_put_contents( $tombstones, "./a//b/x\na/bc/x\nuploads/locked/f.txt\nmainwp-htaccess\n" );
		$cover = array( 'all' => false, 'prefixes' => array( 'a/B', 'Uploads/Locked', '.htaccess' ) );
		$cursor = array();
		$changes = 0;
		foreach ( array( 1, 0 ) as $deleted ) {
			$out = $this->restorer->apply_tombstones( $tombstones, $this->extract_to, array(), $cursor, static function () use ( &$changes ) { ++$changes; }, array(), $cover );
			$this->assertNull( $out['error'] );
			$this->assertSame( $deleted, $out['deleted'] );
			$cursor = json_decode( wp_json_encode( $cursor ), true );
		}
		$this->assertSame( 1, $changes );
		$this->assertFileDoesNotExist( $this->extract_to . '/a/bc/x' );
		foreach ( array( 'a/b/x', 'uploads/locked/f.txt', 'mainwp-htaccess' ) as $path ) { $this->assertSame( 'live bytes', file_get_contents( $this->extract_to . '/' . $path ) ); }
	}

	public function test_unreadable_all_suppresses_deletion_but_still_rejects_hostile_tombstone_lines() {
		file_put_contents( $this->extract_to . '/live.txt', 'keep' );
		$tombstones = $this->dir . '/all-tombstones.list';
		$cursor = array();
		foreach ( array( "../escape.txt", '/absolute.txt', "bad\0name" ) as $hostile ) {
			file_put_contents( $tombstones, "live.txt\n" . $hostile . "\n" );
			$out = $this->restorer->apply_tombstones( $tombstones, $this->extract_to, array(), $cursor, null, array(), array( 'all' => true, 'prefixes' => array() ) );
			$this->assertSame( 0, $out['deleted'] );
			$this->assertSame( MainWP_Backup_Error::ERR_RESTORE_EXTRACT, $out['error_code'] );
			$this->assertSame( 'keep', file_get_contents( $this->extract_to . '/live.txt' ) );
		}
	}

	/**
	 * A manifest transition must not delete config bytes extraction preserves.
	 */
	public function test_tombstones_preserve_legacy_clone_config() {
		mkdir( $this->extract_to . '/clone' );
		file_put_contents( $this->extract_to . '/clone/config.txt', 'leftover clone config' );
		file_put_contents( $this->extract_to . '/gone.txt', 'ordinary file' );
		$tombstones = $this->dir . '/tombstones.list';
		file_put_contents( $tombstones, "clone/config.txt\ngone.txt\n" );

		$result = $this->restorer->apply_tombstones( $tombstones, $this->extract_to );
		$this->assertNull( $result['error'] );
		$this->assertSame( 1, $result['deleted'] );
		$this->assertFileExists( $this->extract_to . '/clone/config.txt' );
		$this->assertSame( 'leftover clone config', file_get_contents( $this->extract_to . '/clone/config.txt' ) );
		$this->assertFileDoesNotExist( $this->extract_to . '/gone.txt' );
	}

	public function test_dotted_filenames_extract_and_apply_tombstones() {
		$entries = array( 'report..pdf' => 'report bytes', 'a..b/c.txt' => 'nested bytes' );
		$zip = $this->make_zip( 'dotted.zip', $entries );
		$result = $this->run_all( $this->config( array( $zip ) ) );
		$this->assertNull( $result['error'] );
		foreach ( $entries as $name => $content ) {
			$this->assertSame( $content, file_get_contents( $this->extract_to . '/' . $name ) );
		}
		$tombstones = $this->dir . '/dotted.list';
		file_put_contents( $tombstones, "report..pdf\na..b/c.txt\n" );
		$result = $this->restorer->apply_tombstones( $tombstones, $this->extract_to );
		$this->assertNull( $result['error'] );
		$this->assertSame( 2, $result['deleted'] );
		foreach ( array_keys( $entries ) as $name ) {
			$this->assertFileDoesNotExist( $this->extract_to . '/' . $name );
		}
	}

	public static function unsafe_tombstone_paths() {
		return array(
			array( '..' ), array( 'safe/..' ), array( 'safe/../file.txt' ),
			array( '/absolute.txt' ), array( 'C:\\absolute.txt' ), array( 'C:/absolute.txt' ),
			array( '\\file.txt' ), array( '\\\\srv\\share' ), array( 'safe\\..\\file.txt' ), array( '..\\file.txt' ),
			array( "safe\0file.txt" ), array( "\0file.txt" ), array( "file.txt\0" ),
		);
	}

	public function test_tombstones_accept_backslash_in_filename() {
		file_put_contents( $this->extract_to . '/safe\\file.txt', 'literal' );
		$tombstones = $this->dir . '/backslash.list';
		file_put_contents( $tombstones, "safe\\file.txt\n" );
		$result = $this->restorer->apply_tombstones( $tombstones, $this->extract_to );
		$this->assertNull( $result['error'] );
		$this->assertSame( 1, $result['deleted'] );
		$this->assertFileDoesNotExist( $this->extract_to . '/safe\\file.txt' );
	}

	/** @dataProvider unsafe_tombstone_paths */
	public function test_unsafe_tombstone_stays_rejected( $name ) {
		$tombstones = $this->dir . '/unsafe.list';
		file_put_contents( $tombstones, $name . "\n" );
		$result = $this->restorer->apply_tombstones( $tombstones, $this->extract_to );
		$this->assertSame( MainWP_Backup_Error::ERR_RESTORE_EXTRACT, $result['error_code'] );
		$this->assertSame( 0, $result['deleted'] );
	}

	public function test_tombstones_accept_dotted_filenames() {
		mkdir( $this->extract_to . '/a..b' );
		file_put_contents( $this->extract_to . '/report..pdf', 'report' );
		file_put_contents( $this->extract_to . '/a..b/c.txt', 'nested' );
		$tombstones = $this->dir . '/dotted.list';
		file_put_contents( $tombstones, "report..pdf\na..b/c.txt\n" );
		$result = $this->restorer->apply_tombstones( $tombstones, $this->extract_to );
		$this->assertNull( $result['error'] );
		$this->assertSame( 2, $result['deleted'] );
		$this->assertFileDoesNotExist( $this->extract_to . '/report..pdf' );
		$this->assertFileDoesNotExist( $this->extract_to . '/a..b/c.txt' );
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
	 * A tar.bz2 part decompresses before PharData reads its entries.
	 */
	public function test_tar_bz2_part_extracted() {
		if ( ! function_exists( 'bzopen' ) ) {
			$this->markTestSkipped( 'The bzip2 extension is unavailable.' );
		}
		$sql = '<?php exit(); ?>' . "\nCREATE TABLE `wp_a` (id INT);\n";
		$tar = $this->make_tar( 'dump.tar', array( 'dbBackup-wp_a.sql.php' => $sql, 'sub/nested.txt' => 'nested-bytes' ) );
		$compressed = bzcompress( file_get_contents( $tar ) );
		$this->assertIsString( $compressed );
		$part = $tar . '.bz2';
		$this->assertSame( strlen( $compressed ), file_put_contents( $part, $compressed ) );
		Phar::unlinkArchive( $tar );

		$result = $this->run_all( $this->config( array( $part ) ) );
		$this->assertNull( $result['error'] );
		$this->assertTrue( $result['done'] );
		$this->assertSame( $sql, file_get_contents( $this->extract_to . '/dbBackup-wp_a.sql.php' ) );
		$this->assertSame( 'nested-bytes', file_get_contents( $this->extract_to . '/sub/nested.txt' ) );
		$plain = $this->dir . '/dump-' . substr( sha1( basename( $part ) ), 0, 12 ) . '.plain.tar';
		$this->assertFileDoesNotExist( $plain );
		$this->assertSame( array(), glob( $plain . '*.tmp' ) );
		$this->assertFileExists( $part );
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
	 * The gzip branch must not write to the name every runner would pick. A
	 * file parked on the old shared name survives untouched because nothing
	 * writes there any more; sharing it is what let one runner promote
	 * another's half-written stream.
	 */
	public function test_tar_gz_does_not_write_the_shared_temp_name() {
		$tar  = $this->dir . '/dump.tar';
		$phar = new PharData( $tar );
		$phar->addFromString( 'wp-content/uploads/marker.png', 'png-bytes' );
		$phar->compress( Phar::GZ );
		unset( $phar );
		Phar::unlinkArchive( $tar );
		$part = $this->dir . '/s42-full-site.example.com-September-17-2026-23-55.tar.gz';
		rename( $tar . '.gz', $part );
		$plain  = $this->dir . '/s42-full-site-example-com-September-17-2026-23-55-' . substr( sha1( basename( $part ) ), 0, 12 ) . '.plain.tar';
		$shared = $plain . '.tmp';
		file_put_contents( $shared, 'another-runner-bytes' );

		$result = $this->run_all( $this->config( array( $part ) ) );
		$this->assertNull( $result['error'] );
		$this->assertTrue( $result['done'] );
		$this->assertSame( 'png-bytes', file_get_contents( $this->extract_to . '/wp-content/uploads/marker.png' ) );
		$this->assertSame( 'another-runner-bytes', file_get_contents( $shared ), 'The shared temporary name must be left alone.' );
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
		$this->assertTrue( touch( $tmp, time() - 2 * HOUR_IN_SECONDS ) );
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
	 * Abandoned writes must be reclaimed without deleting a live peer's bytes.
	 */
	public function test_plain_tar_sweeps_stale_temps_and_preserves_fresh_peers() {
		$this->restorer = new ObservedTempRestorer();
		$alias_method = new ReflectionMethod( MainWP_Child_Backup_File_Restorer::class, 'phar_safe_tar_path' );
		$alias_method->setAccessible( true );

		foreach ( array( '.tar', '.tar.gz', '.tar.bz2' ) as $extension ) {
			if ( '.tar.bz2' === $extension && ! function_exists( 'bzopen' ) ) {
				continue;
			}
			$name = 's42-full-site.example-staging-site.test-September-17-2026-23-55' . $extension;
			$part = $this->dir . '/' . $name;
			$tar = $this->make_tar( 'fixture' . str_replace( '.', '-', $extension ) . '.tar', array( 'index.php' => 'part-content' ) );
			if ( '.tar.bz2' === $extension ) {
				$compressed = bzcompress( file_get_contents( $tar ) );
				$this->assertIsString( $compressed );
				$this->assertSame( strlen( $compressed ), file_put_contents( $part, $compressed ) );
			} elseif ( '.tar.gz' === $extension ) {
				$this->assertNotFalse( file_put_contents( $part, gzencode( file_get_contents( $tar ) ) ) );
			} else {
				$this->assertTrue( rename( $tar, $part ) );
			}
			$plain = $alias_method->invoke( null, $part );
			$stale = $plain . '.99999.tmp';
			$fresh = $plain . '.live-peer.tmp';
			$unknown = $plain . '.unknown.tmp';
			$this->assertNotFalse( file_put_contents( $unknown, 'unknown-age' ) );
			$this->assertTrue( touch( $unknown, time() - 2 * HOUR_IN_SECONDS ) );
			$this->assertNotFalse( file_put_contents( $stale, 'abandoned' ) );
			$this->assertNotFalse( file_put_contents( $fresh, 'peer-content' ) );
			$this->assertTrue( touch( $stale, time() - MainWP_Child_Backup_Task_Runner::LEASE_TTL_SECONDS - 30 ) );
			$this->assertTrue( touch( $fresh, time() - MainWP_Child_Backup_Task_Runner::LEASE_TTL_SECONDS + 60 ) );

			$result = $this->run_all( $this->config( array( $part ) ) );
			$this->assertNull( $result['error'], $extension );
			$this->assertTrue( $result['done'], $extension );
			$this->assertSame( 'part-content', file_get_contents( $this->extract_to . '/index.php' ), $extension );
			$this->assertFileDoesNotExist( $stale, $extension );
			$this->assertFileExists( $fresh, $extension );
			$this->assertSame( 'peer-content', file_get_contents( $fresh ), $extension );
			$this->assertSame( 'unknown-age', file_get_contents( $unknown ), $extension );
			$this->assertSame( array( $fresh, $unknown ), glob( $plain . '*.tmp' ), $extension );
		}
	}

	/**
	 * A failed close must reject even a full-sized decompressed tar.
	 */
	public function test_plain_tar_does_not_promote_failed_close() {
		$this->restorer = new FailedCloseRestorer();
		$tar = $this->make_tar( 'close.tar', array( 'index.php' => 'part-content' ) );
		$part = $tar . '.gz';
		file_put_contents( $part, gzencode( file_get_contents( $tar ) ) );
		$plain = $this->dir . '/close-' . substr( sha1( basename( $part ) ), 0, 12 ) . '.plain.tar';

		$result = $this->run_all( $this->config( array( $part ) ) );
		$this->assertSame( filesize( $tar ), $this->restorer->closed_size );
		$this->assertFalse( $result['done'] );
		$this->assertSame( MainWP_Backup_Error::ERR_RESTORE_EXTRACT, $result['error_code'] );
		$this->assertFileDoesNotExist( $plain );
		$this->assertFileDoesNotExist( $this->extract_to . '/index.php' );
		$this->assertSame( array(), glob( $plain . '*.tmp' ) );
		$this->assertFileExists( $part );
	}

	/**
	 * Brackets in archive names must be matched literally during cleanup.
	 */
	public function test_plain_tar_sweeps_stale_temps_with_brackets() {
		$tar = $this->make_tar( 'brackets.tar', array( 'index.php' => 'part-content' ) );
		$part = $this->dir . '/site[ab].tar.gz';
		file_put_contents( $part, gzencode( file_get_contents( $tar ) ) );
		$plain = $this->dir . '/site[ab]-' . substr( sha1( basename( $part ) ), 0, 12 ) . '.plain.tar';
		$stale = $plain . '.dead.tmp';
		$other = $plain . '-other.dead.tmp';
		file_put_contents( $stale, 'abandoned' );
		file_put_contents( $other, 'unrelated' );
		touch( $stale, time() - MainWP_Child_Backup_Task_Runner::LEASE_TTL_SECONDS - 30 );
		touch( $other, time() - MainWP_Child_Backup_Task_Runner::LEASE_TTL_SECONDS - 30 );

		$result = $this->run_all( $this->config( array( $part ) ) );
		$this->assertNull( $result['error'] );
		$this->assertTrue( $result['done'] );
		$this->assertFileDoesNotExist( $stale );
		$this->assertSame( 'unrelated', file_get_contents( $other ) );
	}

	/**
	 * Password filters cannot control either writer's temporary filename.
	 */
	public function test_plain_tar_temp_suffix_is_hex_despite_password_filter() {
		$this->restorer = new ObservedTempRestorer();
		$filter = static function () {
			return 'shared/[ab]';
		};
		add_filter( 'random_password', $filter );
		try {
			foreach ( array( '.tar', '.tar.gz', '.tar.bz2' ) as $extension ) {
				if ( '.tar.bz2' === $extension && ! function_exists( 'bzopen' ) ) {
					continue;
				}
				$name = 's42-full-site.example-staging-site.test-September-17-2026-23-55' . $extension;
				$part = $this->dir . '/' . $name;
				$tar = $this->make_tar( 'suffix' . str_replace( '.', '-', $extension ) . '.tar', array( 'index.php' => 'part-content' ) );
				if ( '.tar.bz2' === $extension ) {
					$compressed = bzcompress( file_get_contents( $tar ) );
					$this->assertIsString( $compressed );
					$this->assertSame( strlen( $compressed ), file_put_contents( $part, $compressed ) );
				} elseif ( '.tar.gz' === $extension ) {
					file_put_contents( $part, gzencode( file_get_contents( $tar ) ) );
				} else {
					rename( $tar, $part );
				}
				$result = $this->run_all( $this->config( array( $part ) ) );
				$this->assertNull( $result['error'], $extension );
				$this->assertTrue( $result['done'], $extension );
			}
		} finally {
			remove_filter( 'random_password', $filter );
		}
		$this->assertCount( function_exists( 'bzopen' ) ? 3 : 2, $this->restorer->temp_paths );
		foreach ( $this->restorer->temp_paths as $path ) {
			$this->assertMatchesRegularExpression( '/\.[0-9a-f]{12}\.tmp$/', $path );
			$this->assertFileDoesNotExist( $path );
		}
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
	 * A bzip2 part uses a plain tar alias for its decompressed bytes.
	 */
	public function test_phar_safe_tar_path_maps_bz2_to_plain_tar() {
		$name = 's42-full-site.example-staging-site.test-September-17-2026-23-55.tar.bz2';
		$this->assertGreaterThanOrEqual( 50, strlen( $name ) - strpos( $name, '.' ), 'The fixture name must be one PharData rejects.' );
		$method = new ReflectionMethod( MainWP_Child_Backup_File_Restorer::class, 'phar_safe_tar_path' );
		$method->setAccessible( true );
		$alias = $method->invoke( null, $this->dir . '/' . $name );

		$this->assertStringEndsWith( '.plain.tar', $alias );
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
		$this->assertStringContainsString( 'phar extension is disabled', $result['error'] );
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
	private function file_link( $target, $path ) {
		if ( ! function_exists( 'symlink' ) || ! @symlink( $target, $path ) ) {
			$this->markTestSkipped( 'Filesystem does not support symlinks.' );
		}
	}

	public static function file_symlink_formats() {
		return array( 'zip' => array( 'zip' ), 'tar' => array( 'tar' ) );
	}

	/** @dataProvider file_symlink_formats */
	public function test_file_symlinks_keep_dangling_leaves_consume_bytes_and_resume( $format ) {
		$entries = array();
		for ( $i = 0; $i < 23; ++$i ) {
			$path = sprintf( 'link-%02d.txt', $i );
			$this->file_link( 'missing-target', $this->extract_to . '/' . $path );
			$entries[ $path ] = 'archive';
		}
		$entries['zz-normal.txt'] = 'last';
		$part = 'zip' === $format ? $this->make_zip( 'links.zip', $entries ) : $this->make_tar( 'links.tar', $entries );
		$config = $this->config( array( $part, $part ) );
		$cursor = array();
		for ( $slice = 0; $slice < 60; ++$slice ) {
			$out = ( new MainWP_Child_Backup_File_Restorer() )->restore_slice( $cursor, $config, 0 );
			$this->assertNull( $out['error'] );
			$cursor = json_decode( json_encode( $out['cursor'] ), true );
			if ( $out['done'] ) { break; }
		}
		$this->assertTrue( $out['done'] );
		$this->assertSame( 23, $cursor['kept_symlinks'] ?? null );
		$this->assertSame( array_slice( array_keys( $entries ), 0, 20 ), $cursor['kept_symlink_paths'] ?? null );
		$this->assertSame( 330, $cursor['expanded_bytes'] );
		$this->assertSame( 48, $cursor['entries'] );
		$this->assertSame( 'last', file_get_contents( $this->extract_to . '/zz-normal.txt' ) );
		foreach ( array_keys( $entries ) as $path ) {
			if ( 'zz-normal.txt' !== $path ) { $this->assertTrue( is_link( $this->extract_to . '/' . $path ), $path ); }
		}
		$this->assertSame( array(), glob( $this->extract_to . '/.mainwp-restore-*.tmp' ) );
		$this->assertFileDoesNotExist( $this->extract_to . '/missing-target' );
	}

	/** @dataProvider file_symlink_formats */
	public function test_file_symlink_mu_plugins_whole_dir_preserve_still_consumes_and_reports( $format ) {
		$path = 'wp-content/mu-plugins/host.php';
		mkdir( dirname( $this->extract_to . '/' . $path ), 0777, true );
		$this->file_link( 'missing', $this->extract_to . '/' . $path );
		$preserve = array( 'directory' => 'wp-content/mu-plugins', 'mode' => 'whole_dir', 'leaf_paths' => array() );
		$part = 'zip' === $format ? $this->make_zip( 'mu-links.zip', array( $path => 'archive' ) ) : $this->make_tar( 'mu-links.tar', array( $path => 'archive' ) );
		$out = $this->run_all( $this->config( array( $part ), array( 'mu_plugins_preserve' => $preserve ) ) );
		$this->assertNull( $out['error'] );
		$this->assertTrue( is_link( $this->extract_to . '/' . $path ) );
		$this->assertSame( 7, $out['cursor']['expanded_bytes'] );
		$this->assertSame( 1, $out['cursor']['kept_symlinks'] ?? null );
		$tomb = $this->dir . '/mu-links.list';
		file_put_contents( $tomb, $path . "\n" );
		$result = $this->restorer->apply_tombstones( $tomb, $this->extract_to, $preserve, $out['cursor'] );
		$this->assertNull( $result['error'] );
		$this->assertSame( 0, $result['deleted'] );
		$this->assertTrue( is_link( $this->extract_to . '/' . $path ) );
		$this->assertSame( 1, $out['cursor']['kept_symlinks'] ?? null );
		$this->assertSame( array( $path ), $out['cursor']['kept_symlink_paths'] ?? null );
	}

	/** @dataProvider file_symlink_formats */
	public function test_mu_plugins_preserve_final_check_uses_the_mapped_htaccess_destination( $format ) {
		$alias  = 'wp-content/mu-plugins/mainwp-htaccess';
		$path   = 'wp-content/mu-plugins/.htaccess';
		$target = $this->extract_to . '/' . $path;
		mkdir( dirname( $target ), 0777, true );
		$leaf = array( 'directory' => 'wp-content/mu-plugins', 'mode' => 'whole_dir', 'leaf_paths' => array( $path => true ) );
		$part = 'zip' === $format ? $this->make_zip( 'mu-alias-leaf.zip', array( $alias => 'archive rules' ) ) : $this->make_tar( 'mu-alias-leaf.tar', array( $alias => 'archive rules' ) );
		$out  = $this->run_all( $this->config( array( $part ), array( 'mu_plugins_preserve' => $leaf ) ) );
		$this->assertNull( $out['error'] );
		$this->assertSame( 'archive rules', file_get_contents( $target ), 'A leaf of the backup must be restored through its mapped name.' );

		unlink( $target );
		$this->file_link( 'missing', $target );
		$this->restorer = new class( $target ) extends MainWP_Child_Backup_File_Restorer {
			private $host_path;
			public function __construct( $host_path ) {
				$this->host_path = $host_path;
			}
			protected function entry_temp_path( $target ) {
				if ( $target === $this->host_path && is_link( $target ) ) {
					unlink( $target );
					file_put_contents( $target, 'host rules' );
					clearstatcache( true, $target );
				}
				return parent::entry_temp_path( $target );
			}
		};
		$set  = array( 'directory' => 'wp-content/mu-plugins', 'mode' => 'set', 'paths' => array( $path => true ) );
		$part = 'zip' === $format ? $this->make_zip( 'mu-alias-set.zip', array( $alias => 'archive rules' ) ) : $this->make_tar( 'mu-alias-set.tar', array( $alias => 'archive rules' ) );
		$out  = $this->run_all( $this->config( array( $part ), array( 'mu_plugins_preserve' => $set ) ) );
		$this->assertNull( $out['error'] );
		$this->assertSame( 'host rules', file_get_contents( $target ), 'A protected destination must survive its link being replaced mid-entry.' );
		$this->assertSame( 0, $out['cursor']['kept_symlinks'] ?? 0 );
	}

	/** @dataProvider file_symlink_formats */
	public function test_file_symlink_mu_plugins_whole_dir_preserve_survives_host_replacement( $format ) {
		$path = 'wp-content/mu-plugins/host.php';
		$target = $this->extract_to . '/' . $path;
		mkdir( dirname( $target ), 0777, true );
		$this->file_link( 'missing', $target );
		$this->restorer = new class( $target ) extends MainWP_Child_Backup_File_Restorer {
			private $host_path;
			public function __construct( $host_path ) {
				$this->host_path = $host_path;
			}
			protected function entry_temp_path( $target ) {
				if ( $target === $this->host_path && is_link( $target ) ) {
					unlink( $target );
					file_put_contents( $target, 'host deployment' );
					clearstatcache( true, $target );
				}
				return parent::entry_temp_path( $target );
			}
		};
		$preserve = array( 'directory' => 'wp-content/mu-plugins', 'mode' => 'whole_dir', 'leaf_paths' => array() );
		$entries = array( $path => 'archive', 'zz-after.txt' => 'after' );
		$part = 'zip' === $format ? $this->make_zip( 'mu-replaced.zip', $entries ) : $this->make_tar( 'mu-replaced.tar', $entries );
		$out = $this->run_all( $this->config( array( $part ), array( 'mu_plugins_preserve' => $preserve ) ) );
		$this->assertNull( $out['error'] );
		$this->assertTrue( $out['done'] );
		$this->assertSame( 'host deployment', file_get_contents( $target ) );
		$this->assertFalse( is_link( $target ) );
		$this->assertSame( 12, $out['cursor']['expanded_bytes'] );
		$this->assertSame( 1, $out['cursor']['part_index'] );
		$this->assertSame( 0, $out['cursor']['entry_index'] );
		$this->assertSame( 'after', file_get_contents( $this->extract_to . '/zz-after.txt' ) );
		$this->assertSame( array(), glob( dirname( $target ) . '/.mainwp-restore-*.tmp' ) );
		$this->assertSame( array(), glob( $this->extract_to . '/.mainwp-restore-*.tmp' ) );
		$this->assertSame( 0, $out['cursor']['kept_symlinks'] ?? 0 );
		$this->assertSame( array(), $out['cursor']['kept_symlink_paths'] ?? array() );
	}

	/** @dataProvider file_symlink_formats */
	public function test_file_symlink_entries_still_enforce_expanded_byte_limit( $format ) {
		$this->file_link( 'missing', $this->extract_to . '/link.txt' );
		$part = 'zip' === $format ? $this->make_zip( 'limit.zip', array( 'link.txt' => 'archive' ) ) : $this->make_tar( 'limit.tar', array( 'link.txt' => 'archive' ) );
		$restorer = new class extends MainWP_Child_Backup_File_Restorer {
			protected function extraction_limits() {
				$limits = parent::extraction_limits();
				$limits['expanded_bytes'] = 6;
				return $limits;
			}
		};
		$out = $restorer->restore_slice( array(), $this->config( array( $part ) ), PHP_INT_MAX );
		$this->assertSame( MainWP_Backup_Error::ERR_RESTORE_EXTRACT, $out['error_code'] );
		$this->assertTrue( is_link( $this->extract_to . '/link.txt' ) );
		$this->assertSame( array(), glob( $this->extract_to . '/.mainwp-restore-*.tmp' ) );
	}

	/** @dataProvider file_symlink_formats */
	public function test_file_entry_onto_directory_link_is_kept_and_reported_without_error( $format ) {
		$path = 'wp-content/mu-plugins/dir-link';
		mkdir( $this->extract_to . '/wp-content/mu-plugins', 0777, true );
		mkdir( $this->dir . '/directory' );
		file_put_contents( $this->dir . '/directory/host.txt', 'host directory bytes' );
		$this->file_link( $this->dir . '/directory', $this->extract_to . '/' . $path );
		$entries = array( $path => 'file', 'zz-after.txt' => 'after' );
		$part = 'zip' === $format ? $this->make_zip( 'dir-link.zip', $entries ) : $this->make_tar( 'dir-link.tar', $entries );
		$preserve = array( 'directory' => 'wp-content/mu-plugins', 'mode' => 'whole_dir', 'leaf_paths' => array() );
		foreach ( array( array(), $preserve ) as $policy ) {
			$out = $this->run_all( $this->config( array( $part ), array( 'mu_plugins_preserve' => $policy ) ) );
			$this->assertNull( $out['error'] );
			$this->assertNull( $out['error_code'] );
			$this->assertTrue( $out['done'] );
			$this->assertTrue( is_link( $this->extract_to . '/' . $path ) );
			$this->assertSame( $this->dir . '/directory', readlink( $this->extract_to . '/' . $path ) );
			$this->assertSame( 'host directory bytes', file_get_contents( $this->dir . '/directory/host.txt' ) );
			$this->assertSame( 'after', file_get_contents( $this->extract_to . '/zz-after.txt' ) );
			$this->assertSame( 9, $out['cursor']['expanded_bytes'] );
			$this->assertSame( 1, $out['cursor']['kept_symlinks'] ?? null );
			$this->assertSame( array( $path ), $out['cursor']['kept_symlink_paths'] ?? null );
			$this->assertSame( array(), glob( $this->extract_to . '/wp-content/mu-plugins/.mainwp-restore-*.tmp' ) );
		}
		$tombstones = $this->dir . '/dir-link.list';
		file_put_contents( $tombstones, $path . "\n" );
		$cursor = array();
		$result = $this->restorer->apply_tombstones( $tombstones, $this->extract_to, array(), $cursor );
		$this->assertNull( $result['error'] );
		$this->assertSame( 0, $result['deleted'] );
		$this->assertTrue( is_link( $this->extract_to . '/' . $path ) );
		$this->assertSame( 0, $cursor['kept_symlinks'] ?? 0 );
	}

	public function test_file_symlink_tombstones_keep_live_and_dangling_links_once() {
		file_put_contents( $this->dir . '/outside.txt', 'secret' );
		$this->file_link( $this->dir . '/outside.txt', $this->extract_to . '/live.txt' );
		$this->file_link( 'missing', $this->extract_to . '/dangling.txt' );
		$tombstones = $this->dir . '/links.list';
		file_put_contents( $tombstones, "live.txt\ndangling.txt\nlive.txt\n" );
		$cursor = array();
		foreach ( array( 1, 2 ) as $slice ) {
			$out = ( new MainWP_Child_Backup_File_Restorer() )->apply_tombstones( $tombstones, $this->extract_to, array(), $cursor );
			$cursor = json_decode( json_encode( $cursor ), true );
			$this->assertNull( $out['error'] );
			$this->assertSame( 0, $out['deleted'] );
			$this->assertTrue( is_link( $this->extract_to . '/live.txt' ) );
			$this->assertTrue( is_link( $this->extract_to . '/dangling.txt' ) );
			$this->assertSame( 'secret', file_get_contents( $this->dir . '/outside.txt' ) );
			$this->assertSame( 2, $cursor['kept_symlinks'] ?? null );
			$this->assertSame( array( 'live.txt', 'dangling.txt' ), $cursor['kept_symlink_paths'] ?? null );
		}
	}


	/**
	 * A link leaf is never deleted, so where its parent resolves cannot matter (b39).
	 */
	public function test_tombstoned_links_under_an_outward_directory_link_are_kept() {
		mkdir( $this->dir . '/mount' );
		file_put_contents( $this->dir . '/mount/target.txt', 'mounted' );
		$this->file_link( $this->dir . '/mount', $this->extract_to . '/uploads' );
		$this->file_link( 'missing', $this->dir . '/mount/dangling.txt' );
		$this->file_link( $this->dir . '/mount/target.txt', $this->dir . '/mount/live.txt' );
		$tombstones = $this->dir . '/outward-links.list';
		file_put_contents( $tombstones, "uploads/dangling.txt\nuploads/live.txt\n" );
		$cursor = array();
		foreach ( array( 1, 2 ) as $slice ) {
			$out    = ( new MainWP_Child_Backup_File_Restorer() )->apply_tombstones( $tombstones, $this->extract_to, array(), $cursor );
			$cursor = json_decode( json_encode( $cursor ), true );
			$this->assertNull( $out['error'] );
			$this->assertSame( 0, $out['deleted'] );
			$this->assertTrue( is_link( $this->dir . '/mount/dangling.txt' ) );
			$this->assertTrue( is_link( $this->dir . '/mount/live.txt' ) );
			$this->assertSame( 'mounted', file_get_contents( $this->dir . '/mount/target.txt' ) );
			$this->assertSame( 2, $cursor['kept_symlinks'] ?? null );
			$this->assertSame( array( 'uploads/dangling.txt', 'uploads/live.txt' ), $cursor['kept_symlink_paths'] ?? null );
		}
	}

	/**
	 * A file reached through an in-root directory link is skipped like the reconciler does (b35).
	 *
	 * @dataProvider linked_ancestor_paths
	 */
	public function test_tombstones_never_delete_through_an_in_root_directory_link( $link, $real, $relpath ) {
		mkdir( $this->extract_to . '/' . dirname( $real ), 0777, true );
		file_put_contents( $this->extract_to . '/' . $real, 'real bytes' );
		$this->file_link( $this->extract_to . '/' . explode( '/', $real )[0], $this->extract_to . '/' . $link );
		file_put_contents( $this->extract_to . '/plain.txt', 'tombstoned' );
		$tombstones = $this->dir . '/alias.list';
		file_put_contents( $tombstones, $relpath . "\nplain.txt\n" );
		$cursor = array();
		$out    = ( new MainWP_Child_Backup_File_Restorer() )->apply_tombstones( $tombstones, $this->extract_to, array(), $cursor );
		$this->assertNull( $out['error'] );
		$this->assertSame( 1, $out['deleted'] );
		$this->assertSame( 'real bytes', file_get_contents( $this->extract_to . '/' . $real ) );
		$this->assertFileDoesNotExist( $this->extract_to . '/plain.txt' );
		$this->assertSame( 0, $cursor['kept_symlinks'] ?? 0 );
	}

	public static function linked_ancestor_paths() {
		return array(
			'parent link'      => array( 'alias', 'real-dir/leaf.php', 'alias/leaf.php' ),
			'grandparent link' => array( 'alias', 'real-dir/sub/leaf.php', 'alias/sub/leaf.php' ),
		);
	}

	/**
	 * A regular file whose parent resolves outside the root still aborts, as before.
	 */
	public function test_tombstoned_file_under_an_outward_directory_link_still_aborts() {
		mkdir( $this->dir . '/mount' );
		file_put_contents( $this->dir . '/mount/file.txt', 'mounted' );
		$this->file_link( $this->dir . '/mount', $this->extract_to . '/uploads' );
		$tombstones = $this->dir . '/outward-file.list';
		file_put_contents( $tombstones, "uploads/file.txt\n" );
		$cursor = array();
		$out    = ( new MainWP_Child_Backup_File_Restorer() )->apply_tombstones( $tombstones, $this->extract_to, array(), $cursor );
		$this->assertSame( MainWP_Backup_Error::ERR_RESTORE_EXTRACT, $out['error_code'] );
		$this->assertSame( 'mounted', file_get_contents( $this->dir . '/mount/file.txt' ) );
	}


	/**
	 * A filesystem-root extraction must not loop forever looking for linked ancestors.
	 */
	public function test_linked_ancestor_check_terminates_at_the_filesystem_root() {
		$method = new \ReflectionMethod( MainWP_Child_Backup_File_Restorer::class, 'has_linked_ancestor' );
		$method->setAccessible( true );
		$restorer = new MainWP_Child_Backup_File_Restorer();
		$this->assertFalse( $method->invoke( $restorer, '/root-level.txt', '/' ) );
		$this->assertFalse( $method->invoke( $restorer, '/a/b/leaf.txt', '/' ) );
		$this->assertFalse( $method->invoke( $restorer, $this->extract_to . '/a/leaf.txt', $this->extract_to . '/' ) );
	}

}
