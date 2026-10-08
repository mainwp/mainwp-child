<?php
/**
 * Class BackupSymlinksTest
 *
 * @package Mainwp_Child
 */

use MainWP\Child\MainWP_Backup;
use MainWP\Child\Tar_Archiver;
use MainWP\Child\MainWP_Child_Backup_File_Walker;

/**
 * Linked content must retain its archive path without escaping the allowed bases.
 */
class BackupSymlinksTest extends WP_UnitTestCase {

	/**
	 * Scratch directory for fixtures.
	 *
	 * @var string
	 */
	private $scratch;
	private $external;

	/**
	 * Build a scratch tree.
	 */
	public function set_up() {
		parent::set_up();
		$this->scratch = ABSPATH . 'mwp-symlink-test-' . wp_rand( 1000, 999999 );
		mkdir( $this->scratch, 0755, true );
		MainWP_Backup::reset_skipped_paths();
		$this->external = sys_get_temp_dir() . '/mwp-symlink-external-' . uniqid();
		mkdir( $this->external . '/uploads', 0755, true );
		add_filter( 'upload_dir', array( $this, 'external_uploads' ) );
	}

	/**
	 * Remove the scratch tree.
	 */
	public function tear_down() {
		remove_filter( 'upload_dir', array( $this, 'external_uploads' ) );
		$this->remove_tree( $this->scratch );
		$this->remove_tree( $this->external );
		MainWP_Backup::reset_skipped_paths();
		parent::tear_down();
	}

	/**
	 * Recursively delete a scratch tree without following symlinks.
	 *
	 * @param string $dir Directory to remove.
	 */
	private function remove_tree( $dir ) {
		if ( ! is_dir( $dir ) || is_link( $dir ) ) {
			if ( file_exists( $dir ) || is_link( $dir ) ) {
				unlink( $dir );
			}
			return;
		}
		foreach ( scandir( $dir ) as $entry ) {
			if ( '.' === $entry || '..' === $entry ) {
				continue;
			}
			$path = $dir . '/' . $entry;
			if ( is_link( $path ) || is_file( $path ) ) {
				unlink( $path );
			} else {
				$this->remove_tree( $path );
			}
		}
		rmdir( $dir );
	}

	/**
	 * Regular files inside the site root are safe.
	 */
	public function test_accepts_files_inside_abspath() {
		$this->assertTrue( MainWP_Backup::is_safe_backup_path( ABSPATH . 'wp-load.php' ) );
		$this->assertTrue( MainWP_Backup::is_safe_backup_path( rtrim( ABSPATH, '/' ) ) );
	}

	public function test_content_layout_accepts_roots_inside_site() {
		$this->assertFalse( MainWP_Backup::content_outside_site_root( $this->scratch, array( $this->scratch . '/wp-content/', $this->scratch . '/wp-content/uploads', '' ) ) );
		$this->assertFalse( MainWP_Backup::content_outside_site_root( $this->scratch . '/', array( $this->scratch ) ) );
	}

	public function test_content_layout_rejects_sibling_root() {
		$this->assertTrue( MainWP_Backup::content_outside_site_root( $this->scratch, array( $this->scratch . '/wp-content', $this->external ) ) );
	}

	public function test_content_layout_rejects_prefix_sibling() {
		$root = $this->scratch . '/wp';
		mkdir( $root );
		mkdir( $this->scratch . '/wp-content' );
		$this->assertTrue( MainWP_Backup::content_outside_site_root( $root, array( $this->scratch . '/wp-content' ) ) );
	}

	public function test_content_layout_accepts_outward_symlink_under_site_root() {
		$link = $this->scratch . '/wp-content';
		$this->assertTrue( symlink( $this->external, $link ) );
		$this->assertFalse( MainWP_Backup::content_outside_site_root( $this->scratch, array( $link, $link . '/uploads' ) ) );
	}

	public function test_content_layout_resolved_rejects_outward_symlink_under_site_root() {
		$link = $this->scratch . '/wp-content';
		$this->assertTrue( symlink( $this->external, $link ) );
		$this->assertTrue( MainWP_Backup::content_outside_site_root( $this->scratch, array( $link, $link . '/uploads' ), true ) );
	}

	public function test_content_layout_resolved_accepts_real_roots_inside_site() {
		mkdir( $this->scratch . '/wp-content/uploads', 0755, true );
		$this->assertFalse( MainWP_Backup::content_outside_site_root( $this->scratch, array( $this->scratch . '/wp-content', $this->scratch . '/wp-content/uploads', $this->scratch . '/wp-content/missing' ), true ) );
	}

	public function test_content_layout_accepts_alias_resolving_inside_root() {
		$link = $this->external . '/content-alias';
		$this->assertTrue( symlink( $this->scratch, $link ) );
		$this->assertFalse( MainWP_Backup::content_outside_site_root( $this->scratch, array( $link ) ) );
	}

	public function test_content_layout_allows_empty_site_root() {
		$this->assertFalse( MainWP_Backup::content_outside_site_root( '', array( $this->external ) ) );
	}

	public function test_content_layout_ignores_empty_roots() {
		$this->assertFalse( MainWP_Backup::content_outside_site_root( $this->scratch, array( '' ) ) );
	}

	public function external_uploads( $uploads ) {
		$uploads['basedir'] = $this->external . '/uploads';
		return $uploads;
	}

	private function link_tree() {
		$tree = $this->scratch . '/tree';
		mkdir( $tree . '/dir', 0755, true );
		mkdir( $tree . '/a' );
		mkdir( $tree . '/b' );
		file_put_contents( $tree . '/real.txt', 'target bytes' );
		file_put_contents( $tree . '/dir/.mainwp-restore-abc123.tmp', 'partial file' );
		file_put_contents( $tree . '/dir/.mainwp-restore-nothex.tmp', 'ordinary dotfile' );
		// Marks an engine that can see dotfiles at all; copy_dir() walks with glob(), which cannot.
		file_put_contents( $tree . '/dir/.htaccess-sentinel', 'dotfile sentinel' );
		symlink( $tree . '/dir/.mainwp-restore-abc123.tmp', $tree . '/temp-alias.txt' );
		file_put_contents( $tree . '/dir/inner.txt', 'directory bytes' );
		file_put_contents( $this->external . '/uploads/photo.txt', 'upload bytes' );
		file_put_contents( $this->external . '/outside.txt', 'outside bytes' );
		symlink( 'real.txt', $tree . '/linked.txt' );
		symlink( 'dir', $tree . '/linked-dir' );
		symlink( $this->external . '/uploads', $tree . '/uploads' );
		symlink( $this->external . '/outside.txt', $tree . '/outside.txt' );
		symlink( $this->external, $tree . '/outside-dir' );
		symlink( 'missing', $tree . '/dangling' );
		symlink( '..', $tree . '/dir/loop' );
		symlink( '../b', $tree . '/a/to-b' );
		symlink( '../a', $tree . '/b/to-a' );
		return $tree;
	}

	public function test_safe_path_matrix() {
		$tree = $this->link_tree();
		$cases = array(
			'real.txt'       => true,
			'linked.txt'     => true,
			'linked-dir'     => true,
			'uploads'        => true,
			'outside.txt'    => false,
			'outside-dir'    => false,
			'dangling'       => false,
			'dir/loop'       => false,
			'a/to-b/to-a'    => false,
		);
		foreach ( $cases as $path => $expected ) {
			$ancestors = array( $tree );
			if ( 'dir/loop' === $path ) {
				$ancestors[] = $tree . '/dir';
			} elseif ( 'a/to-b/to-a' === $path ) {
				$ancestors[] = $tree . '/a';
				$ancestors[] = $tree . '/a/to-b';
			}
			$this->assertSame( $expected, MainWP_Backup::is_safe_backup_path( $tree . '/' . $path, null, $ancestors ), $path );
		}
		$this->assertTrue( MainWP_Backup::is_safe_backup_path( $tree . '/linked.txt', array( $tree ) ) );
		$this->assertFalse( MainWP_Backup::is_safe_backup_path( $tree . '/uploads', array( $tree ) ) );
	}

	public function test_directory_inventory_link_matrix() {
		$tree = $this->link_tree();
		file_put_contents( $tree . '/a/a.txt', 'a bytes' );
		file_put_contents( $tree . '/b/b.txt', 'b bytes' );
		$method = new ReflectionMethod( MainWP_Backup::class, 'get_directory_inventory' );
		$method->setAccessible( true );
		$directory_bytes = strlen( 'directory bytesordinary dotfiledotfile sentinel' );
		$expected = array(
			'bytes' => 2 * strlen( 'target bytes' ) + 2 * $directory_bytes + strlen( 'upload bytes' ) + 2 * strlen( 'a bytesb bytes' ),
			'count' => 13,
		);
		$this->assertSame( $expected, $method->invoke( null, $tree ) );
		$cases = array(
			'linked.txt'  => array( strlen( 'target bytes' ), 1 ),
			'linked-dir'  => array( $directory_bytes, 3 ),
			'uploads'     => array( strlen( 'upload bytes' ), 1 ),
			'outside.txt' => array( 0, 0 ),
			'outside-dir' => array( 0, 0 ),
			'dangling'    => array( 0, 0 ),
			'dir/loop'    => array( 0, 0 ),
			'a/to-b'      => array( strlen( 'b bytes' ), 1 ),
			'b/to-a'      => array( strlen( 'a bytes' ), 1 ),
			'temp-alias.txt' => array( 0, 0 ),
		);
		foreach ( $cases as $path => $effect ) {
			$target = readlink( $tree . '/' . $path );
			unlink( $tree . '/' . $path );
			try {
				$this->assertSame(
					array( 'bytes' => $expected['bytes'] - $effect[0], 'count' => $expected['count'] - $effect[1] ),
					$method->invoke( null, $tree ),
					$path
				);
			} finally {
				symlink( $target, $tree . '/' . $path );
			}
		}
		foreach ( array( '.mainwp-restore-abc123.tmp', '.mainwp-restore-ab12-cd34.tmp' ) as $name ) {
			file_put_contents( $tree . '/' . $name, 'excluded temp bytes' );
			$this->assertSame( $expected, $method->invoke( null, $tree ), $name );
		}
		$this->assertSame( array(), MainWP_Backup::get_skipped_paths() );
	}

	public static function inventory_depths() {
		return array(
			'at cutoff' => array( MainWP_Backup::MAX_INVENTORY_DEPTH, false ),
			'beyond cutoff' => array( MainWP_Backup::MAX_INVENTORY_DEPTH + 1, true ),
		);
	}

	/** @dataProvider inventory_depths */
	public function test_directory_inventory_depth_limit( $max_depth, $truncated ) {
		$method = new ReflectionMethod( MainWP_Backup::class, 'get_directory_inventory' );
		$method->setAccessible( true );
		$dir = $this->scratch;
		for ( $depth = 1; $depth <= $max_depth; ++$depth ) {
			$dir .= '/d';
			mkdir( $dir );
		}
		file_put_contents( $dir . '/file.txt', 'contents' );
		$this->assertSame( array( 'bytes' => $truncated ? 0 : 8, 'count' => $truncated ? 0 : 1 ), $method->invoke( null, $this->scratch ) );
		$this->assertSame( $truncated ? array( $dir ) : array(), MainWP_Backup::get_skipped_paths() );
	}

	public function test_directory_inventory_skips_unreadable_directories() {
		$method = new ReflectionMethod( MainWP_Backup::class, 'get_directory_inventory' );
		$method->setAccessible( true );
		$this->assertSame( array( 'bytes' => 0, 'count' => 0 ), $method->invoke( null, $this->scratch . '/missing' ) );
		mkdir( $this->scratch . '/unreadable' );
		file_put_contents( $this->scratch . '/unreadable/hidden.txt', 'hidden' );
		file_put_contents( $this->scratch . '/visible.txt', 'visible' );
		chmod( $this->scratch . '/unreadable', 0000 );
		try {
			if ( is_readable( $this->scratch . '/unreadable' ) ) {
				$this->markTestSkipped( 'This user can read directories with mode 0000.' );
			}
			$this->assertSame( array( 'bytes' => 7, 'count' => 1 ), $method->invoke( null, $this->scratch ) );
		} finally {
			chmod( $this->scratch . '/unreadable', 0755 );
		}
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_estimate_backup_size_includes_linked_content() {
		$content = $this->scratch . '/content';
		mkdir( $content );
		file_put_contents( $this->external . '/uploads/photo.txt', 'linked content bytes' );
		symlink( $this->external . '/uploads', $content . '/uploads' );
		define( 'MainWP\Child\WP_CONTENT_DIR', $content );
		delete_transient( 'mainwp_backup_content_inventory' );
		try {
			$db_only = MainWP_Backup::get()->estimate_backup_size( false );
			$this->assertSame( $db_only + strlen( 'linked content bytes' ), MainWP_Backup::get()->estimate_backup_size( true ) );
			$this->assertSame(
				array( 'bytes' => strlen( 'linked content bytes' ), 'count' => 1 ),
				get_transient( 'mainwp_backup_content_inventory' )
			);
		} finally {
			delete_transient( 'mainwp_backup_content_inventory' );
		}
	}

	/**
	 * Paths that resolve outside the allowed bases are rejected.
	 */
	public function test_rejects_paths_outside_base() {
		$this->assertFalse( MainWP_Backup::is_safe_backup_path( '/etc/hosts' ) );
		$this->assertFalse( MainWP_Backup::is_safe_backup_path( $this->external ) );
	}

	/**
	 * A sibling directory sharing the base as a string prefix is rejected.
	 *
	 * realpath() has no trailing slash, so a plain strpos prefix check passes
	 * for /base-evil when the base is /base. The trailing-slash compare closes
	 * that hole.
	 */
	public function test_rejects_sibling_prefix_directory() {
		$base = $this->scratch . '/backup-abc123';
		$evil = $this->scratch . '/backup-abc123-evil';
		mkdir( $base );
		mkdir( $evil );
		file_put_contents( $evil . '/file.txt', 'evil' );

		$this->assertFalse( MainWP_Backup::is_safe_backup_path( $evil . '/file.txt', array( $base ) ) );
		$this->assertTrue( MainWP_Backup::is_safe_backup_path( $base, array( $base ) ) );
	}

	/**
	 * Nonexistent paths are rejected (realpath fails).
	 */
	public function test_rejects_nonexistent_paths() {
		$this->assertFalse( MainWP_Backup::is_safe_backup_path( $this->scratch . '/does-not-exist' ) );
		$this->assertFalse( MainWP_Backup::is_safe_backup_path( '' ) );
	}

	/**
	 * Skipped paths are recorded for the backup report, with a cap.
	 */
	public function test_skipped_paths_are_recorded_and_capped() {
		for ( $i = 0; $i < 150; $i++ ) {
			MainWP_Backup::record_skipped_path( '/tmp/skipped-' . $i );
		}
		$skipped = MainWP_Backup::get_skipped_paths();
		$this->assertContains( '/tmp/skipped-0', $skipped );
		$this->assertLessThanOrEqual( 100, count( $skipped ) );
	}

	private function backup() {
		return new class() extends MainWP_Backup {
			public function extend_archive_mutex_lease() {}
		};
	}

	private function assert_link_entries( $entries, $tree ) {
		$this->assertSame( 'target bytes', isset( $entries['linked.txt'] ) ? $entries['linked.txt'] : null );
		$this->assertArrayNotHasKey( 'dir/.mainwp-restore-abc123.tmp', $entries );
		$this->assertArrayNotHasKey( 'linked-dir/.mainwp-restore-abc123.tmp', $entries );
		$this->assertArrayNotHasKey( 'temp-alias.txt', $entries );
		// copy_dir() walks with glob(), which never matches a leading dot, so the
		// legacy copy engine sees no dotfile at all. Only assert the non-hex
		// dotfile survives on the engines that can see it.
		if ( array_key_exists( 'dir/.htaccess-sentinel', $entries ) ) {
			$this->assertSame( 'ordinary dotfile', $entries['dir/.mainwp-restore-nothex.tmp'] );
		}
		$this->assertSame( 'directory bytes', isset( $entries['linked-dir/inner.txt'] ) ? $entries['linked-dir/inner.txt'] : null );
		$this->assertSame( 'upload bytes', isset( $entries['uploads/photo.txt'] ) ? $entries['uploads/photo.txt'] : null );
		foreach ( array( 'outside.txt', 'outside-dir', 'dangling', 'dir/loop', 'linked-dir/loop', 'a/to-b/to-a', 'b/to-a/to-b' ) as $refused ) {
			$this->assertContains( $tree . '/' . $refused, MainWP_Backup::get_skipped_paths(), $refused );
			foreach ( array_keys( $entries ) as $entry ) {
				$this->assertFalse( $entry === $refused || 0 === strpos( $entry, $refused . '/' ), $entry );
			}
		}
	}

	private function excluded_link_tree( $tree ) {
		$excluded = $this->scratch . '/excluded';
		mkdir( $excluded );
		file_put_contents( $excluded . '/backup.txt', 'excluded backup bytes' );
		symlink( $excluded, $tree . '/backup-link' );
		return array( str_replace( ABSPATH, '', $excluded ) );
	}

	private function assert_excluded_link_absent( $entries ) {
		foreach ( array_keys( $entries ) as $entry ) {
			$this->assertFalse( 'backup-link' === rtrim( $entry, '/' ) || 0 === strpos( $entry, 'backup-link/' ), $entry );
		}
	}

	public static function full_engines() {
		return array( 'zip' => array( 'zip' ), 'tar' => array( 'tar' ) );
	}

	/** @dataProvider full_engines */
	public function test_full_engine_excludes_root_file_link_target( $format ) {
		$tree = $this->link_tree();
		$excludes = $this->excluded_link_tree( $tree );
		$link = ABSPATH . 'mwp-root-link-' . uniqid() . '.txt';
		$keep = ABSPATH . 'mwp-root-keep-' . uniqid() . '.txt';
		symlink( $this->scratch . '/excluded/backup.txt', $link );
		file_put_contents( $keep, 'included bytes' );
		try {
			foreach ( glob( ABSPATH . '*' ) as $node ) {
				if ( $node !== $link && $node !== $keep ) {
					$excludes[] = str_replace( ABSPATH, '', $node );
				}
			}
			$backup = new class() extends MainWP_Backup {
				public function extend_archive_mutex_lease() {}
				public function get_or_create_db_dump_files( $filepath_prefix, &$archiver = null ) {
					return array();
				}
			};
			$archive = $this->external . '/full.' . $format;
			if ( 'zip' === $format ) {
				$this->assertTrue( $backup->create_zip_full_backup( $archive, $excludes, false, true, false, false ) );
				$zip = new ZipArchive();
				$this->assertTrue( $zip->open( $archive ) );
				$this->assertFalse( $zip->locateName( basename( $link ) ) );
				$this->assertSame( 'included bytes', $zip->getFromName( basename( $keep ) ) );
				$zip->close();
			} else {
				$tar = new Tar_Archiver( $backup, 'tar' );
				$this->assertTrue( $tar->create_full_backup( $archive, $excludes, false, true, false, false ) );
				$phar = new PharData( $archive );
				$this->assertFalse( isset( $phar[ basename( $link ) ] ) );
				$this->assertSame( 'included bytes', $phar[ basename( $keep ) ]->getContent() );
			}
		} finally {
			unlink( $link );
			unlink( $keep );
		}
	}

	public static function root_suffixes() {
		return array( 'no trailing slash' => array( '' ), 'trailing slash' => array( '/' ) );
	}

	/**
	 * @dataProvider root_suffixes
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_exclusion_helper_matches_resolved_root( $suffix ) {
		$root = $this->scratch . '/real-root';
		mkdir( $root . '/excluded', 0755, true );
		file_put_contents( $root . '/excluded/backup.txt', 'excluded bytes' );
		file_put_contents( $root . '/keep.txt', 'included bytes' );
		$link = $this->scratch . '/linked-root';
		symlink( $root, $link );
		symlink( $root . '/excluded/backup.txt', $root . '/alias.txt' );
		// Isolate the helper's root spelling without moving the WordPress install.
		define( 'MainWP\\Child\\ABSPATH', $link . $suffix );
		$this->assertNotSame( $link . '/', trailingslashit( realpath( $link ) ) );
		$excludes = array( 'excluded' );
		$this->assertTrue( MainWP_Backup::excluded_by_path_or_target( $excludes, $link . '/excluded/backup.txt' ) );
		$this->assertTrue( MainWP_Backup::excluded_by_path_or_target( $excludes, $link . '/alias.txt' ) );
		$this->assertTrue( MainWP_Backup::excluded_by_path_or_target( $excludes, realpath( $root . '/excluded/backup.txt' ) ) );
		$this->assertFalse( MainWP_Backup::excluded_by_path_or_target( $excludes, $link . '/keep.txt' ) );
	}

	public function test_zip_descends_links_and_records_refusals() {
		if ( ! class_exists( 'ZipArchive' ) ) {
			$this->markTestSkipped( 'ZipArchive unavailable.' );
		}
		$tree = $this->link_tree();
		$excludes = $this->excluded_link_tree( $tree );
		$backup = $this->backup();
		$zip = new ZipArchive();
		$archive = $this->external . '/test.zip';
		$this->assertTrue( $zip->open( $archive, ZipArchive::CREATE ) );
		$slot = new ReflectionProperty( MainWP_Backup::class, 'zip' );
		$slot->setAccessible( true );
		$slot->setValue( $backup, $zip );
		$backup->zip_add_dir( $tree, $excludes );
		$this->assertTrue( $zip->close() );
		$this->assertTrue( $zip->open( $archive ) );
		$prefix = str_replace( ABSPATH, '', $tree ) . '/';
		$entries = array();
		for ( $i = 0; $i < $zip->numFiles; ++$i ) {
			$entries[ substr( $zip->getNameIndex( $i ), strlen( $prefix ) ) ] = $zip->getFromIndex( $i );
		}
		$zip->close();
		$this->assert_link_entries( $entries, $tree );
		$this->assert_excluded_link_absent( $entries );
	}

	public function test_tar_descends_links_and_records_refusals() {
		$tree = $this->link_tree();
		$excludes = $this->excluded_link_tree( $tree );
		$archive = $this->external . '/test.tar';
		$tar = new Tar_Archiver( $this->backup(), 'tar' );
		$tar->create( $archive );
		$tar->add_dir( $tree, $excludes );
		$tar->close();
		$prefix = str_replace( ABSPATH, '', $tree ) . '/';
		$entries = array();
		$handle = fopen( $archive, 'rb' );
		while ( 512 === strlen( $header = fread( $handle, 512 ) ) && trim( $header, "\0" ) !== '' ) {
			$name = rtrim( substr( $header, 0, 100 ), "\0" );
			$size = octdec( trim( substr( $header, 124, 12 ), " \0" ) );
			$data = $size > 0 ? fread( $handle, $size ) : '';
			$entries[ substr( $name, strlen( $prefix ) ) ] = $data;
			if ( $size % 512 ) {
				fseek( $handle, 512 - $size % 512, SEEK_CUR );
			}
		}
		fclose( $handle );
		$this->assert_link_entries( $entries, $tree );
		$this->assert_excluded_link_absent( $entries );
	}

	public function test_copy_descends_links_and_records_refusals() {
		$tree = $this->link_tree();
		$excludes = $this->excluded_link_tree( $tree );
		$out = $this->external . '/copy';
		$copied = $out . '/' . str_replace( ABSPATH, '', $tree );
		mkdir( dirname( $copied ), 0755, true );
		$this->backup()->copy_dir( array( $tree ), $excludes, $out . '/', false );
		$entries = array();
		$files = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $copied, FilesystemIterator::SKIP_DOTS ) );
		foreach ( $files as $file ) {
			if ( $file->isFile() ) {
				$entries[ substr( $file->getPathname(), strlen( $copied ) + 1 ) ] = file_get_contents( $file->getPathname() );
			}
		}
		$this->assert_link_entries( $entries, $tree );
		$this->assert_excluded_link_absent( $entries );
	}

	public function test_walker_descends_links_and_records_refusals_after_resume() {
		$tree = $this->link_tree();
		$out = $this->external . '/sidecars';
		mkdir( $out );
		$cursor = null;
		$done = false;
		for ( $slice = 0; $slice < 100 && ! $done; ++$slice ) {
			$walker = new class( $tree ) extends MainWP_Child_Backup_File_Walker {
				private $steps = 0;
				protected function walk_budget_exhausted( $deadline ) {
					return ++$this->steps > 2;
				}
			};
			$result = $walker->emit_sidecars_slice( $out, $cursor, PHP_INT_MAX );
			$cursor = $result['cursor'];
			$done = $result['done'];
		}
		$this->assertTrue( $done, 'Cycles must terminate across resumed slices.' );
		$entries = array();
		foreach ( file( $out . '/files.list.staged', FILE_IGNORE_NEW_LINES ) as $line ) {
			$row = explode( "\t", $line );
			$entries[ $row[0] ] = file_get_contents( $tree . '/' . $row[0] );
		}
		$this->assert_link_entries( $entries, $tree );
	}
}
