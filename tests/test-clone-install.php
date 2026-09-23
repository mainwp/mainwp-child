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
}
