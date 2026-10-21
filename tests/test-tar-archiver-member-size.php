<?php
/**
 * Tar member sizes must preserve the next header's offset.
 *
 * @package Mainwp_Child
 */

use MainWP\Child\MainWP_Backup;
use MainWP\Child\MainWP_Backup_Error;
use MainWP\Child\MainWP_Child_Backup_File_Restorer;
use MainWP\Child\MainWP_Exception;
use MainWP\Child\MainWP_Helper;
use MainWP\Child\Tar_Archiver;

class Changing_Source_Member_Size_Tar_Archiver extends Tar_Archiver {

	public $source_path;
	public $change;
	private $changed = false;

	protected function read_source_data( $handle, $length ) {
		if ( ! $this->changed ) {
			$this->changed = true;
			if ( 'grow' === $this->change ) {
				file_put_contents( $this->source_path, str_repeat( 'g', 2000 ), FILE_APPEND );
			} else {
				$writer = fopen( $this->source_path, 'r+b' );
				try {
					ftruncate( $writer, 10 );
				} finally {
					fclose( $writer );
				}
			}
		}
		return parent::read_source_data( $handle, $length );
	}
}

class TarArchiverMemberSizeTest extends WP_UnitTestCase {

	private $fixture;
	private $scratch;
	private $backup;
	private $sparse_paths = array();

	public function set_up() {
		parent::set_up();
		$this->fixture = ABSPATH . 'mwp-size-' . uniqid();
		$this->scratch = sys_get_temp_dir() . '/mwp-size-' . uniqid();
		mkdir( $this->fixture );
		mkdir( $this->scratch );
		$this->backup = new class() extends MainWP_Backup {
			public function extend_archive_mutex_lease() {}
			public function get_or_create_db_dump_files( $prefix, &$archiver = null ) {
				return array();
			}
		};
		MainWP_Backup::reset_skipped_paths();
		$this->backup->reset_failed_adds();
		$this->backup->set_failed_add_skips( array() );
	}

	public function tear_down() {
		foreach ( $this->sparse_paths as $path ) {
			if ( file_exists( $path ) ) {
				unlink( $path );
			}
		}
		MainWP_Helper::delete_dir( $this->fixture );
		MainWP_Helper::delete_dir( $this->scratch );
		MainWP_Backup::reset_skipped_paths();
		$this->backup->reset_failed_adds();
		$this->backup->set_failed_add_skips( array() );
		parent::tear_down();
	}

	public static function archive_types() {
		return array(
			'tar'    => array( 'tar' ),
			'tar.gz' => array( 'tar.gz' ),
		);
	}

	private function make_sparse( $path ) {
		$this->sparse_paths[] = $path;
		$handle = fopen( $path, 'c+b' );
		$this->assertIsResource( $handle );
		try {
			$this->assertTrue( ftruncate( $handle, 4294966785 ) );
		} finally {
			fclose( $handle );
		}
		clearstatcache( true, $path );
		$this->assertSame( 4294966785, filesize( $path ) );
	}

	private function run_pass( $path, $type = 'tar', $append = false ) {
		$excludes = array( '.htaccess' );
		foreach ( glob( ABSPATH . '*' ) as $node ) {
			if ( $node !== $this->fixture ) {
				$excludes[] = str_replace( ABSPATH, '', $node );
			}
		}
		$archiver = new Tar_Archiver( $this->backup, $type );
		try {
			$this->assertTrue( $archiver->create_full_backup( $path, $excludes, false, true, false, false, $append ) );
		} finally {
			$archiver->close();
		}
	}

	private function assert_members( $path, $type = 'tar' ) {
		if ( 'tar.gz' === $type ) {
			$stream = gzopen( $path, 'rb' );
			try {
				$bytes = stream_get_contents( $stream );
			} finally {
				gzclose( $stream );
			}
			$path = $this->scratch . '/decoded.tar';
			file_put_contents( $path, $bytes );
		}
		$walk = new ReflectionMethod( MainWP_Child_Backup_File_Restorer::class, 'tar_header_members' );
		$walk->setAccessible( true );
		$members = $walk->invoke( new MainWP_Child_Backup_File_Restorer(), $path );
		$this->assertIsArray( $members, is_string( $members ) ? $members : '' );
		return $members['members'];
	}

	private function contents( $path, $entry ) {
		$archive = new PharData( $path );
		return $archive[ $entry ]->getContent();
	}

	public function test_oversized_source_is_omitted_and_recorded() {
		$big  = $this->fixture . '/big.bin';
		$path = $this->scratch . '/backup.tar';
		file_put_contents( $this->fixture . '/normal.txt', 'normal bytes' );
		try {
			$this->make_sparse( $big );
			$reasons = array();
			$record  = static function ( $path, $reason ) use ( &$reasons ) {
				$reasons[ $path ] = $reason;
			};
			add_action( 'mainwp_child_backup_skipped_path', $record, 10, 2 );
			try {
				$this->run_pass( $path );
			} finally {
				remove_action( 'mainwp_child_backup_skipped_path', $record, 10 );
			}
			$members = $this->assert_members( $path );
			$prefix  = basename( $this->fixture ) . '/';
			$this->assertSame( 1, $members[ $prefix . 'normal.txt' ] );
			$this->assertArrayNotHasKey( $prefix . 'big.bin', $members );
			$this->assertSame( 'normal bytes', $this->contents( $path, $prefix . 'normal.txt' ) );
			$this->assertContains( $big, MainWP_Backup::get_skipped_paths() );
			$this->assertContains( $big, $this->backup->get_failed_add_paths() );
			$this->assertSame( 'too_large', $reasons[ $big ] );
		} finally {
			if ( file_exists( $big ) ) {
				unlink( $big );
			}
		}
	}

	public function test_zip_file_fails_for_an_oversized_source() {
		$big      = $this->fixture . '/dump.sql';
		$archiver = new Tar_Archiver( $this->backup, 'tar' );
		try {
			$this->make_sparse( $big );
			try {
				$archiver->zip_file( array( $big ), $this->scratch . '/dump.tar' );
				$this->fail( 'A required source must fail instead of being omitted.' );
			} catch ( MainWP_Exception $e ) {
				$this->assertSame( MainWP_Backup_Error::ERR_TAR_WRITE_FAILED, $e->get_message_error_code() );
			}
		} finally {
			try {
				$archiver->close();
			} finally {
				if ( file_exists( $big ) ) {
					unlink( $big );
				}
			}
		}
	}

	/** @dataProvider archive_types */
	public function test_growth_preserves_declared_content_and_alignment( $type ) {
		$this->assert_changing_source( $type, 'grow' );
	}

	/** @dataProvider archive_types */
	public function test_shrink_fills_declared_size_and_reports_change( $type ) {
		$this->assert_changing_source( $type, 'shrink' );
	}

	private function assert_changing_source( $type, $change ) {
		$source   = $this->fixture . '/changing.txt';
		$content  = str_repeat( 'original-', 100 );
		$path     = $this->scratch . '/changing.' . $type;
		$archiver = new Changing_Source_Member_Size_Tar_Archiver( $this->backup, $type );
		$archiver->source_path = $source;
		$archiver->change      = $change;
		file_put_contents( $source, $content );
		file_put_contents( $this->fixture . '/after.txt', 'next member' );
		$reasons = array();
		$record  = static function ( $path, $reason ) use ( &$reasons ) {
			$reasons[ $path ] = $reason;
		};
		add_action( 'mainwp_child_backup_skipped_path', $record, 10, 2 );
		try {
			$this->assertTrue( $archiver->zip_file( array( $source, $this->fixture . '/after.txt' ), $path ) );
		} finally {
			try {
				$archiver->close();
			} finally {
				remove_action( 'mainwp_child_backup_skipped_path', $record, 10 );
			}
		}
		$this->assertSame( array( 'changing.txt' => 1, 'after.txt' => 1 ), $this->assert_members( $path, $type ) );
		$this->assertSame( 'next member', $this->contents( $path, 'after.txt' ) );
		if ( 'grow' === $change ) {
			$this->assertSame( $content, $this->contents( $path, 'changing.txt' ) );
			$this->assertSame( array(), MainWP_Backup::get_skipped_paths() );
			$this->assertSame( array(), $reasons );
		} else {
			$this->assertSame( substr( $content, 0, 10 ) . str_repeat( "\0", strlen( $content ) - 10 ), $this->contents( $path, 'changing.txt' ) );
			$this->assertSame( array( $source ), MainWP_Backup::get_skipped_paths() );
			$this->assertSame( 'changed', $reasons[ $source ] );
		}
		$this->assertSame( array(), $this->backup->get_failed_add_paths(), 'A written member must still be matched on append.' );
	}

	/** @dataProvider archive_types */
	public function test_append_matches_members_after_an_oversized_skip( $type ) {
		$big    = $this->fixture . '/big.bin';
		$normal = $this->fixture . '/normal.txt';
		$path   = $this->scratch . '/append.' . $type;
		file_put_contents( $normal, 'already archived' );
		file_put_contents( $this->fixture . '/after.txt', 'after the skip' );
		try {
			$this->make_sparse( $big );
			$this->run_pass( $path, $type );
			$members = $this->assert_members( $path, $type );
			$this->backup->set_failed_add_skips( $this->backup->get_failed_add_paths() );
			$this->backup->reset_failed_adds();
			MainWP_Backup::reset_skipped_paths();
			// A previously archived source must still match even after it outgrows the limit.
			$this->make_sparse( $normal );
			file_put_contents( $big, 'now small enough' );
			$this->run_pass( $path, $type, true );
			$this->assertSame( $members, $this->assert_members( $path, $type ) );
			$prefix = basename( $this->fixture ) . '/';
			$this->assertSame( 'already archived', $this->contents( $path, $prefix . 'normal.txt' ) );
			$this->assertSame( 'after the skip', $this->contents( $path, $prefix . 'after.txt' ) );
			$this->assertSame( array(), $this->backup->get_failed_add_paths() );
			$this->assertSame( array(), MainWP_Backup::get_skipped_paths() );
		} finally {
			foreach ( array( $big, $normal ) as $file ) {
				if ( file_exists( $file ) ) {
					unlink( $file );
				}
			}
		}
	}
}
