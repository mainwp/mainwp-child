<?php
/**
 * Format-level fixtures for the inert v2 backup internals.
 *
 * @package Mainwp_Child
 */

use MainWP\Child\MainWP_Child_Backup_Layout;
use MainWP\Child\MainWP_Child_Backup_Layout_Codec;
use MainWP\Child\MainWP_Child_Backup_Layout_List;

require_once __DIR__ . '/includes/trait-tar-entry-fixture.php';

class BackupLayoutV2Test extends WP_UnitTestCase {
	use Tar_Entry_Fixture;
	private $dir;

	public function set_up() {
		parent::set_up();
		$this->dir = sys_get_temp_dir() . '/mwp-v2-' . uniqid();
		mkdir( $this->dir );
	}

	public function tear_down() {
		$this->remove_tree( $this->dir );
		parent::tear_down();
	}

	private function remove_tree( $path ) {
		if ( is_dir( $path ) && ! is_link( $path ) ) {
			foreach ( scandir( $path ) as $name ) {
				if ( '.' !== $name && '..' !== $name ) {
					$this->remove_tree( $path . '/' . $name );
				}
			}
			rmdir( $path );
		} else {
			unlink( $path );
		}
	}

	private function descriptor() {
		return MainWP_Child_Backup_Layout::descriptor( array( array( 'role' => 'uploads', 'coverage' => 'restore' ), array( 'role' => 'site', 'coverage' => 'preserved' ) ), str_repeat( 'a', 64 ) );
	}

	public function test_descriptor_canonical_bytes_and_observational_version() {
		$descriptor = $this->descriptor();
		$this->assertSame( '{"format":2,"roots":[{"role":"site","coverage":"preserved"},{"role":"uploads","coverage":"restore"}],"layout_id":"' . str_repeat( 'a', 64 ) . '","core_policy":"managed_preserve","list_format":2,"path_encoding":"role-prefix"}', MainWP_Child_Backup_Layout::serialize( $descriptor ) );
		$with_version = $descriptor;
		$with_version['core_version'] = '6.9';
		$this->assertSame( MainWP_Child_Backup_Layout::digest( $descriptor ), MainWP_Child_Backup_Layout::digest( $with_version ) );
	}

	public function test_descriptor_mismatch_refused() {
		$this->expectException( Exception::class );
		MainWP_Child_Backup_Layout::decode( MainWP_Child_Backup_Layout::serialize( $this->descriptor() ), str_repeat( 'b', 64 ) );
	}

	public function test_unknown_descriptor_version_refused() {
		$descriptor = $this->descriptor();
		$descriptor['format'] = 3;
		$this->expectException( Exception::class );
		MainWP_Child_Backup_Layout::serialize( $descriptor );
	}

	public function test_container_round_trip_resumes_without_live_root() {
		file_put_contents( $this->dir . '/source.txt', 'uploads bytes' );
		$codec = new MainWP_Child_Backup_Layout_Codec();
		$codec->write( $this->dir . '/backup.mwp2', $this->descriptor(), array( 'roots/uploads/same.txt' => $this->dir . '/source.txt' ) );
		$this->assertSame( "MWPBACKUP2\n", file_get_contents( $this->dir . '/backup.mwp2', false, null, 0, 11 ) );
		mkdir( $this->dir . '/scratch' );
		$cursor = array();
		do {
			$result = $codec->read_slice( $this->dir . '/backup.mwp2', $this->dir . '/scratch', MainWP_Child_Backup_Layout::digest( $this->descriptor() ), $cursor, microtime( true ) + 10, 127 );
			$cursor = $result['cursor'];
		} while ( ! $result['done'] );
		$this->assertSame( $this->descriptor(), $result['descriptor'] );
		$this->assertSame( 'uploads bytes', file_get_contents( $this->dir . '/scratch/extracted/roots/uploads/same.txt' ) );
		$this->assertFileDoesNotExist( $this->dir . '/scratch/extracted/mainwp-layout.json' );
	}

	public function test_magic_guard() {
		file_put_contents( $this->dir . '/bad.mwp2', "BADBACKUP2\n" . str_repeat( "\0", 1024 ) );
		mkdir( $this->dir . '/scratch' );
		$this->expectException( Exception::class );
		( new MainWP_Child_Backup_Layout_Codec() )->read_slice( $this->dir . '/bad.mwp2', $this->dir . '/scratch', str_repeat( 'a', 64 ), array(), microtime( true ) + 10, 1 );
	}

	public function test_distinct_roots_with_same_basename_and_precedence() {
		mkdir( $this->dir . '/content' );
		mkdir( $this->dir . '/content/plugins' );
		mkdir( $this->dir . '/uploads' );
		$layout = new MainWP_Child_Backup_Layout( array( 'site' => $this->dir, 'content' => $this->dir . '/content', 'plugins' => $this->dir . '/content/plugins', 'uploads' => $this->dir . '/uploads' ) );
		$this->assertSame( 'roots/plugins/same.txt', $layout->identity( $this->dir . '/content/plugins/same.txt' ) );
		$this->assertSame( 'roots/content/same.txt', $layout->identity( $this->dir . '/content/same.txt' ) );
		$this->assertSame( 'roots/uploads/same.txt', $layout->identity( $this->dir . '/uploads/same.txt' ) );
		$this->assertSame( $this->dir . '/content/plugins/same.txt', $layout->resolve( 'roots/plugins/same.txt' ) );
		$this->expectException( Exception::class );
		$layout->resolve( 'roots/content/plugins/same.txt' );
	}

	public static function invalid_identities() {
		return array( array( '/roots/uploads/file' ), array( 'roots/uploads/C:/file' ), array( "roots/uploads/a\0b" ), array( 'roots/uploads/../file' ), array( 'roots/uploads/a\\..\\file' ), array( 'roots/alien/file' ) );
	}

	/** @dataProvider invalid_identities */
	public function test_identity_guards( $identity ) {
		$this->expectException( Exception::class );
		MainWP_Child_Backup_Layout::parse_identity( $identity );
	}

	public function test_slash_boundary_and_existing_parent_before_mkdir() {
		mkdir( $this->dir . '/root' );
		mkdir( $this->dir . '/root-evil' );
		symlink( $this->dir . '/root-evil', $this->dir . '/root/link' );
		$layout = new MainWP_Child_Backup_Layout( array( 'uploads' => $this->dir . '/root' ) );
		$caught = false;
		try {
			$layout->resolve( 'roots/uploads/link/new/file' );
		} catch ( Exception $e ) {
			$caught = true;
		}
		$this->assertTrue( $caught );
		$this->assertDirectoryDoesNotExist( $this->dir . '/root-evil/new' );
	}

	public function test_configured_link_root_is_resolved_once() {
		mkdir( $this->dir . '/physical' );
		symlink( $this->dir . '/physical', $this->dir . '/configured' );
		$layout = new MainWP_Child_Backup_Layout( array( 'uploads' => $this->dir . '/configured' ) );
		$this->assertSame( $this->dir . '/physical/file', $layout->resolve( 'roots/uploads/file' ) );
	}

	public function test_diff_parity_root_collisions_and_qualified_skips() {
		$digest = MainWP_Child_Backup_Layout::digest( $this->descriptor() );
		$list = new MainWP_Child_Backup_Layout_List();
		$list->write( $this->dir . '/parent', $digest, array( 'roots/content/same' => array( 1, 2 ), 'roots/plugins/same' => array( 1, 2 ), 'roots/uploads/same' => array( 1, 2 ) ) );
		$list->write( $this->dir . '/current', $digest, array( 'roots/plugins/same' => array( 3, 2 ) ) );
		foreach ( array( false, true ) as $merge ) {
			$counts[] = $list->diff( $this->dir . '/parent', $this->dir . '/current', $this->dir . '/changes' . (int) $merge, $this->dir . '/tombs' . (int) $merge, $digest, $merge, array( 'roots/uploads' ) );
		}
		$this->assertSame( $counts[0], $counts[1] );
		$this->assertSame( file_get_contents( $this->dir . '/changes0' ), file_get_contents( $this->dir . '/changes1' ) );
		$this->assertSame( '# mainwp-tombstones 2 ' . $digest . "\nroots/content/same\n", file_get_contents( $this->dir . '/tombs0' ) );
		$this->assertSame( file_get_contents( $this->dir . '/tombs0' ), file_get_contents( $this->dir . '/tombs1' ) );
	}

	public function test_unknown_list_version_refused() {
		file_put_contents( $this->dir . '/list', '# mainwp-files 3 ' . str_repeat( 'a', 64 ) . "\n" );
		$this->expectException( Exception::class );
		( new MainWP_Child_Backup_Layout_List() )->read( $this->dir . '/list', str_repeat( 'a', 64 ) );
	}
	private function assert_format_error( $code, $callback ) {
		$caught = null;
		try {
			$callback();
		} catch ( \MainWP\Child\MainWP_Exception $e ) {
			$caught = $e->get_message_error_code();
		}
		$this->assertSame( $code, $caught );
	}

	public function test_descriptor_digest_and_unknown_version_error_codes() {
		$this->assert_format_error( 'mainwp_backup_file_corrupt', function () {
			MainWP_Child_Backup_Layout::decode( MainWP_Child_Backup_Layout::serialize( $this->descriptor() ), str_repeat( 'b', 64 ) );
		} );
		$this->assert_format_error( 'mainwp_backup_format_unsupported', function () {
			$descriptor = $this->descriptor();
			$descriptor['format'] = 3;
			MainWP_Child_Backup_Layout::serialize( $descriptor );
		} );
	}

	public function test_descendant_link_cannot_cross_to_another_authorized_root() {
		mkdir( $this->dir . '/content' );
		mkdir( $this->dir . '/content/uploads' );
		symlink( $this->dir . '/content/uploads', $this->dir . '/content/alias' );
		$layout = new MainWP_Child_Backup_Layout( array( 'content' => $this->dir . '/content', 'uploads' => $this->dir . '/content/uploads' ) );
		$this->assert_format_error( 'mainwp_backup_file_corrupt', function () use ( $layout ) {
			$layout->resolve( 'roots/content/alias/new/file' );
		} );
		$this->assertDirectoryDoesNotExist( $this->dir . '/content/uploads/new' );
	}

	public function test_existing_parent_slash_boundary_without_role_map() {
		mkdir( $this->dir . '/root' );
		mkdir( $this->dir . '/root-evil' );
		symlink( $this->dir . '/root-evil', $this->dir . '/root/link' );
		$this->expectException( Exception::class );
		\MainWP\Child\MainWP_Child_Backup_File_Restorer::validate_extract_path( 'link/file', $this->dir . '/root' );
	}

	public function test_missing_parent_slash_boundary_without_role_map() {
		mkdir( $this->dir . '/root' );
		mkdir( $this->dir . '/root-evil' );
		symlink( $this->dir . '/root-evil', $this->dir . '/root/link' );
		$caught = false;
		try {
			\MainWP\Child\MainWP_Child_Backup_File_Restorer::validate_extract_path( 'link/new/file', $this->dir . '/root' );
		} catch ( Exception $e ) {
			$caught = true;
		}
		$this->assertTrue( $caught );
		$this->assertDirectoryDoesNotExist( $this->dir . '/root-evil/new' );
	}

	public function test_list_duplicate_and_digest_binding() {
		$digest = str_repeat( 'a', 64 );
		$list = new MainWP_Child_Backup_Layout_List();
		$header = '# mainwp-files 2 ' . $digest . "\n";
		file_put_contents( $this->dir . '/list', $header . "roots/uploads/a\t1\t2\nroots/uploads/a\t3\t2\n" );
		$this->assert_format_error( 'mainwp_backup_file_corrupt', function () use ( $list, $digest ) {
			$list->read( $this->dir . '/list', $digest );
		} );
		file_put_contents( $this->dir . '/list', $header );
		$this->assert_format_error( 'mainwp_backup_file_corrupt', function () use ( $list ) {
			$list->read( $this->dir . '/list', str_repeat( 'b', 64 ) );
		} );
	}

	public function test_paths_cache_keeps_component_and_qualified_identity() {
		$list = new MainWP_Child_Backup_Layout_List();
		$list->write_paths_cache( $this->dir . '/paths.cache', array( 'roots/uploads/same' => 'uploads', 'roots/plugins/same' => 'plugins' ) );
		$this->assertSame( "plugins\troots/plugins/same\nuploads\troots/uploads/same\n", file_get_contents( $this->dir . '/paths.cache' ) );
	}

	public function test_descriptor_mismatch_before_scratch_extraction() {
		file_put_contents( $this->dir . '/source', 'bytes' );
		$codec = new MainWP_Child_Backup_Layout_Codec();
		$codec->write( $this->dir . '/backup.mwp2', $this->descriptor(), array( 'roots/uploads/file' => $this->dir . '/source' ) );
		mkdir( $this->dir . '/scratch' );
		$this->assert_format_error( 'mainwp_backup_file_corrupt', function () use ( $codec ) {
			$codec->read_slice( $this->dir . '/backup.mwp2', $this->dir . '/scratch', str_repeat( 'b', 64 ), array(), microtime( true ) + 10 );
		} );
		$this->assertDirectoryDoesNotExist( $this->dir . '/scratch/extracted' );
	}

	public function test_cursor_cannot_resume_under_another_descriptor() {
		file_put_contents( $this->dir . '/source', 'bytes' );
		$codec = new MainWP_Child_Backup_Layout_Codec();
		$codec->write( $this->dir . '/backup.mwp2', $this->descriptor(), array( 'roots/uploads/file' => $this->dir . '/source' ) );
		mkdir( $this->dir . '/scratch' );
		$result = $codec->read_slice( $this->dir . '/backup.mwp2', $this->dir . '/scratch', MainWP_Child_Backup_Layout::digest( $this->descriptor() ), array(), microtime( true ) + 10, 1 );
		$this->assertFalse( $result['done'] );
		$this->assert_format_error( 'mainwp_backup_layout_changed', function () use ( $codec, $result ) {
			$codec->read_slice( $this->dir . '/backup.mwp2', $this->dir . '/scratch', str_repeat( 'b', 64 ), $result['cursor'], microtime( true ) + 10, 1 );
		} );
	}

	public function test_equal_roots_have_one_owner_and_semantic_priority_is_bidirectional() {
		mkdir( $this->dir . '/content' );
		mkdir( $this->dir . '/content/deep' );
		$layout = new MainWP_Child_Backup_Layout( array( 'plugins' => $this->dir, 'uploads' => $this->dir . '/content', 'content' => $this->dir . '/content/deep', 'site' => $this->dir . '/content/deep' ) );
		$this->assertSame( 'roots/uploads/deep/file', $layout->identity( $this->dir . '/content/deep/file' ) );
		$this->assertSame( 'roots/plugins/file', $layout->identity( $this->dir . '/file' ) );
		$aliases = new MainWP_Child_Backup_Layout( array( 'site' => $this->dir, 'content' => $this->dir, 'uploads' => $this->dir ) );
		$this->assertSame( 'roots/uploads/file', $aliases->identity( $this->dir . '/file' ) );
		$this->assert_format_error( 'mainwp_backup_file_corrupt', function () use ( $aliases ) {
			$aliases->resolve( 'roots/content/file' );
		} );
	}

	public function test_overflow_suppresses_deletions_in_both_diff_engines() {
		$digest = str_repeat( 'a', 64 );
		$list = new MainWP_Child_Backup_Layout_List();
		$list->write( $this->dir . '/parent', $digest, array( 'roots/content/same' => array( 1, 2 ) ) );
		$list->write( $this->dir . '/current', $digest, array() );
		foreach ( array( false, true ) as $merge ) {
			$counts = $list->diff( $this->dir . '/parent', $this->dir . '/current', $this->dir . '/changes', $this->dir . '/tombs', $digest, $merge, array(), true );
			$this->assertSame( 0, $counts['tombstones'] );
			$this->assertSame( 1, $counts['carried'] );
		}
	}

    public function test_missing_and_corrupt_descriptor_refused_before_extraction() {
        $cases = array(
            $this->tar_entry_bytes( 'roots/uploads/file', 'payload' ),
            $this->tar_entry_bytes( 'mainwp-layout.json', '{bad json}' ) . $this->tar_entry_bytes( 'roots/uploads/file', 'payload' ),
        );
        foreach ( $cases as $index => $tar ) {
            file_put_contents( $this->dir . '/bad' . $index . '.mwp2', "MWPBACKUP2\n" . $tar . str_repeat( "\0", 1024 ) );
            mkdir( $this->dir . '/scratch' . $index );
            $this->assert_format_error( 'mainwp_backup_file_corrupt', function () use ( $index ) {
                ( new MainWP_Child_Backup_Layout_Codec() )->read_slice( $this->dir . '/bad' . $index . '.mwp2', $this->dir . '/scratch' . $index, MainWP_Child_Backup_Layout::digest( $this->descriptor() ), array(), microtime( true ) + 10 );
            } );
            $this->assertDirectoryDoesNotExist( $this->dir . '/scratch' . $index . '/extracted' );
        }
    }

    public function test_duplicate_tar_identities_are_refused() {
        $tar = $this->tar_entry_bytes( 'mainwp-layout.json', MainWP_Child_Backup_Layout::serialize( $this->descriptor() ) )
            . $this->tar_entry_bytes( 'roots/uploads/file', 'first' )
            . $this->tar_entry_bytes( 'roots/uploads/file', 'second' );
        file_put_contents( $this->dir . '/duplicate.mwp2', "MWPBACKUP2\n" . $tar . str_repeat( "\0", 1024 ) );
        mkdir( $this->dir . '/scratch' );
        $this->assert_format_error( 'mainwp_backup_file_corrupt', function () {
            ( new MainWP_Child_Backup_Layout_Codec() )->read_slice( $this->dir . '/duplicate.mwp2', $this->dir . '/scratch', MainWP_Child_Backup_Layout::digest( $this->descriptor() ), array(), microtime( true ) + 10 );
        } );
        $this->assertDirectoryDoesNotExist( $this->dir . '/scratch/extracted' );
    }

    public function test_recovery_only_and_preserved_coverage_round_trip() {
        $descriptor = MainWP_Child_Backup_Layout::descriptor( array( array( 'role' => 'site', 'coverage' => 'preserved' ), array( 'role' => 'config', 'coverage' => 'recovery_only' ), array( 'role' => 'uploads', 'coverage' => 'restore' ) ), str_repeat( 'a', 64 ) );
        file_put_contents( $this->dir . '/config', 'fake recovery marker' );
        file_put_contents( $this->dir . '/upload', 'media marker' );
        $codec = new MainWP_Child_Backup_Layout_Codec();
        $codec->write( $this->dir . '/recovery.mwp2', $descriptor, array( 'roots/config/wp-config.php' => $this->dir . '/config', 'roots/uploads/same' => $this->dir . '/upload' ) );
        mkdir( $this->dir . '/scratch' );
        $result = $codec->read_slice( $this->dir . '/recovery.mwp2', $this->dir . '/scratch', MainWP_Child_Backup_Layout::digest( $descriptor ), array(), microtime( true ) + 10 );
        $this->assertTrue( $result['done'] );
        $this->assertSame( $descriptor, $result['descriptor'] );
        $this->assertSame( 'fake recovery marker', file_get_contents( $this->dir . '/scratch/extracted/roots/config/wp-config.php' ) );
        $this->assertSame( 'media marker', file_get_contents( $this->dir . '/scratch/extracted/roots/uploads/same' ) );
    }

    public function test_writer_resumes_at_entry_and_wrapper_boundaries() {
        file_put_contents( $this->dir . '/source', str_repeat( 'x', 1024 ) );
        $entries = array( 'roots/uploads/one' => $this->dir . '/source', 'roots/uploads/two' => $this->dir . '/source' );
        $codec = new MainWP_Child_Backup_Layout_Codec();
        $result = $codec->write_slice( $this->dir . '/sliced.mwp2', $this->descriptor(), $entries, array(), microtime( true ) + 10, 1, 127 );
        $this->assertFalse( $result['done'] );
        $this->assertSame( 1, $result['cursor']['entry_index'] );
        $cursor = $result['cursor'];
        do {
            $result = $codec->write_slice( $this->dir . '/sliced.mwp2', $this->descriptor(), $entries, $cursor, microtime( true ) + 10, 1, 127 );
            $this->assertLessThanOrEqual( 127, $result['cursor']['wrapped_offset'] - $cursor['wrapped_offset'] );
            $cursor = $result['cursor'];
        } while ( ! $result['done'] );
        mkdir( $this->dir . '/scratch' );
        $result = $codec->read_slice( $this->dir . '/sliced.mwp2', $this->dir . '/scratch', MainWP_Child_Backup_Layout::digest( $this->descriptor() ), array(), microtime( true ) + 10 );
        $this->assertTrue( $result['done'] );
        $this->assertSame( str_repeat( 'x', 1024 ), file_get_contents( $this->dir . '/scratch/extracted/roots/uploads/two' ) );
    }

	public function test_resolver_never_authorizes_a_live_file_link() {
		file_put_contents( $this->dir . '/sentinel', 'keep' );
		mkdir( $this->dir . '/root' );
		symlink( $this->dir . '/sentinel', $this->dir . '/root/link' );
		$layout = new MainWP_Child_Backup_Layout( array( 'uploads' => $this->dir . '/root' ) );
		$this->assert_format_error( 'mainwp_backup_file_corrupt', function () use ( $layout ) {
			$layout->resolve( 'roots/uploads/link' );
		} );
		$this->assertTrue( is_link( $this->dir . '/root/link' ) );
		$this->assertSame( 'keep', file_get_contents( $this->dir . '/sentinel' ) );
	}

	public function test_container_preserves_observed_core_version_outside_digest() {
		$descriptor = $this->descriptor();
		$versioned = $descriptor;
		$versioned['core_version'] = '6.9-fixture';
		$this->assertSame( MainWP_Child_Backup_Layout::digest( $descriptor ), MainWP_Child_Backup_Layout::digest( $versioned ) );
		file_put_contents( $this->dir . '/source', 'bytes' );
		$codec = new MainWP_Child_Backup_Layout_Codec();
		$codec->write( $this->dir . '/version.mwp2', $versioned, array( 'roots/uploads/file' => $this->dir . '/source' ) );
		mkdir( $this->dir . '/scratch' );
		$result = $codec->read_slice( $this->dir . '/version.mwp2', $this->dir . '/scratch', MainWP_Child_Backup_Layout::digest( $descriptor ), array(), microtime( true ) + 10 );
		$this->assertTrue( $result['done'] );
		$this->assertSame( $versioned, $result['descriptor'] );
		$this->assertSame( MainWP_Child_Backup_Layout::digest( $descriptor ), MainWP_Child_Backup_Layout::digest( $result['descriptor'] ) );
	}

	public function test_observed_core_version_must_be_a_string() {
		$descriptor = $this->descriptor();
		$descriptor['core_version'] = array( 'invalid' );
		$this->assert_format_error( 'mainwp_backup_file_corrupt', function () use ( $descriptor ) {
			MainWP_Child_Backup_Layout::metadata_bytes( $descriptor );
		} );
		$this->assert_format_error( 'mainwp_backup_file_corrupt', function () use ( $descriptor ) {
			MainWP_Child_Backup_Layout::decode( wp_json_encode( $descriptor ), MainWP_Child_Backup_Layout::digest( $descriptor ) );
		} );
	}

	public function test_cursor_refuses_source_stat_change() {
		file_put_contents( $this->dir . '/source', 'bytes' );
		$codec = new MainWP_Child_Backup_Layout_Codec();
		$codec->write( $this->dir . '/backup.mwp2', $this->descriptor(), array( 'roots/uploads/file' => $this->dir . '/source' ) );
		mkdir( $this->dir . '/scratch' );
		$digest = MainWP_Child_Backup_Layout::digest( $this->descriptor() );
		$result = $codec->read_slice( $this->dir . '/backup.mwp2', $this->dir . '/scratch', $digest, array(), microtime( true ) + 10, 1 );
		file_put_contents( $this->dir . '/backup.mwp2', 'changed bytes', FILE_APPEND );
		$this->assert_format_error( 'mainwp_backup_layout_changed', function () use ( $codec, $digest, $result ) {
			$codec->read_slice( $this->dir . '/backup.mwp2', $this->dir . '/scratch', $digest, $result['cursor'], microtime( true ) + 10, 1 );
		} );
	}

}
