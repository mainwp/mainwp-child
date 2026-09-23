<?php
/**
 * Clone extraction preserves destination root rewrite rules.
 *
 * @package Mainwp_Child
 */

use MainWP\Child\MainWP_Clone_Install;

class MainWP_Clone_Install_Test extends WP_UnitTestCase {

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

}
