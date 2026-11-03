<?php
/**
 * Sensitivity fixtures for the private format guards.
 *
 * @package Mainwp_Child
 */

use MainWP\Child\MainWP_Child_Backup_Layout;
use MainWP\Child\MainWP_Child_Backup_Layout_Codec;
use MainWP\Child\MainWP_Child_Backup_Layout_List;
use MainWP\Child\MainWP_Child_Backup_Layout_State;
use MainWP\Child\MainWP_Exception;

require_once __DIR__ . '/includes/trait-tar-entry-fixture.php';

class BackupLayoutContractGuardsTest extends WP_UnitTestCase {
	use Tar_Entry_Fixture;

	private $dir;

	public function set_up() {
		parent::set_up();
		$this->dir = sys_get_temp_dir() . '/mwp-contract-' . uniqid();
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
		return MainWP_Child_Backup_Layout::descriptor( array( array( 'role' => 'uploads', 'coverage' => 'restore' ) ), str_repeat( 'a', 64 ) );
	}

	/** @dataProvider invalid_descriptor_rows */
	public function test_descriptor_row_guards( $kind ) {
		$descriptor = $this->descriptor();
		switch ( $kind ) {
			case 'empty':
				$descriptor['roots'] = array();
				break;
			case 'extra-field':
				$descriptor['roots'][0]['extra'] = 'ignored';
				break;
			case 'duplicate':
				$descriptor['roots'][] = $descriptor['roots'][0];
				break;
			case 'role':
				$descriptor['roots'][0]['role'] = 'alien';
				break;
			case 'list-format':
				$descriptor['list_format'] = 3;
				break;
			case 'encoding':
				$descriptor['path_encoding'] = 'unknown';
				break;
			case 'policy':
				$descriptor['core_policy'] = 'unknown';
				break;
			case 'layout-id':
				$descriptor['layout_id'] = 'invalid';
				break;
			case 'coverage':
				$descriptor['roots'][0]['coverage'] = 'unrecognized';
				break;
		}
		$this->expectException( MainWP_Exception::class );
		MainWP_Child_Backup_Layout::serialize( $descriptor );
	}

	public static function invalid_descriptor_rows() {
		return array( array( 'empty' ), array( 'extra-field' ), array( 'duplicate' ), array( 'coverage' ), array( 'role' ), array( 'list-format' ), array( 'encoding' ), array( 'policy' ), array( 'layout-id' ) );
	}

	/** @dataProvider invalid_list_rows */
	public function test_list_row_guards( $body, $tombstones ) {
		$digest = str_repeat( 'a', 64 );
		file_put_contents( $this->dir . '/list', '# mainwp-' . ( $tombstones ? 'tombstones' : 'files' ) . ' 2 ' . $digest . "\n" . $body );
		$this->expectException( MainWP_Exception::class );
		( new MainWP_Child_Backup_Layout_List() )->read( $this->dir . '/list', $digest, $tombstones );
	}

	public static function invalid_list_rows() {
		return array(
			array( "roots/uploads/z\t1\t2\nroots/uploads/a\t1\t2\n", false ),
			array( "roots/uploads/a\t1\t2\textra\n", false ),
			array( "roots/uploads/a\tnonnumeric\t2\n", false ),
			array( "roots/uploads/a\t1\tnonnumeric\n", false ),
			array( "roots/uploads/a\textra\n", true ),
			array( "roots/uploads/a\t1\t22", false ),
		);
	}

	/** @dataProvider invalid_identity_segments */
	public function test_identity_segment_guards( $identity ) {
		$this->expectException( MainWP_Exception::class );
		MainWP_Child_Backup_Layout::parse_identity( $identity );
	}

	public static function invalid_identity_segments() {
		return array(
			array( 'roots/uploads/a//b' ),
			array( 'roots/uploads/a/./b' ),
			array( "roots/uploads/a\tb" ),
			array( "roots/uploads/a\nb" ),
			array( "roots/uploads/a\rb" ),
		);
	}

	public function test_resolver_refuses_unpinned_known_role() {
		$layout = new MainWP_Child_Backup_Layout( array( 'content' => $this->dir ) );
		$this->expectException( MainWP_Exception::class );
		$layout->resolve( 'roots/uploads/file' );
	}

	public function test_codec_refuses_role_absent_from_descriptor() {
		$descriptor = $this->descriptor();
		$tar = $this->tar_entry_bytes( 'mainwp-layout.json', MainWP_Child_Backup_Layout::serialize( $descriptor ) ) . $this->tar_entry_bytes( 'roots/plugins/file', 'payload' );
		file_put_contents( $this->dir . '/role.mwp2', "MWPBACKUP2\n" . $tar . str_repeat( "\0", 1024 ) );
		mkdir( $this->dir . '/scratch' );
		$this->expectException( MainWP_Exception::class );
		( new MainWP_Child_Backup_Layout_Codec() )->read_slice( $this->dir . '/role.mwp2', $this->dir . '/scratch', MainWP_Child_Backup_Layout::digest( $descriptor ), array(), microtime( true ) + 10 );
	}

	public function test_undeclared_role_is_rejected_at_parent_before_leaf_check() {
		$descriptor = $this->descriptor();
		$tar = $this->tar_entry_bytes( 'mainwp-layout.json', MainWP_Child_Backup_Layout::serialize( $descriptor ) ) . $this->tar_entry_bytes( 'roots/plugins/file', 'payload' );
		file_put_contents( $this->dir . '/parent-order.tar', $tar . str_repeat( "\0", 1024 ) );
		$phar = new PharData( $this->dir . '/parent-order.tar' );
		$names = array();
		$prefix = 'phar://' . $phar->getPath() . '/';
		foreach ( new RecursiveIteratorIterator( $phar, RecursiveIteratorIterator::SELF_FIRST ) as $entry ) {
			$names[] = substr( $entry->getPathname(), strlen( $prefix ) );
		}
		$parent = array_search( 'roots/plugins', $names, true );
		$leaf = array_search( 'roots/plugins/file', $names, true );
		$this->assertIsInt( $parent );
		$this->assertIsInt( $leaf );
		$this->assertLessThan( $leaf, $parent );
		$this->assertSame( array( 'plugins', 'file' ), MainWP_Child_Backup_Layout::parse_identity( 'roots/plugins/file' ) );

		file_put_contents( $this->dir . '/parent-order.mwp2', "MWPBACKUP2\n" . $tar . str_repeat( "\0", 1024 ) );
		mkdir( $this->dir . '/parent-scratch' );
		$failure = null;
		try {
			( new MainWP_Child_Backup_Layout_Codec() )->read_slice( $this->dir . '/parent-order.mwp2', $this->dir . '/parent-scratch', MainWP_Child_Backup_Layout::digest( $descriptor ), array(), microtime( true ) + 10 );
		} catch ( MainWP_Exception $error ) {
			$failure = array( $error->get_message_error_code(), in_array( 'parse_identity', array_column( $error->getTrace(), 'function' ), true ) );
		}
		// The valid leaf cannot fail identity parsing; its undeclared parent fails first.
		$this->assertSame( array( 'mainwp_backup_file_corrupt', true ), $failure );
		$this->assertDirectoryDoesNotExist( $this->dir . '/parent-scratch/extracted' );
	}

	/** @dataProvider invalid_writer_bounds */
	public function test_writer_slice_bounds( $entries, $bytes ) {
		$this->expectException( MainWP_Exception::class );
		( new MainWP_Child_Backup_Layout_Codec() )->write_slice( $this->dir . '/bounds.mwp2', $this->descriptor(), array(), array(), microtime( true ) + 10, $entries, $bytes );
	}

	public static function invalid_writer_bounds() {
		return array( array( 0, 127 ), array( 1, 0 ) );
	}

	public function test_writer_refuses_changed_entry_bindings() {
		file_put_contents( $this->dir . '/source', 'source bytes' );
		file_put_contents( $this->dir . '/other', 'other bytes' );
		$entries = array( 'roots/uploads/one' => $this->dir . '/source', 'roots/uploads/two' => $this->dir . '/source' );
		$codec = new MainWP_Child_Backup_Layout_Codec();
		$result = $codec->write_slice( $this->dir . '/entries.mwp2', $this->descriptor(), $entries, array(), microtime( true ) + 10, 1, 127 );
		$this->assertFalse( $result['done'] );
		$entries['roots/uploads/two'] = $this->dir . '/other';
		$this->expectException( MainWP_Exception::class );
		$codec->write_slice( $this->dir . '/entries.mwp2', $this->descriptor(), $entries, $result['cursor'], microtime( true ) + 10, 1, 127 );
	}

	public function test_writer_refuses_entry_index_past_end() {
		file_put_contents( $this->dir . '/source', 'source bytes' );
		$entries = array( 'roots/uploads/one' => $this->dir . '/source' );
		$codec = new MainWP_Child_Backup_Layout_Codec();
		$result = $codec->write_slice( $this->dir . '/index.mwp2', $this->descriptor(), $entries, array(), microtime( true ) + 10, 1, 127 );
		$result['cursor']['entry_index'] = 2;
		$this->expectException( MainWP_Exception::class );
		$codec->write_slice( $this->dir . '/index.mwp2', $this->descriptor(), $entries, $result['cursor'], microtime( true ) + 10, 1, 127 );
	}

	public function test_private_state_decode_nonstring_is_file_corrupt() {
		$code = null;
		try {
			MainWP_Child_Backup_Layout_State::decode( array( 'not JSON bytes' ), str_repeat( 'a', 64 ) );
		} catch ( MainWP_Exception $e ) {
			$code = $e->get_message_error_code();
		}
		$this->assertSame( 'mainwp_backup_file_corrupt', $code );
	}
	public function test_diff_missing_output_directory_has_registry_error() {
		$digest = str_repeat( 'a', 64 );
		$list = new MainWP_Child_Backup_Layout_List();
		$list->write( $this->dir . '/parent', $digest, array() );
		$list->write( $this->dir . '/current', $digest, array() );
		$code = null;
		try {
			$list->diff( $this->dir . '/parent', $this->dir . '/current', $this->dir . '/missing/changes', $this->dir . '/missing/tombs', $digest );
		} catch ( MainWP_Exception $e ) {
			$code = $e->get_message_error_code();
		}
		$this->assertSame( 'mainwp_backup_dir_not_writable', $code );
		$this->assertDirectoryDoesNotExist( $this->dir . '/missing' );
	}

	public function test_list_line_bound_refuses_an_oversized_row() {
		$digest = str_repeat( 'a', 64 );
		file_put_contents( $this->dir . '/long-list', '# mainwp-files 2 ' . $digest . "\nroots/uploads/" . str_repeat( 'a', 131073 ) . "\t1\t22\n" );
		$this->expectException( MainWP_Exception::class );
		( new MainWP_Child_Backup_Layout_List() )->read( $this->dir . '/long-list', $digest );
	}

}
