<?php
/**
 * TAR continuation under changing directory membership.
 *
 * @package Mainwp_Child
 */

use MainWP\Child\MainWP_Backup_Error;
use MainWP\Child\MainWP_Exception;
use MainWP\Child\Tar_Archiver;

require_once __DIR__ . '/includes/trait-tar-entry-fixture.php';

class TarArchiverChurnTest extends WP_UnitTestCase {

	use Tar_Entry_Fixture;

	private $scratch;

	public function set_up() {
		parent::set_up();
		$uploads = wp_upload_dir();
		$this->scratch = $uploads['basedir'] . '/mwp-tar-churn-' . uniqid();
		mkdir( $this->scratch );
	}

	public function tear_down() {
		MainWP\Child\MainWP_Helper::delete_dir( $this->scratch );
		parent::tear_down();
	}

	public function archive_types() {
		return array( array( 'tar' ), array( 'tar.gz' ) );
	}

	/** @dataProvider archive_types */
	public function test_reordered_whitespace_names_keep_the_captured_copy( $type ) {
		foreach ( array( 'dir/a ', ' a', "dir/a\t" ) as $name ) {
			$bytes = $this->tar_entry_bytes( $name, 'captured' ) . $this->tar_entry_bytes( 'dir/b', 'other' );
			list( $archiver, $path ) = $this->resume( $bytes, $type );
			try {
				$this->invoke( $archiver, 'add_file_from_string', 'dir/b', 'changed other' );
				$this->invoke( $archiver, 'add_file_from_string', $name, 'changed captured' );
			} finally {
				$archiver->close();
			}
			$raw = file_get_contents( 'tar' === $type ? $path : 'compress.zlib://' . $path );
			$this->assertSame( $bytes, $raw, 'Reordered whitespace names must not append a duplicate or replace captured contents.' );
		}
	}

	private function invoke( $archiver, $method, ...$args ) {
		$reflection = new ReflectionMethod( $archiver, $method );
		$reflection->setAccessible( true );
		return $reflection->invokeArgs( $archiver, $args );
	}

	private function resume( $bytes, $type ) {
		$path = $this->scratch . '/backup.' . $type;
		file_put_contents( $path, 'tar' === $type ? $bytes : gzencode( $bytes ) );
		$archiver = new Tar_Archiver( null, $type );
		$archive_path = new ReflectionProperty( $archiver, 'archivePath' );
		$archive_path->setAccessible( true );
		$archive_path->setValue( $archiver, $path );
		$mode = new ReflectionProperty( $archiver, 'mode' );
		$mode->setAccessible( true );
		$mode->setValue( $archiver, Tar_Archiver::APPEND );
		$archiver->prepare_append( $path );
		return array( $archiver, $path );
	}

	/** @dataProvider archive_types */
	public function test_churn_retains_archived_members_and_adds_new_files_once( $type ) {
		$base = 'wp-content/cache/zz-churn-test/';
		$long = $base . str_repeat( 'long-', 25 ) . '.txt';
		$bytes = $this->tar_entry_bytes( $base . 'deleted.txt', 'captured before deletion' )
			. $this->tar_entry_bytes( $base . 'removed-dir', '', '5' )
			. $this->tar_entry_bytes( '././@LongLink', $long, 'L' )
			. $this->tar_entry_bytes( substr( $long, 0, 100 ), 'long original' )
			. $this->tar_entry_bytes( $base . 'kept.txt', 'original' );
		list( $archiver, $path ) = $this->resume( $bytes, $type );
		try {
			// New entry precedes surviving entries in the second directory walk.
			file_put_contents( $this->scratch . '/new.txt', 'new' );
			$this->invoke( $archiver, 'add_file', $this->scratch . '/new.txt', $base . 'new.txt' );
			$this->invoke( $archiver, 'add_file_from_string', $base . 'kept.txt', 'changed' );
			$this->invoke( $archiver, 'add_file_from_string', $long, 'changed long' );
			$this->invoke( $archiver, 'add_empty_directory', $base . 'removed-dir', 0, 0, 0, time() );
			$this->invoke( $archiver, 'add_data', str_repeat( "\0", 1024 ) );
		} finally {
			$archiver->close();
		}
		$raw = 'tar' === $type ? file_get_contents( $path ) : file_get_contents( 'compress.zlib://' . $path );
		$this->assertSame( $bytes, substr( $raw, 0, strlen( $bytes ) ), 'Captured members must remain byte-for-byte unchanged.' );
		$this->assertSame( strlen( $bytes ) + 2048, strlen( $raw ), 'Only one new file and the footer may be appended.' );
		// Native restore inflates compressed parts before opening them with PharData.
		$decoded = $this->scratch . '/decoded.tar';
		file_put_contents( $decoded, $raw );
		$archive = new PharData( $decoded );
		$archive->extractTo( $this->scratch . '/restored' );
		$this->assertSame( 'captured before deletion', file_get_contents( $this->scratch . '/restored/' . $base . 'deleted.txt' ) );
		$this->assertSame( 'original', file_get_contents( $this->scratch . '/restored/' . $base . 'kept.txt' ) );
		$this->assertSame( 'long original', file_get_contents( $this->scratch . '/restored/' . $long ) );
		$this->assertSame( 'new', file_get_contents( $this->scratch . '/restored/' . $base . 'new.txt' ) );
	}

	/** @dataProvider archive_types */
	public function test_idle_replay_preserves_existing_content( $type ) {
		$bytes = $this->tar_entry_bytes( 'kept.txt', 'original' );
		list( $archiver, $path ) = $this->resume( $bytes, $type );
		try {
			$this->assertTrue( $this->invoke( $archiver, 'add_file_from_string', 'kept.txt', 'changed' ) );
		} finally {
			$archiver->close();
		}
		$this->assertSame( $bytes, 'tar' === $type ? file_get_contents( $path ) : file_get_contents( 'compress.zlib://' . $path ) );
	}

	public function corrupt_members() {
		$rows = array();
		foreach ( array( 'tar', 'tar.gz' ) as $type ) {
			foreach ( array( 'checksum', 'truncated', 'long-name', 'type', 'footer', 'directory-size' ) as $damage ) {
				$rows[] = array( $type, $damage );
			}
		}
		return $rows;
	}

	/** @dataProvider corrupt_members */
	public function test_churn_does_not_accept_corrupt_members( $type, $damage ) {
		$bytes = $this->tar_entry_bytes( 'deleted.txt', 'original' );
		if ( 'checksum' === $damage ) {
			$bytes[0] = 'X';
		} elseif ( 'truncated' === $damage ) {
			$bytes = substr( $bytes, 0, 600 );
		} elseif ( 'long-name' === $damage ) {
			$bytes = substr( $this->tar_entry_bytes( '././@LongLink', str_repeat( 'x', 200 ), 'L' ), 0, 600 );
		} elseif ( 'footer' === $damage ) {
			$bytes .= str_repeat( "\0", 1024 ) . 'unexpected tail';
		} elseif ( 'directory-size' === $damage ) {
			$bytes = $this->tar_entry_bytes( 'deleted-dir', 'unexpected payload', '5' );
		} else {
			$bytes = $this->tar_entry_bytes( 'deleted.txt', '', '2', 'target' );
		}
		list( $archiver ) = $this->resume( $bytes, $type );
		try {
			$this->invoke( $archiver, 'add_file_from_string', 'new.txt', 'new' );
			$this->fail( 'Corrupt archived member was accepted.' );
		} catch ( MainWP_Exception $error ) {
			$this->assertSame( MainWP_Backup_Error::ERR_FILE_CORRUPT, $error->get_message_error_code() );
		} finally {
			$archiver->close();
		}
	}
}
