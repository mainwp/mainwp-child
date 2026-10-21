<?php
/**
 * Clone extraction preserves destination root rewrite rules.
 *
 * @package Mainwp_Child
 */

use MainWP\Child\MainWP_Clone_Install;
use MainWP\Child\Tar_Archiver;

require_once __DIR__ . "/includes/trait-tar-entry-fixture.php";

class MainWP_Clone_Install_Test extends WP_UnitTestCase {
	use Tar_Entry_Fixture;

	public static function data_destination_htaccess() {
		return array(
			'existing' => array( "RewriteBase /destination/\n", false ),
			'empty' => array( '', false ),
			'absent' => array( null, false ),
			'existing on failure' => array( "RewriteBase /destination/\n", true ),
			'absent on failure' => array( null, true ),
		);
	}

	/**
	 * @dataProvider data_destination_htaccess
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_clone_extraction_preserves_destination_root_htaccess( $original, $fail ) {
		$root = sys_get_temp_dir() . '/mainwp-clone-htaccess-' . uniqid() . '/';
		mkdir( $root );
		define( 'MainWP\\Child\\ABSPATH', $root );
		$archive = $root . 'archive.tar';
		file_put_contents( $archive, 'fixture' );
		if ( null !== $original ) {
			file_put_contents( $root . '.htaccess', $original );
		}
		$clone = new class( $archive, $fail ) extends MainWP_Clone_Install {
			public function __construct( $archive, $fail ) {
				$this->file = $archive;
				$this->archiver = new class( $fail ) {
					private $fail;
					public function __construct( $fail ) {
						$this->fail = $fail;
					}
					public function is_open() {
						return true;
					}
					public function extract_to( $root ) {
						file_put_contents( $root . '.htaccess', 'source root rules' );
						mkdir( $root . 'nested' );
						file_put_contents( $root . 'nested/.htaccess', 'source nested rules' );
						if ( $this->fail ) {
							throw new RuntimeException( 'Extraction failed after writing files.' );
						}
						return true;
					}
				};
			}
		};
		try {
			$error = null;
			try {
				$this->assertTrue( $clone->extract_backup() );
			} catch ( RuntimeException $exception ) {
				$error = $exception->getMessage();
			}
			$this->assertSame( $fail ? 'Extraction failed after writing files.' : null, $error );
			if ( null === $original ) {
				$this->assertFileDoesNotExist( $root . '.htaccess' );
			} else {
				$this->assertSame( $original, file_get_contents( $root . '.htaccess' ) );
			}
			$this->assertSame( 'source nested rules', file_get_contents( $root . 'nested/.htaccess' ) );
		} finally {
			foreach ( array( '.htaccess', 'nested/.htaccess', 'archive.tar' ) as $file ) {
				if ( file_exists( $root . $file ) ) {
					unlink( $root . $file );
				}
			}
			if ( is_dir( $root . 'nested' ) ) {
				rmdir( $root . 'nested' );
			}
			rmdir( $root );
		}
	}
	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_clone_unchanged_read_only_htaccess_is_not_rewritten() {
		$root = sys_get_temp_dir() . '/mainwp-clone-readonly-' . uniqid() . '/';
		mkdir( $root );
		define( 'MainWP\\Child\\ABSPATH', $root );
		file_put_contents( $root . 'archive.tar', 'fixture' );
		file_put_contents( $root . '.htaccess', 'destination rules' );
		$mtime = time() - 3600;
		touch( $root . '.htaccess', $mtime );
		chmod( $root . '.htaccess', 0444 );
		$clone = new class( $root . 'archive.tar' ) extends MainWP_Clone_Install {
			public function __construct( $archive ) {
				$this->file = $archive;
				$this->archiver = new class {
					public function is_open() { return true; }
					public function extract_to( $root ) { return true; }
				};
			}
		};
		try {
			$this->assertTrue( $clone->extract_backup() );
			clearstatcache( true, $root . '.htaccess' );
			$this->assertSame( 'destination rules', file_get_contents( $root . '.htaccess' ) );
			$this->assertSame( $mtime, filemtime( $root . '.htaccess' ), 'An unchanged file must not be rewritten, even by a privileged test user.' );
		} finally {
			chmod( $root . '.htaccess', 0644 );
			unlink( $root . '.htaccess' );
			unlink( $root . 'archive.tar' );
			rmdir( $root );
		}
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_clone_cleanup_failure_does_not_mask_extraction_exception() {
		$root = sys_get_temp_dir() . '/mainwp-clone-failure-' . uniqid() . '/';
		mkdir( $root );
		define( 'MainWP\\Child\\ABSPATH', $root );
		file_put_contents( $root . 'archive.tar', 'fixture' );
		file_put_contents( $root . '.htaccess', 'destination rules' );
		$failure = new RuntimeException( 'Extraction failed.' );
		$clone = new class( $root . 'archive.tar', $failure ) extends MainWP_Clone_Install {
			public function __construct( $archive, $failure ) {
				$this->file = $archive;
				$this->archiver = new class( $failure ) {
					private $failure;
					public function __construct( $failure ) { $this->failure = $failure; }
					public function is_open() { return true; }
					public function extract_to( $root ) {
						unlink( $root . '.htaccess' );
						mkdir( $root . '.htaccess' );
						throw $this->failure;
					}
				};
			}
		};
		$warnings = array();
		set_error_handler( static function ( $severity, $message ) use ( &$warnings ) {
			$warnings[] = $message;
			return true;
		} );
		// A separate-process test fails on any stderr output, so route error_log() to a file.
		$log          = $root . 'error.log';
		$previous_log = ini_set( 'error_log', $log );
		try {
			$caught = null;
			try {
				$clone->extract_backup();
			} catch ( Throwable $error ) {
				$caught = $error;
			}
			$this->assertSame( $failure, $caught );
			$this->assertNotEmpty( $warnings, 'The fixture must make cleanup fail.' );
			$this->assertStringContainsString( '.htaccess', (string) file_get_contents( $log ) );
		} finally {
			ini_set( 'error_log', (string) $previous_log );
			if ( file_exists( $log ) ) {
				unlink( $log );
			}
			restore_error_handler();
			rmdir( $root . '.htaccess' );
			unlink( $root . 'archive.tar' );
			rmdir( $root );
		}
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_clone_zip_removes_normalized_config_names() {
		$root = sys_get_temp_dir() . '/mainwp-clone-config-' . uniqid() . '/';
		mkdir( $root );
		$path = $root . 'archive.zip';
		$zip = new ZipArchive();
		$this->assertTrue( $zip->open( $path, ZipArchive::CREATE ) );
		foreach ( array( 'wp-config.php', './wp-config.php', './/wp-config.php', 'nested/wp-config.php' ) as $name ) {
			$zip->addFromString( $name, 'source config' );
		}
		foreach ( array( 'clone', './clone', './/clone' ) as $name ) {
			$zip->addEmptyDir( $name );
		}
		$zip->close();
		$clone = new class( $path ) extends MainWP_Clone_Install {
			public function __construct( $file ) { $this->file = $file; }
		};
		try {
			$this->assertTrue( $clone->remove_config_file() );
			$this->assertTrue( $zip->open( $path ) );
			try {
				$this->assertSame( 1, $zip->numFiles );
				$this->assertSame( 'nested/wp-config.php', $zip->getNameIndex( 0 ) );
			} finally {
				$zip->close();
			}
		} finally {
			unlink( $path );
			rmdir( $root );
		}
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_clone_zip_with_only_config_entries_succeeds_without_writing() {
		$root = sys_get_temp_dir() . '/mainwp-clone-empty-' . uniqid() . '/';
		mkdir( $root . 'site', 0777, true );
		define( 'MainWP\\Child\\ABSPATH', $root . 'site/' );
		$path = $root . 'archive.zip';
		$zip = new ZipArchive();
		$this->assertTrue( $zip->open( $path, ZipArchive::CREATE ) );
		$zip->addFromString( './wp-config.php', 'source config' );
		$zip->close();
		$clone = new class( $path ) extends MainWP_Clone_Install {
			public function __construct( $file ) { $this->file = $file; }
		};
		try {
			$this->assertTrue( $clone->extract_zip_backup() );
			$this->assertSame( array( '.', '..' ), scandir( $root . 'site' ) );
		} finally {
			$iterator = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $root, FilesystemIterator::SKIP_DOTS ), RecursiveIteratorIterator::CHILD_FIRST );
			foreach ( $iterator as $file ) {
				$file->isLink() || ! $file->isDir() ? unlink( $file->getPathname() ) : rmdir( $file->getPathname() );
			}
			rmdir( $root );
		}
	}

	public static function data_clone_unzip_preflight() {
		return array( 'protected entries' => array( 'protected' ), 'readers disagree' => array( 'unicode path' ), 'rewrite fails' => array( 'read only' ) );
	}

	/**
	 * PclZip preflight must reject unsafe raw names before WordPress can write them.
	 *
	 * @dataProvider data_clone_unzip_preflight
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_clone_unzip_preflight_removes_protected_entries( $case ) {
		if ( 'read only' === $case && function_exists( 'posix_geteuid' ) && 0 === posix_geteuid() ) {
			$this->markTestSkipped( 'Root can write a read-only directory.' );
		}
		$root = sys_get_temp_dir() . '/mainwp-clone-preflight-' . uniqid() . '/';
		mkdir( $root . 'site', 0777, true );
		mkdir( $root . 'outside' );
		mkdir( $root . 'download' );
		mkdir( $root . 'scratch' );
		if ( ! defined( 'PCLZIP_TEMPORARY_DIR' ) ) { define( 'PCLZIP_TEMPORARY_DIR', $root . 'scratch/' ); }
		if ( ! class_exists( 'PclZip', false ) ) { require_once ABSPATH . 'wp-admin/includes/class-pclzip.php'; }
		define( 'MainWP\\Child\\ABSPATH', $root . 'site/' );
		file_put_contents( $root . 'outside/secret.txt', 'original' );
		symlink( $root . 'outside/secret.txt', $root . 'site/linked.txt' );
		symlink( $root . 'outside', $root . 'site/linked' );
		$path = $root . 'download/archive.zip';
		if ( 'unicode path' === $case ) {
			// ZipArchive reads the Unicode path extra field (class/payload.php); PclZip reads the raw name.
			$raw     = 'linked/payload.php';
			$unicode = 'class/payload.php';
			$data    = 'payload';
			$extra   = pack( 'vvCV', 0x7075, 5 + strlen( $unicode ), 1, crc32( $raw ) ) . $unicode;
			$local   = pack( 'VvvvvvVVVvv', 0x04034b50, 20, 0, 0, 0, 0, crc32( $data ), strlen( $data ), strlen( $data ), strlen( $raw ), strlen( $extra ) ) . $raw . $extra . $data;
			$central = pack( 'VvvvvvvVVVvvvvvVV', 0x02014b50, 20, 20, 0, 0, 0, 0, crc32( $data ), strlen( $data ), strlen( $data ), strlen( $raw ), strlen( $extra ), 0, 0, 0, 0, 0 ) . $raw . $extra;
			file_put_contents( $path, $local . $central . pack( 'VvvvvVVv', 0x06054b50, 0, 0, 1, 1, strlen( $central ), strlen( $local ), 0 ) );
			$zip = new ZipArchive();
			$this->assertTrue( $zip->open( $path ) );
			$this->assertSame( $unicode, $zip->getNameIndex( 0 ) );
			$zip->close();
		} else {
			$zip = new ZipArchive();
			$this->assertTrue( $zip->open( $path, ZipArchive::CREATE ) );
			$zip->addFromString( 'linked.txt', 'archive content' );
			$zip->addFromString( './wp-config.php', 'source config' );
			$zip->addFromString( 'after.txt', 'later entry' );
			$zip->close();
		}
		$clone = new class( $path ) extends MainWP_Clone_Install {
			public function __construct( $file ) { $this->file = $file; }
		};
		$preflight = new ReflectionMethod( MainWP_Clone_Install::class, 'drop_protected_zip_entries' );
		$preflight->setAccessible( true );
		if ( 'read only' === $case ) {
			chmod( $path, 0444 );
			chmod( $root . 'download', 0555 );
		}
		try {
			$error = null;
			try {
				$preflight->invoke( $clone );
			} catch ( Throwable $caught ) {
				$error = $caught;
			}
			if ( 'protected' === $case ) {
				$this->assertNull( $error, $error ? $error->getMessage() : '' );
				$zip = new ZipArchive();
				$this->assertTrue( $zip->open( $path ) );
				$names = array();
				for ( $index = 0; $index < $zip->numFiles; ++$index ) {
					$names[] = $zip->getNameIndex( $index );
				}
				$zip->close();
				$this->assertSame( array( 'after.txt' ), $names );
			} else {
				$this->assertInstanceOf( Exception::class, $error );
			}
			$this->assertSame( array( '.', '..', 'secret.txt' ), scandir( $root . 'outside' ) );
		} finally {
			chmod( $root . 'download', 0755 );
			if ( file_exists( $path ) ) { chmod( $path, 0644 ); }
			$iterator = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $root, FilesystemIterator::SKIP_DOTS ), RecursiveIteratorIterator::CHILD_FIRST );
			foreach ( $iterator as $file ) {
				$file->isLink() || ! $file->isDir() ? unlink( $file->getPathname() ) : rmdir( $file->getPathname() );
			}
			rmdir( $root );
		}
	}


	public static function data_clone_entry_matrix() {
		$rows = array(
			1 => array( array( 'a/b.txt', 'X', 'existing parent' ) ),
			2 => array( array( 'new/dir/f.txt', 'X', 'missing' ), array( 'new/./dir/f.txt', 'X', 'missing' ), array( 'new//dir/f.txt', 'X', 'missing' ) ),
			3 => array( array( 'locked/new/f.txt', 'X', 'remote locked' ), array( 'locked/new/f.txt', 'X', 'remote locked large' ) ),
			4 => array( array( 'locked/new/f.txt', 'R', 'direct locked' ) ),
			5 => array( array( 'new/dir/f.txt', 'X', 'umask' ) ),
			6 => array_map( static function ( $name ) { return array( $name, 'R', 'missing' ); }, array( '../x', 'a/../../x', 'a/../x', 'a\\..\\x', '.. /x', '.../x', '. ./x' ) ),
			7 => array_map( static function ( $name ) { return array( $name, 'R', 'missing' ); }, array( '/x', '\\x', 'C:\\x', "bad\0name" ) ),
			8 => array_map( static function ( $name ) { return array( $name, 'S', 'config' ); }, array( 'wp-config.php', './wp-config.php', './/wp-config.php' ) ),
			9 => array_map( static function ( $name ) { return array( $name, 'S', 'config' ); }, array( '.\\wp-config.php', 'WP-CONFIG.PHP', 'wp-config.php.', 'wp-config.php ', 'wp-config.php::$DATA' ) ),
			10 => array( array( 'self/wp-config.php', 'S', 'self' ) ),
			11 => array( array( 'alias.php', 'S', 'hardlink' ) ),
			12 => array( array( 'sub/wp-config.php', 'X', 'missing' ) ),
			13 => array( array( 'leaf.txt', 'S', 'leaf outside' ), array( 'leaf.txt', 'S', 'leaf inside' ) ),
			14 => array( array( 'linked/', 'S', 'linked outside' ) ),
			15 => array( array( 'linked/f.txt', 'X', 'linked inside' ) ),
			16 => array( array( 'linked/f.txt', 'R', 'linked outside' ) ),
			17 => array( array( 'linked/f.txt', 'R', 'dangling' ) ),
			18 => array( array( 'new/f.txt', 'X', 'root link' ) ),
			19 => array( array( 'safe./wp-config.php', 'X', 'missing' ), array( 'safe./linked/p.php', 'X', 'decoy link' ), array( 'safe./linked/p.php', 'R', 'dot link' ) ),
			20 => array( array( "cafe\u{0301}/f.txt", 'X', 'missing' ), array( "wp-conf\u{0456}g.php", 'X', 'missing' ), array( 'up loads/My Photo.jpg', 'X', 'missing' ) ),
			21 => array( array( 'conflict/', 'R', 'file conflict' ), array( 'conflict', 'R', 'dir conflict' ), array( 'conflict/f.txt', 'R', 'file conflict' ) ),
			22 => array( array( '../late.txt', 'R', 'missing' ) ),
			23 => array( array( 'member.txt', 'X', 'many' ) ),
			24 => array( array( 'member.txt', 'R', 'corrupt' ) ),
			25 => array( array( 'linked/f.txt', 'R', 'unicode outside' ), array( 'wp-config.php', 'S', 'unicode config' ), array( 'safe/name.txt', 'X', 'unicode protected' ) ),
			26 => array_map( static function ( $type ) { return array( 'metadata', 'S', 'tar type ' . $type ); }, array( '1', '2', 'x', 'g', '7' ) ),
			27 => array( array( 'new/f.txt', 'X', 'bz2' ) ),
			28 => array( array( 'locked/new/f.txt', 'R', 'direct locked' ) ),
			29 => array( array( 'clash/f.txt', 'R', 'archive conflict' ) ),
			30 => array( array( 'changed/f.txt', 'R', 'swap parent' ) ),
		);
		foreach ( array( '1', '2', 'x', 'g', '7' ) as $type ) { $rows[26][] = array( 'metadata', 'S', 'tar empty ' . $type ); }
		$rows[26][] = array( 'long/f.txt', 'X', 'long header' );
		$data = array();
		foreach ( $rows as $row => $cases ) {
			foreach ( array( 'T', 'ZD', 'ZF', 'PU', 'PD' ) as $route ) {
				if ( ( 3 === $row && ! in_array( $route, array( 'T', 'ZF', 'PU' ), true ) ) ||
					( in_array( $row, array( 4, 28 ), true ) && 'ZF' === $route ) ||
					( 23 === $row && ! in_array( $route, array( 'ZD', 'ZF' ), true ) ) ||
					( 24 === $row && ! in_array( $route, array( 'ZD', 'ZF' ), true ) ) ||
					( 25 === $row && 'T' === $route ) ||
					( in_array( $row, array( 26, 27 ), true ) && 'T' !== $route ) ||
					( 30 === $row && ! in_array( $route, array( 'T', 'ZF' ), true ) ) ) {
					continue;
				}
				foreach ( $cases as $index => $case ) {
					$data[ sprintf( '%02d %s %s %d', $row, $route, $case[2], $index ) ] = array_merge( array( $row, $route ), $case );
				}
			}
		}
		$routes = array();
		foreach ( $data as $label => $case ) { $routes[ $case[1] ][ $label ] = $case; }
		return array_map( static function ( $cases ) { return array( $cases ); }, $routes );
	}

	/**
	 * @dataProvider data_clone_entry_matrix
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_clone_entry_matrix( $cases ) {
		if ( ! defined( 'FS_CHMOD_DIR' ) ) { define( 'FS_CHMOD_DIR', 0755 ); }
		if ( ! defined( 'FS_CHMOD_FILE' ) ) { define( 'FS_CHMOD_FILE', 0644 ); }
		$base = sys_get_temp_dir() . '/mainwp-matrix-' . uniqid();
		define( 'MainWP\\Child\\ABSPATH', $base . '/site/' );
		$failures = array();
		foreach ( $cases as $label => $case ) {
			try { $this->check_clone_matrix_case( $base, ...$case ); }
			catch ( Throwable $error ) { $failures[] = $label . ': ' . $error->getMessage(); }
		}
		$this->assertSame( array(), $failures );
	}

	private function check_clone_matrix_case( $base, $row, $route, $name, $outcome, $state ) {
		if ( false !== strpos( $state, 'locked' ) && function_exists( 'posix_geteuid' ) && 0 === posix_geteuid() ) {
			return;
		}
		if ( 'bz2' === $state && ! function_exists( 'bzcompress' ) ) {
			return;
		}
		require_once ABSPATH . 'wp-admin/includes/file.php';
		if ( ! defined( 'PCLZIP_TEMPORARY_DIR' ) ) { define( 'PCLZIP_TEMPORARY_DIR', sys_get_temp_dir() . '/' ); }
		require_once ABSPATH . 'wp-admin/includes/class-pclzip.php';
		require_once ABSPATH . 'wp-admin/includes/class-wp-filesystem-base.php';
		require_once ABSPATH . 'wp-admin/includes/class-wp-filesystem-direct.php';
		mkdir( $base . '/site', 0755, true );
		mkdir( $base . '/outside' );
		$root = $base . '/site/';
		if ( 'root link' === $state ) {
			rename( $base . '/site', $base . '/actual' );
			symlink( $base . '/actual', $base . '/site' );
		}
		file_put_contents( $root . 'wp-config.php', 'destination config' );
		file_put_contents( $base . '/outside/keep.txt', 'outside original' );
		if ( 'existing parent' === $state ) { mkdir( $root . 'a', 0711 ); }
		if ( 'self' === $state ) { symlink( $root, $root . 'self' ); }
		if ( 'hardlink' === $state ) { link( $root . 'wp-config.php', $root . 'alias.php' ); }
		if ( 0 === strpos( $state, 'leaf' ) ) {
			file_put_contents( $root . 'inside.txt', 'inside original' );
			symlink( 'leaf inside' === $state ? $root . 'inside.txt' : $base . '/outside/keep.txt', $root . 'leaf.txt' );
		}
		if ( in_array( $state, array( 'linked outside', 'linked inside', 'dangling', 'decoy link', 'unicode outside', 'dot link' ), true ) ) {
			mkdir( $root . 'inside' );
			$target = 'linked inside' === $state ? $root . 'inside' : $base . '/outside';
			if ( 'dangling' === $state ) { $target = $base . '/absent'; }
			if ( 'dot link' === $state ) { mkdir( $root . 'safe.' ); }
			symlink( $target, $root . ( 'dot link' === $state ? 'safe./linked' : 'linked' ) );
		}
		if ( 'file conflict' === $state ) { file_put_contents( $root . 'conflict', 'destination file' ); }
		if ( 'dir conflict' === $state ) { mkdir( $root . 'conflict' ); }
		if ( false !== strpos( $state, 'locked' ) ) { mkdir( $root . 'locked', 0555 ); chmod( $root . 'locked', 0555 ); }
		global $wp_filesystem;
		$previous_fs = $wp_filesystem;
		$remote = 'direct locked' !== $state && ( in_array( $route, array( 'ZF', 'PU' ), true ) || 0 === strpos( $state, 'remote locked' ) || 'swap parent' === $state );
		$wp_filesystem = new class( $remote, $root, $base . '/outside', 'many' === $state, 'swap parent' === $state ) extends WP_Filesystem_Direct {
			public $writes = array();
			public $reader_choices = array();
			private $root;
			private $outside;
			private $record;
			private $swap;
			public function __construct( $remote, $root, $outside, $record, $swap ) {
				parent::__construct( null );
				$this->method = $remote ? 'ftpext' : 'direct';
				$this->root = $root;
				$this->outside = $outside;
				$this->record = $record;
				$this->swap = $swap;
			}
			public function chmod( $path, $mode = false, $recursive = false ) {
				return chmod( $path, $mode );
			}
			public function mkdir( $path, $mode = false, $owner = false, $group = false ) {
				$parent = dirname( rtrim( $path, '/' ) );
				$old = fileperms( $parent ) & 0777;
				if ( 'direct' !== $this->method ) { chmod( $parent, 0755 ); }
				try { return parent::mkdir( $path, $mode, $owner, $group ); }
				finally { chmod( $parent, $old ); }
			}
			public function put_contents( $path, $contents, $mode = false ) {
				$this->reader_choices[] = apply_filters( 'unzip_file_use_ziparchive', true );
				$this->writes[ basename( $path ) ] = array( $contents, $mode );
				if ( $this->swap && 'before.txt' === basename( $path ) ) {
					symlink( $this->outside, $this->root . 'changed' );
				}
				return $this->record ? true : parent::put_contents( $path, $contents, $mode );
			}
		};
		if ( ( 'PU' === $route && in_array( $row, array( 1, 2, 5 ), true ) ) || ( 'T' === $route && ! $remote ) ) { $wp_filesystem = new WP_Filesystem_Direct( null ); }
		$archive = $base . '/archive.' . ( 'T' === $route ? ( 'bz2' === $state ? 'tar.bz2' : 'tar' ) : 'zip' );
		$body = 'remote locked large' === $state ? str_repeat( 'x', 2000001 ) : 'archive content';
		$entries = array( array( 'before.txt', 'before', '0' ), array( $name, $body, '/' === substr( $name, -1 ) ? '5' : '0' ), array( 'after.txt', 'after', '0' ) );
		if ( 22 === $row ) { $entries[0][0] = 'before/dir/before.txt'; }
		if ( 'archive conflict' === $state ) { array_unshift( $entries, array( 'clash', 'first', '0' ) ); }
		if ( 0 === strpos( $state, 'tar type ' ) ) { $entries[1] = array( $name, $this->tar_entry_bytes( '../hidden.txt', 'hidden' ), substr( $state, -1 ) ); }
		if ( 0 === strpos( $state, 'tar empty ' ) ) { $entries[1] = array( $name, '', substr( $state, -1 ) ); }
		if ( 'many' === $state ) {
			$entries = array();
			for ( $i = 0; $i < 65536; ++$i ) { $entries[] = array( 'm' . $i . '.txt', 'body', '0' ); }
		}
		if ( 'T' === $route ) {
			$bytes = '';
			foreach ( $entries as $entry ) { $bytes .= $this->tar_entry_bytes( $entry[0], $entry[1], $entry[2] ); }
			if ( 'long header' === $state ) { $bytes = $this->tar_entry_bytes( '././@LongLink', str_pad( $name . "\0", 700, "\0" ), 'L' ) . $this->tar_entry_bytes( 'placeholder', 'archive content' ) . $this->tar_entry_bytes( 'before.txt', 'before' ) . $this->tar_entry_bytes( 'after.txt', 'after' ); }
			$bytes .= str_repeat( "\0", 1024 );
			if ( 'bz2' === $state ) { $bytes = bzcompress( $bytes ); }
			file_put_contents( $archive, $bytes );
		} else {
			$this->write_clone_zip_fixture( $archive, $entries, 25 === $row ? ( 'unicode protected' === $state ? 'wp-config.php' : 'safe/name.txt' ) : false );
			if ( 'corrupt' === $state ) { file_put_contents( $archive, 'not a zip' ); }
		}
		if ( 25 === $row && in_array( $route, array( 'ZD', 'ZF' ), true ) ) { $outcome = 'unicode protected' === $state ? 'S' : 'X'; $name = 'unicode protected' === $state ? 'wp-config.php' : 'safe/name.txt'; }
		// libzip reads an embedded NUL as a space, so ZipArchive checks and writes that literal name.
		if ( 7 === $row && "bad\0name" === $name && in_array( $route, array( 'ZD', 'ZF' ), true ) ) { $outcome = 'X'; $name = 'bad name'; }
		$clone = new class( $archive, $route ) extends MainWP_Clone_Install {
			private $route;
			public function __construct( $file, $route ) { $this->file = $file; $this->route = $route; }
			public function check_zip_support() { return ! in_array( $this->route, array( 'PU', 'PD' ), true ); }
			public function check_wp_zip() { return 'PD' !== $this->route; }
		};
		$archiver = null;
		$before = $this->clone_destination_snapshot( $root );
		$old_umask = umask( 0077 );
		$warnings = array();
		set_error_handler( static function ( $level, $message ) use ( &$warnings ) { $warnings[] = $message; return true; } );
		$zip_filter = static function () { return true; };
		add_filter( 'unzip_file_use_ziparchive', $zip_filter, PHP_INT_MAX );
		try {
			$caught = null;
			$result = null;
			try {
				if ( 'T' === $route ) {
					$archiver = new Tar_Archiver( null, 'bz2' === $state ? 'tar.bz2' : 'tar' );
					$archiver->read( $archive );
					$result = $archiver->extract_to( $root );
				} elseif ( 'ZD' === $route ) {
					$result = $clone->extract_zip_backup();
				} elseif ( 'ZF' === $route ) {
					$result = $clone->extract_wp_zip_backup();
				} else {
					$result = $clone->extract_backup();
				}
			} catch ( Throwable $error ) { $caught = $error; }
			if ( 'R' === $outcome ) {
				$this->assertTrue( $caught instanceof Exception || false === $result, $state . ' must refuse extraction' );
				if ( ! in_array( $row, array( 4, 24, 28, 29, 30 ), true ) ) {
					$this->assertSame( $before, $this->clone_destination_snapshot( $root ), 'Refusal must preserve files, directories, links and modes.' );
					$this->assertFileDoesNotExist( $root . 'before.txt' );
					$this->assertFileDoesNotExist( $root . 'after.txt' );
				}
			} else {
				$this->assertNull( $caught, $caught ? $caught->getMessage() : '' );
				$this->assertNotSame( false, $result );
				if ( 'many' === $state && 'ZF' === $route ) {
					$this->assertCount( 65536, $wp_filesystem->writes );
					$this->assertSame( array( 'body', FS_CHMOD_FILE ), $wp_filesystem->writes['m65535.txt'] );
				} elseif ( 'many' === $state ) {
					$this->assertSame( 'body', file_get_contents( $root . 'm65535.txt' ) );
				} else {
					$this->assertSame( 'before', file_get_contents( $root . 'before.txt' ) );
					$this->assertSame( 'after', file_get_contents( $root . 'after.txt' ) );
					if ( 'X' === $outcome ) {
						$this->assertSame( $body, file_get_contents( $root . $name ) );
							if ( 3 === $row ) {
								$this->assertSame( 'PU' === $route || strlen( $body ) < 2000000, array_key_exists( 'f.txt', $wp_filesystem->writes ) );
							}
						if ( in_array( $row, array( 1, 2, 3, 5 ), true ) ) {
							clearstatcache();
							$this->assertSame( FS_CHMOD_FILE, fileperms( $root . $name ) & 0777 );
							$this->assertSame( 1 === $row ? 0711 : FS_CHMOD_DIR, fileperms( dirname( $root . $name ) ) & 0777 );
						}
					} elseif ( in_array( $row, array( 9, 26 ), true ) && 'wp-config.php' !== $name ) {
						if ( is_file( $root . $name ) ) { $this->assertSame( 'destination config', file_get_contents( $root . $name ) ); }
						else { $this->assertFileDoesNotExist( $root . $name ); }
					}
				}
			}
			$this->assertSame( 'destination config', file_get_contents( $root . 'wp-config.php' ) );
			$this->assertSame( array( '.', '..', 'keep.txt' ), scandir( $base . '/outside' ) );
			$this->assertSame( 'outside original', file_get_contents( $base . '/outside/keep.txt' ) );
			$this->assertFileDoesNotExist( $base . '/hidden.txt' );
			if ( 13 === $row ) {
				$this->assertTrue( is_link( $root . 'leaf.txt' ) );
				$this->assertSame( 'inside original', file_get_contents( $root . 'inside.txt' ) );
			}
			if ( 14 === $row ) { $this->assertTrue( is_link( $root . 'linked' ) ); }
			if ( 11 === $row ) { $this->assertSame( fileinode( $root . 'wp-config.php' ), fileinode( $root . 'alias.php' ) ); }
			if ( 'PU' === $route && 'unicode protected' === $state ) { $this->assertSame( array( false, false, false ), $wp_filesystem->reader_choices ); }
			if ( 'PU' === $route ) { $this->assertTrue( apply_filters( 'unzip_file_use_ziparchive', true ), 'The reader filter must be restored.' ); }
		} finally {
			remove_filter( 'unzip_file_use_ziparchive', $zip_filter, PHP_INT_MAX );
			restore_error_handler();
			umask( $old_umask );
			if ( $archiver ) { $archiver->close(); }
			$wp_filesystem = $previous_fs;
			if ( is_dir( $root . 'locked' ) ) { chmod( $root . 'locked', 0755 ); }
			$iterator = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $base, FilesystemIterator::SKIP_DOTS ), RecursiveIteratorIterator::CHILD_FIRST );
			foreach ( $iterator as $file ) {
				$file->isLink() || ! $file->isDir() ? unlink( $file->getPathname() ) : rmdir( $file->getPathname() );
			}
			rmdir( $base );
		}
	}

	public static function data_clone_writer_sizes() {
		$cases = array();
		foreach ( array( 'writer', 'tar' ) as $route ) {
			foreach ( array( 1999999, 2000000, 2000001 ) as $size ) {
				$cases[ $route . ' ' . $size ] = array( $route, $size );
			}
		}
		return $cases;
	}

	/** @dataProvider data_clone_writer_sizes */
	public function test_clone_large_members_stream_before_transport( $route, $size ) {
		$root = sys_get_temp_dir() . '/mainwp-clone-stream-' . uniqid();
		mkdir( $root );
		$body = str_repeat( 'x', $size );
		$fs = new class {
			public $method = 'ftpext';
			public $writes = 0;
			public function put_contents( $path, $data, $mode ) {
				++$this->writes;
				return strlen( $data ) === file_put_contents( $path, $data ) && chmod( $path, $mode );
			}
		};
		global $wp_filesystem;
		$previous_fs = $wp_filesystem;
		$wp_filesystem = $fs;
		$archiver = null;
		$stream = null;
		try {
			if ( 'tar' === $route ) {
				file_put_contents( $root . '/archive.tar', $this->tar_entry_bytes( 'payload.txt', $body ) . str_repeat( "\0", 1024 ) );
				$archiver = new Tar_Archiver( null, 'tar' );
				$archiver->read( $root . '/archive.tar' );
				$archiver->extract_to( $root );
			} else {
				$stream = fopen( 'php://temp', 'w+' );
				fwrite( $stream, $body );
				rewind( $stream );
				MainWP_Clone_Install::write_clone_file( $root . '/payload.txt', $stream, $size, $fs );
			}
			$this->assertSame( hash( 'sha256', $body ), hash_file( 'sha256', $root . '/payload.txt' ) );
			$this->assertSame( $size < 2000000 ? 1 : 0, $fs->writes, 'A PHP-writable large member must bypass transport buffering.' );
		} finally {
			if ( $archiver ) { $archiver->close(); }
			if ( $stream ) { fclose( $stream ); }
			$wp_filesystem = $previous_fs;
			foreach ( glob( $root . '/*' ) as $path ) { unlink( $path ); }
			rmdir( $root );
		}
	}

	public function test_clone_transport_refuses_member_larger_than_memory_headroom() {
		if ( function_exists( 'posix_geteuid' ) && 0 === posix_geteuid() ) {
			$this->markTestSkipped( 'Root ignores directory permissions.' );
		}
		$root = sys_get_temp_dir() . '/mainwp-clone-headroom-' . uniqid();
		mkdir( $root . '/locked', 0755, true );
		chmod( $root . '/locked', 0555 );
		$fs = new class {
			public $method = 'ftpext';
			public $writes = 0;
			public function put_contents( $path, $data, $mode ) { ++$this->writes; return true; }
		};
		$previous_limit = ini_get( 'memory_limit' );
		// A limit above current usage always takes effect, and a member of that size never fits.
		$limit = memory_get_usage() + 64 * 1024 * 1024;
		ini_set( 'memory_limit', (string) $limit );
		$stream = fopen( 'php://temp', 'w+' );
		set_error_handler( static function () { return true; }, E_WARNING );
		try {
			$caught = null;
			try { MainWP_Clone_Install::write_clone_file( $root . '/locked/large.bin', $stream, $limit, $fs ); }
			catch ( Exception $error ) { $caught = $error; }
			$this->assertInstanceOf( Exception::class, $caught );
			$this->assertStringContainsString( 'too large', $caught->getMessage() );
			$this->assertSame( 0, $fs->writes );
		} finally {
			restore_error_handler();
			fclose( $stream );
			ini_set( 'memory_limit', $previous_limit );
			chmod( $root . '/locked', 0755 );
			rmdir( $root . '/locked' );
			rmdir( $root );
		}
	}

	public function test_clone_large_member_uses_transport_when_direct_open_fails() {
		if ( function_exists( 'posix_geteuid' ) && 0 === posix_geteuid() ) {
			$this->markTestSkipped( 'Root can write a read-only directory.' );
		}
		$root = sys_get_temp_dir() . '/mainwp-clone-fallback-' . uniqid();
		mkdir( $root );
		chmod( $root, 0555 );
		$fs = new class {
			public $method = 'ftpext';
			public $writes = 0;
			public function put_contents( $path, $data, $mode ) {
				++$this->writes;
				chmod( dirname( $path ), 0755 );
				return strlen( $data ) === file_put_contents( $path, $data ) && chmod( $path, $mode );
			}
		};
		$body = str_repeat( 'x', 2000000 );
		$stream = fopen( 'php://temp', 'w+' );
		fwrite( $stream, $body );
		rewind( $stream );
		set_error_handler( static function ( $level, $message ) { return false !== strpos( $message, 'Permission denied' ); } );
		try {
			MainWP_Clone_Install::write_clone_file( $root . '/payload.txt', $stream, strlen( $body ), $fs );
			$this->assertSame( 1, $fs->writes );
			$this->assertSame( hash( 'sha256', $body ), hash_file( 'sha256', $root . '/payload.txt' ) );
		} finally {
			restore_error_handler();
			fclose( $stream );
			chmod( $root, 0755 );
			if ( file_exists( $root . '/payload.txt' ) ) { unlink( $root . '/payload.txt' ); }
			rmdir( $root );
		}
	}

	public static function data_clone_duplicate_zip_routes() {
		return array( 'direct' => array( false ), 'transport' => array( true ) );
	}

	/**
	 * @dataProvider data_clone_duplicate_zip_routes
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_clone_zip_duplicate_names_refuse_before_writing( $transport ) {
		$root = sys_get_temp_dir() . '/mainwp-clone-duplicate-' . uniqid() . '/';
		mkdir( $root . 'site', 0755, true );
		define( 'MainWP\\Child\\ABSPATH', $root . 'site/' );
		$this->write_clone_zip_fixture( $root . 'archive.zip', array( array( 'before.txt', 'before', '0' ), array( 'same.txt', 'FIRST', '0' ), array( 'same.txt', 'xy', '0' ) ) );
		$clone = new class( $root . 'archive.zip' ) extends MainWP_Clone_Install {
			public function __construct( $file ) { $this->file = $file; }
		};
		$fs = $transport ? new class {
			public $method = 'ftpext';
			public function put_contents( $path, $data, $mode ) { return false !== file_put_contents( $path, $data ); }
		} : null;
		try {
			$caught = null;
			try { $clone->extract_zip_backup( $fs ); }
			catch ( Exception $error ) { $caught = $error; }
			$this->assertSame( array( '.', '..' ), scandir( $root . 'site' ), 'Duplicate names must be refused during validation.' );
			$this->assertInstanceOf( Exception::class, $caught );
		} finally {
			foreach ( glob( $root . 'site/*' ) as $path ) { unlink( $path ); }
			rmdir( $root . 'site' );
			unlink( $root . 'archive.zip' );
			rmdir( $root );
		}
	}

	public static function data_clone_file_chmod_failures() {
		$cases = array();
		foreach ( array( 'writer', 'PD', 'PU' ) as $route ) {
			foreach ( array( true, false ) as $existed ) {
				$cases[ $route . ( $existed ? ' existing' : ' created' ) ] = array( $route, $existed );
			}
		}
		return $cases;
	}

	/**
	 * @dataProvider data_clone_file_chmod_failures
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_clone_file_chmod_failure_depends_on_creation( $route, $existed ) {
		if ( ! defined( 'FS_CHMOD_DIR' ) ) { define( 'FS_CHMOD_DIR', 0755 ); }
		if ( ! defined( 'FS_CHMOD_FILE' ) ) { define( 'FS_CHMOD_FILE', 0644 ); }
		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/class-pclzip.php';
		require_once ABSPATH . 'wp-admin/includes/class-wp-filesystem-base.php';
		require_once ABSPATH . 'wp-admin/includes/class-wp-filesystem-direct.php';
		$root = sys_get_temp_dir() . '/mainwp-clone-chmod-' . uniqid() . '/';
		mkdir( $root . 'site', 0755, true );
		define( 'MainWP\\Child\\ABSPATH', $root . 'site/' );
		// A writable file can belong to another user, making chmod fail after a successful write.
		eval( 'namespace MainWP\\Child; function chmod( $path, $mode ) { return "payload.txt" === basename( $path ) ? false : \\chmod( $path, $mode ); }' );
		if ( $existed ) { file_put_contents( $root . 'site/payload.txt', 'original' ); }
		$this->write_clone_zip_fixture( $root . 'archive.zip', array( array( 'payload.txt', 'replacement', '0' ) ) );
		$clone = new class( $root . 'archive.zip' ) extends MainWP_Clone_Install {
			public function __construct( $file ) { $this->file = $file; }
			public function check_zip_support() { return false; }
		};
		global $wp_filesystem;
		$previous_fs = $wp_filesystem;
		$wp_filesystem = new WP_Filesystem_Direct( null );
		$stream = fopen( 'php://temp', 'w+' );
		fwrite( $stream, 'replacement' );
		rewind( $stream );
		try {
			$caught = null;
			try {
				if ( 'writer' === $route ) { MainWP_Clone_Install::write_clone_file( $root . 'site/payload.txt', $stream, 11 ); }
				elseif ( 'PD' === $route ) { $clone->extract_zip_pcl_backup(); }
				else { $clone->extract_wp_zip_backup(); }
			} catch ( Exception $error ) { $caught = $error; }
			$this->assertSame( 'replacement', file_get_contents( $root . 'site/payload.txt' ) );
			if ( $existed ) { $this->assertNull( $caught, 'An existing writable file need not be owned by PHP.' ); }
			else { $this->assertInstanceOf( Exception::class, $caught, 'A newly created file must receive the requested mode.' ); }
		} finally {
			fclose( $stream );
			$wp_filesystem = $previous_fs;
			if ( file_exists( $root . 'site/payload.txt' ) ) { unlink( $root . 'site/payload.txt' ); }
			rmdir( $root . 'site' );
			unlink( $root . 'archive.zip' );
			rmdir( $root );
		}
	}

	public static function data_clone_pclzip_failed_entries() {
		return array( 'write error' => array( false ), 'inflate error' => array( true ) );
	}

	/**
	 * @dataProvider data_clone_pclzip_failed_entries
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_clone_pclzip_failed_entry_status_refuses_extraction( $corrupt ) {
		if ( ! $corrupt && function_exists( 'posix_geteuid' ) && 0 === posix_geteuid() ) {
			$this->markTestSkipped( 'Root can write a read-only directory.' );
		}
		require_once ABSPATH . 'wp-admin/includes/class-pclzip.php';
		$root = sys_get_temp_dir() . '/mainwp-clone-status-' . uniqid() . '/';
		mkdir( $root . 'site/locked', 0755, true );
		define( 'MainWP\\Child\\ABSPATH', $root . 'site/' );
		$this->write_clone_zip_fixture( $root . 'archive.zip', array( array( 'locked/payload.txt', 'not deflate', '0' ), array( 'after.txt', 'after', '0' ) ) );
		if ( $corrupt ) {
			$bytes = file_get_contents( $root . 'archive.zip' );
			$central = strpos( $bytes, "PK\x01\x02" );
			$bytes = substr_replace( $bytes, pack( 'v', 8 ), $central + 10, 2 );
			file_put_contents( $root . 'archive.zip', substr_replace( $bytes, pack( 'v', 8 ), 8, 2 ) );
		} else { chmod( $root . 'site/locked', 0555 ); }
		$clone = new class( $root . 'archive.zip' ) extends MainWP_Clone_Install {
			public function __construct( $file ) { $this->file = $file; }
		};
		set_error_handler( static function ( $level, $message ) { return false !== strpos( $message, 'Permission denied' ) || false !== strpos( $message, 'gzinflate' ); } );
		try {
			$caught = null;
			try { $clone->extract_zip_pcl_backup(); }
			catch ( Exception $error ) { $caught = $error; }
			$this->assertInstanceOf( Exception::class, $caught, 'A dropped PclZip member must not report success.' );
		} finally {
			restore_error_handler();
			chmod( $root . 'site/locked', 0755 );
			$iterator = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $root, FilesystemIterator::SKIP_DOTS ), RecursiveIteratorIterator::CHILD_FIRST );
			foreach ( $iterator as $file ) { $file->isDir() ? rmdir( $file->getPathname() ) : unlink( $file->getPathname() ); }
			rmdir( $root );
		}
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_clone_unzip_skipped_metadata_needs_no_chmod() {
		if ( ! defined( 'FS_CHMOD_DIR' ) ) { define( 'FS_CHMOD_DIR', 0755 ); }
		if ( ! defined( 'FS_CHMOD_FILE' ) ) { define( 'FS_CHMOD_FILE', 0644 ); }
		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/class-pclzip.php';
		require_once ABSPATH . 'wp-admin/includes/class-wp-filesystem-base.php';
		require_once ABSPATH . 'wp-admin/includes/class-wp-filesystem-direct.php';
		$root = sys_get_temp_dir() . '/mainwp-clone-metadata-' . uniqid() . '/';
		mkdir( $root . 'site', 0755, true );
		define( 'MainWP\\Child\\ABSPATH', $root . 'site/' );
		$this->write_clone_zip_fixture( $root . 'archive.zip', array( array( '__MACOSX/._payload.txt', 'metadata', '0' ), array( 'payload.txt', 'contents', '0' ) ) );
		$clone = new class( $root . 'archive.zip' ) extends MainWP_Clone_Install {
			public function __construct( $file ) { $this->file = $file; }
			public function check_zip_support() { return false; }
		};
		global $wp_filesystem;
		$previous_fs = $wp_filesystem;
		$wp_filesystem = new WP_Filesystem_Direct( null );
		set_error_handler( static function ( $level, $message ) { return false !== strpos( $message, 'chmod' ); } );
		try {
			$caught = null;
			$result = null;
			try { $result = $clone->extract_wp_zip_backup(); }
			catch ( Exception $error ) { $caught = $error; }
			$this->assertNull( $caught, 'WordPress intentionally omits macOS metadata.' );
			$this->assertTrue( $result );
			$this->assertSame( 'contents', file_get_contents( $root . 'site/payload.txt' ) );
			$this->assertFileDoesNotExist( $root . 'site/__MACOSX/._payload.txt' );
		} finally {
			restore_error_handler();
			$wp_filesystem = $previous_fs;
			$iterator = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $root, FilesystemIterator::SKIP_DOTS ), RecursiveIteratorIterator::CHILD_FIRST );
			foreach ( $iterator as $file ) { $file->isDir() ? rmdir( $file->getPathname() ) : unlink( $file->getPathname() ); }
			rmdir( $root );
		}
	}

	private function clone_destination_snapshot( $root ) {
		clearstatcache( true );
		$entries = array();
		$iterator = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $root, FilesystemIterator::SKIP_DOTS ), RecursiveIteratorIterator::SELF_FIRST );
		foreach ( $iterator as $entry ) {
			$path = $entry->getPathname();
			$name = substr( $path, strlen( $root ) );
			$entries[ $name ] = $entry->isLink() ? array( 'link', readlink( $path ) ) : array( $entry->isDir() ? 'dir' : file_get_contents( $path ), fileperms( $path ) & 0777 );
		}
		ksort( $entries );
		return $entries;
	}

	private function write_clone_zip_fixture( $path, $entries, $unicode = false ) {
		if ( count( $entries ) > 65535 ) {
			$zip = new ZipArchive();
			$this->assertTrue( $zip->open( $path, ZipArchive::CREATE ) );
			foreach ( $entries as $entry ) { $zip->addFromString( $entry[0], $entry[1] ); }
			$this->assertTrue( $zip->close() );
			return;
		}
		$local = '';
		$central = '';
		foreach ( $entries as $entry ) {
			list( $name, $data, $type ) = $entry;
			if ( '5' === $type ) { $data = ''; }
			$extra = $unicode && ! in_array( $name, array( 'before.txt', 'after.txt' ), true ) ? pack( 'vvCV', 0x7075, 5 + strlen( $unicode ), 1, crc32( $name ) ) . $unicode : '';
			$offset = strlen( $local );
			$local .= pack( 'VvvvvvVVVvv', 0x04034b50, 20, 0, 0, 0, 0, crc32( $data ), strlen( $data ), strlen( $data ), strlen( $name ), strlen( $extra ) ) . $name . $extra . $data;
			$central .= pack( 'VvvvvvvVVVvvvvvVV', 0x02014b50, 20, 20, 0, 0, 0, 0, crc32( $data ), strlen( $data ), strlen( $data ), strlen( $name ), strlen( $extra ), 0, 0, 0, '5' === $type ? 16 : 0, $offset ) . $name . $extra;
		}
		file_put_contents( $path, $local . $central . pack( 'VvvvvVVv', 0x06054b50, 0, 0, count( $entries ), count( $entries ), strlen( $central ), strlen( $local ), 0 ) );
	}

}
