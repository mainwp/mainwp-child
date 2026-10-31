<?php
/**
 * Old readers must refuse the wrapped format before writing a destination.
 *
 * @package Mainwp_Child
 */

use MainWP\Child\MainWP_Child_Backup_File_Restorer;
use MainWP\Child\MainWP_Child_Backup_Incremental_Diff;

require_once __DIR__ . '/includes/trait-tar-entry-fixture.php';

class BackupLayoutV2LegacyTest extends WP_UnitTestCase {
	use Tar_Entry_Fixture;

	public function test_old_archive_readers_refuse_wrapper_and_renamed_wrapper() {
		$dir = sys_get_temp_dir() . '/mwp-v2-legacy-' . uniqid();
		mkdir( $dir );
		mkdir( $dir . '/target' );
		$bytes = "MWPBACKUP2\n" . $this->tar_entry_bytes( 'sentinel.txt', 'must never be written' ) . str_repeat( "\0", 1024 );
		try {
			foreach ( array( 'mwp2', 'zip', 'tar' ) as $extension ) {
				$writes = 0;
				$part = $dir . '/backup.' . $extension;
				file_put_contents( $part, $bytes );
				$result = ( new MainWP_Child_Backup_File_Restorer() )->restore_slice(
					array(),
					array( 'parts' => array( $part ), 'hashes' => array( basename( $part ) => hash_file( 'sha256', $part ) ), 'extract_to' => $dir . '/target', 'after_file_change' => function () use ( &$writes ) { ++$writes; } ),
					microtime( true ) + 30
				);
				$this->assertSame( 0, $writes, $extension );
				$this->assertNotNull( $result['error'], $extension );
				$this->assertFalse( $result['done'], $extension );
				$this->assertSame( array( '.', '..' ), scandir( $dir . '/target' ), $extension );
				unlink( $part );
			}
		} finally {
			foreach ( glob( $dir . '/*' ) as $path ) {
				if ( is_file( $path ) ) {
					unlink( $path );
				}
			}
			rmdir( $dir . '/target' );
			rmdir( $dir );
		}
	}

	public function test_old_list_reader_refuses_v2_header() {
		$path = tempnam( sys_get_temp_dir(), 'mwp-list-' );
		try {
			file_put_contents( $path, '# mainwp-files 2 ' . str_repeat( 'a', 64 ) . "\nroots/uploads/file.txt\t3\t12\n" );
			$result = ( new MainWP_Child_Backup_Incremental_Diff() )->load_parent_map( $path );
			$this->assertFalse( $result['valid'] );
			$this->assertSame( array(), $result['map'] );
		} finally {
			unlink( $path );
		}
	}
}
