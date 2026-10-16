<?php
/**
 * Class TarArchiverResumeConfigDuplicateTest
 *
 * @package Mainwp_Child
 */

use MainWP\Child\MainWP_Backup;
use MainWP\Child\MainWP_Backup_Budget_Exception;
use MainWP\Child\MainWP_Backup_Error;
use MainWP\Child\MainWP_Child_Backup_File_Walker;
use MainWP\Child\MainWP_Exception;
use MainWP\Child\MainWP_Helper;
use MainWP\Child\Tar_Archiver;

/**
 * A resumed full backup must hold one clone/config.txt.
 *
 * The task runner re-enters create_full_backup() in append mode whenever
 * archive_done was not checkpointed, so a finished archive (with or without
 * its 1024-byte end padding) can be appended to again. These cases drive
 * that re-entry through the real engine and count the clone entries in
 * every header block, including any written after the end padding, where
 * tar readers would stop and hide a duplicate.
 */
class TarArchiverResumeConfigDuplicateTest extends WP_UnitTestCase {

	/**
	 * Site-root fixture tree for archive payloads.
	 *
	 * @var string
	 */
	private $fixture;

	/**
	 * Scratch directory for the archives.
	 *
	 * @var string
	 */
	private $scratch;

	/**
	 * Original contents to restore when the testbed already has clone files.
	 *
	 * @var array
	 */
	private $clone_files = array();

	/**
	 * Whether cleanup owns the site-root clone directory.
	 *
	 * @var bool
	 */
	private $clone_dir_created = false;

	/**
	 * Build the fixture tree.
	 */
	public function set_up() {
		parent::set_up();
		$this->fixture = ABSPATH . 'mwp-cfg-dup-' . uniqid();
		$this->scratch = sys_get_temp_dir() . '/mwp-cfg-dup-' . uniqid();
		mkdir( $this->fixture . '/nested', 0777, true );
		mkdir( $this->scratch );
		for ( $i = 0; $i < 4; $i++ ) {
			file_put_contents( $this->fixture . '/file-' . $i . '.txt', str_repeat( "content-$i\n", 40 ) ); // phpcs:ignore -- test fixture.
		}
		file_put_contents( $this->fixture . '/nested/inner.txt', 'inner' ); // phpcs:ignore -- test fixture.
	}

	/**
	 * Remove fixtures and archives.
	 */
	public function tear_down() {
		foreach ( $this->clone_files as $path => $original ) {
			if ( null === $original ) {
				unlink( $path );
			} else {
				file_put_contents( $path, $original );
			}
		}
		if ( $this->clone_dir_created ) {
			rmdir( ABSPATH . 'clone' );
		}
		MainWP_Helper::delete_dir( $this->fixture );
		MainWP_Helper::delete_dir( $this->scratch );
		parent::tear_down();
	}

	/**
	 * Archive formats crossed with whether the end padding was written.
	 *
	 * @return array
	 */
	public function variants() {
		return array(
			'tar without end padding'    => array( 'tar', false ),
			'tar with end padding'       => array( 'tar', true ),
			'tar.gz without end padding' => array( 'tar.gz', false ),
			'tar.gz with end padding'    => array( 'tar.gz', true ),
		);
	}

	/**
	 * Both formats must replace the leftover config on a fresh pass.
	 *
	 * @return array
	 */
	public function archive_types() {
		return array(
			'tar'    => array( 'tar' ),
			'tar.gz' => array( 'tar.gz' ),
		);
	}

	/**
	 * A failed legacy clone can leave both metadata and unrelated site files.
	 */
	private function make_leftover_clone() {
		$dir = ABSPATH . 'clone';
		$this->assertFalse( is_link( $dir ), 'The clone fixture must stay inside the testbed.' );
		if ( ! is_dir( $dir ) ) {
			$this->assertTrue( mkdir( $dir ) );
			$this->clone_dir_created = true;
		}
		foreach ( array( 'config.txt' => '{"leftover":true}', 'notes.txt' => 'keep these notes' ) as $name => $contents ) {
			$path = $dir . '/' . $name;
			$this->assertFalse( is_link( $path ) || is_dir( $path ), 'A clone fixture must be an ordinary file.' );
			$original = is_file( $path ) ? file_get_contents( $path ) : null;
			$this->assertNotFalse( $original, 'Existing clone files must be readable before replacement.' );
			$this->clone_files[ $path ] = $original;
			$this->assertSame( strlen( $contents ), file_put_contents( $path, $contents ) );
		}
	}

	/**
	 * A backup engine that embeds no database dump, like a prepared empty set.
	 *
	 * @return MainWP_Backup
	 */
	private function backup() {
		return new class() extends MainWP_Backup {
			public function extend_archive_mutex_lease() {}
			public function get_or_create_db_dump_files( $prefix, &$archiver = null ) {
				return array();
			}
		};
	}

	/**
	 * Keep the walk isolated while allowing the leftover clone fixture.
	 *
	 * @return string[]
	 */
	private function excludes() {
		$excludes = array();
		foreach ( glob( ABSPATH . '*' ) as $node ) {
			if ( $node !== $this->fixture && ( empty( $this->clone_files ) || ABSPATH . 'clone' !== $node ) ) {
				$excludes[] = str_replace( ABSPATH, '', $node );
			}
		}
		if ( file_exists( ABSPATH . '.htaccess' ) ) {
			$excludes[] = '.htaccess';
		}
		return $excludes;
	}

	/**
	 * Core files stay enabled to match run_file_archiver().
	 *
	 * @param string   $type       Archive type.
	 * @param string   $path       Archive path.
	 * @param bool     $append     Append mode.
	 * @param int|null $deadline   Slice deadline.
	 * @param bool     $add_config Whether to append generated clone metadata.
	 *
	 * @return Tar_Archiver The archiver, for cleanup after a throw.
	 */
	private function run_pass( $type, $path, $append, $deadline = null, $add_config = true ) {
		$archiver = new Tar_Archiver( $this->backup(), $type );
		$archiver->set_budget_deadline( $deadline );
		try {
			$archiver->create_full_backup( $path, $this->excludes(), $add_config, true, false, false, $append );
		} catch ( \Exception $e ) {
			$archiver->close();
			throw $e;
		}
		return $archiver;
	}

	/**
	 * Path of the archive for a type.
	 *
	 * @param string $type Archive type.
	 *
	 * @return string
	 */
	private function archive_path( $type ) {
		return $this->scratch . '/backup-resume.' . $type;
	}

	/**
	 * Decoded tar bytes, across every gzip member.
	 *
	 * @param string $type Archive type.
	 * @param string $path Archive path.
	 *
	 * @return string
	 */
	private function tar_bytes( $type, $path ) {
		if ( 'tar' === $type ) {
			return file_get_contents( $path ); // phpcs:ignore -- test reader.
		}
		$stream = gzopen( $path, 'rb' );
		$bytes  = stream_get_contents( $stream );
		gzclose( $stream );
		return $bytes;
	}

	/**
	 * Write decoded tar bytes back as one complete archive of the given type.
	 *
	 * @param string $type  Archive type.
	 * @param string $path  Archive path.
	 * @param string $bytes Tar bytes.
	 */
	private function write_tar_bytes( $type, $path, $bytes ) {
		file_put_contents( $path, 'tar' === $type ? $bytes : gzencode( $bytes ) ); // phpcs:ignore -- test fixture.
	}

	/**
	 * Every header name in the tar stream. Zero blocks are skipped, not
	 * treated as the end, so entries written after the end padding count.
	 *
	 * @param string     $bytes    Tar bytes.
	 * @param array|null $contents File contents grouped by name, preserving duplicates.
	 *
	 * @return string[]
	 */
	private function header_names( $bytes, &$contents = null ) {
		$names    = array();
		$contents = array();
		$length   = strlen( $bytes );
		$offset   = 0;
		while ( $offset + 512 <= $length ) {
			$header  = substr( $bytes, $offset, 512 );
			$offset += 512;
			if ( str_repeat( "\0", 512 ) === $header ) {
				continue;
			}
			$name   = rtrim( substr( $header, 0, 100 ), "\0 " );
			$prefix = rtrim( substr( $header, 345, 155 ), "\0 " );
			$size   = (int) octdec( trim( substr( $header, 124, 12 ), "\0 " ) );
			if ( 'L' === $header[156] ) {
				$name    = rtrim( substr( $bytes, $offset, 512 ), "\0 " );
				$offset += 512;
				$header  = substr( $bytes, $offset, 512 );
				$offset += 512;
				$size    = (int) octdec( trim( substr( $header, 124, 12 ), "\0 " ) );
				$prefix  = '';
			}
			$names[] = $prefix . $name;
			if ( '0' === $header[156] || "\0" === $header[156] ) {
				$contents[ $prefix . $name ][] = substr( $bytes, $offset, $size );
			}
			$offset += (int) ( ceil( $size / 512 ) * 512 );
		}
		return $names;
	}

	/**
	 * How many times an entry name appears.
	 *
	 * @param string[] $names Header names.
	 * @param string   $entry Entry name.
	 *
	 * @return int
	 */
	private function count_entry( $names, $entry ) {
		return count(
			array_filter(
				$names,
				function ( $name ) use ( $entry ) {
					return $name === $entry;
				}
			)
		);
	}

	/**
	 * Stale config must never win the legacy clone reader's first-match lookup.
	 *
	 * @param string $contents Archived config bytes.
	 */
	private function assert_generated_config( $contents ) {
		global $wpdb;
		$config = json_decode( $contents, true );
		$this->assertIsArray( $config, 'The generated config must be JSON.' );
		$this->assertSame( get_option( 'siteurl' ), $config['siteurl'] );
		$this->assertSame( get_option( 'home' ), $config['home'] );
		$this->assertSame( ABSPATH, $config['abspath'] );
		$this->assertSame( $wpdb->prefix, $config['prefix'] );
		$this->assertSame( get_bloginfo( 'language' ), $config['lang'] );
		$this->assertIsArray( $config['plugins'] );
		$this->assertIsArray( $config['themes'] );
		$this->assertArrayNotHasKey( 'leftover', $config );
	}

	/**
	 * The walk must not shadow the config consumed by legacy clone readers.
	 *
	 * @dataProvider archive_types
	 * @param string $type Archive type.
	 */
	public function test_fresh_backup_uses_generated_config_over_leftover( $type ) {
		$this->make_leftover_clone();
		$path = $this->archive_path( $type );
		$this->run_pass( $type, $path, false );

		$contents = array();
		$names    = $this->header_names( $this->tar_bytes( $type, $path ), $contents );
		$this->assertSame( 1, $this->count_entry( $names, 'clone/config.txt' ) );
		$this->assert_generated_config( $contents['clone/config.txt'][0] );
		$this->assertSame( '{"leftover":true}', file_get_contents( ABSPATH . 'clone/config.txt' ), 'Archiving must preserve the source file.' );
	}

	/**
	 * A real clone folder can contain site files that still need backup.
	 *
	 * @dataProvider archive_types
	 * @param string $type Archive type.
	 */
	public function test_generated_config_keeps_other_clone_files( $type ) {
		$this->make_leftover_clone();
		$path = $this->archive_path( $type );
		$this->run_pass( $type, $path, false );

		$contents = array();
		$names    = $this->header_names( $this->tar_bytes( $type, $path ), $contents );
		$this->assertSame( 1, $this->count_entry( $names, 'clone/notes.txt' ) );
		$this->assertSame( array( 'keep these notes' ), $contents['clone/notes.txt'] );
	}

	/**
	 * Without generated metadata the leftover config remains an ordinary file.
	 *
	 * @dataProvider archive_types
	 * @param string $type Archive type.
	 */
	public function test_without_generated_config_archives_leftover( $type ) {
		$this->make_leftover_clone();
		$path = $this->archive_path( $type );
		$this->run_pass( $type, $path, false, null, false );

		$contents = array();
		$names    = $this->header_names( $this->tar_bytes( $type, $path ), $contents );
		$this->assertSame( 1, $this->count_entry( $names, 'clone/config.txt' ) );
		$this->assertSame( array( '{"leftover":true}' ), $contents['clone/config.txt'] );
	}

	/**
	 * Re-entry must keep the generated config even while the leftover still exists.
	 *
	 * @dataProvider variants
	 * @param string $type Archive type.
	 * @param bool   $padding Whether the first pass kept its end padding.
	 */
	public function test_resume_with_leftover_clone_keeps_one_generated_config( $type, $padding ) {
		$this->make_leftover_clone();
		$path = $this->archive_path( $type );
		$this->run_pass( $type, $path, false );
		if ( ! $padding ) {
			$this->write_tar_bytes( $type, $path, substr( $this->tar_bytes( $type, $path ), 0, -1024 ) );
		}
		$this->run_pass( $type, $path, true );

		$contents = array();
		$names    = $this->header_names( $this->tar_bytes( $type, $path ), $contents );
		$this->assertSame( 1, $this->count_entry( $names, 'clone/config.txt' ) );
		$this->assert_generated_config( $contents['clone/config.txt'][0] );
		$this->assertSame( array( 'keep these notes' ), $contents['clone/notes.txt'] );
	}

	/**
	 * Incremental manifests must describe site files without stale clone metadata.
	 */
	public function test_manifest_omits_generated_config_path_only() {
		$this->make_leftover_clone();
		mkdir( $this->fixture . '/nested/clone' );
		file_put_contents( $this->fixture . '/nested/clone/config.txt', 'ordinary nested config' );
		$walker = new MainWP_Child_Backup_File_Walker( ABSPATH, $this->excludes() );
		$walker->emit_sidecars( $this->scratch );

		$paths = array();
		foreach ( file( $this->scratch . '/files.list.staged', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES ) as $line ) {
			$paths[] = explode( "\t", $line )[0];
		}
		$this->assertNotContains( 'clone/config.txt', $paths );
		$this->assertContains( 'clone/notes.txt', $paths );
		$this->assertContains( str_replace( ABSPATH, '', $this->fixture ) . '/nested/clone/config.txt', $paths );

		$pack_paths = array();
		foreach ( file( $this->scratch . '/paths.cache.staged', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES ) as $line ) {
			$pack_paths[] = explode( "\t", $line )[1];
		}
		$this->assertSame( $paths, $pack_paths, 'Incremental packing must use the same paths as the manifest.' );

		$path = $this->archive_path( 'tar' );
		$this->run_pass( 'tar', $path, false );
		$contents = array();
		$this->header_names( $this->tar_bytes( 'tar', $path ), $contents );
		$this->assert_generated_config( $contents['clone/config.txt'][0] );
		$site_files = array_values( array_diff( array_keys( $contents ), array( 'clone/config.txt' ) ) );
		sort( $site_files, SORT_STRING );
		$this->assertSame( $paths, $site_files, 'The manifest must describe every archived site file.' );
	}

	/**
	 * Build a finished archive that ends with clone/ and clone/config.txt,
	 * optionally without the end padding a killed slice never wrote.
	 *
	 * @param string $type    Archive type.
	 * @param bool   $padding Keep the 1024-byte end padding.
	 *
	 * @return string The decoded tar bytes as built.
	 */
	private function build_finished_archive( $type, $padding ) {
		$path = $this->archive_path( $type );
		$this->run_pass( $type, $path, false );
		$bytes = $this->tar_bytes( $type, $path );
		$this->assertSame( str_repeat( "\0", 1024 ), substr( $bytes, -1024 ), 'A finished pass ends with the end padding.' );
		if ( ! $padding ) {
			$bytes = substr( $bytes, 0, -1024 );
			$this->write_tar_bytes( $type, $path, $bytes );
		}
		$names = $this->header_names( $bytes );
		$this->assertSame( array( 'clone', 'clone/config.txt' ), array_slice( $names, -2 ), 'The archive must end with the clone entries.' );
		return $bytes;
	}

	/**
	 * Re-entering the archiver on a finished archive, with the walk unchanged,
	 * keeps one clone/config.txt. This is the slice that died after the
	 * archive closed but before archive_done was checkpointed.
	 *
	 * @dataProvider variants
	 *
	 * @param string $type    Archive type.
	 * @param bool   $padding Whether the end padding was written.
	 */
	public function test_rerun_on_finished_archive_keeps_one_config( $type, $padding ) {
		$before = $this->build_finished_archive( $type, $padding );
		$path   = $this->archive_path( $type );

		$this->run_pass( $type, $path, true );

		$after = $this->tar_bytes( $type, $path );
		$names = $this->header_names( $after );
		$this->assertSame( $before, substr( $after, 0, strlen( $before ) ), 'The archived prefix must survive unchanged.' );
		$this->assertSame( 1, $this->count_entry( $names, 'clone' ) );
		$this->assertSame( 1, $this->count_entry( $names, 'clone/config.txt' ) );
		$this->assertSame( str_repeat( "\0", strlen( $after ) - strlen( $before ) ), substr( $after, strlen( $before ) ), 'Only end padding may follow the archived entries.' );
	}

	/**
	 * A file whose contents and size changed between passes matches by name,
	 * so the re-entry still writes no second config.
	 *
	 * @dataProvider variants
	 *
	 * @param string $type    Archive type.
	 * @param bool   $padding Whether the end padding was written.
	 */
	public function test_rerun_after_changed_file_keeps_one_config( $type, $padding ) {
		$this->build_finished_archive( $type, $padding );
		$path = $this->archive_path( $type );
		file_put_contents( $this->fixture . '/file-1.txt', str_repeat( 'grown ', 400 ) ); // phpcs:ignore -- test fixture.

		$this->run_pass( $type, $path, true );

		$names = $this->header_names( $this->tar_bytes( $type, $path ) );
		$this->assertSame( 1, $this->count_entry( $names, 'clone/config.txt' ) );
	}

	/**
	 * A walk that diverges before the clone entries (a file added or removed
	 * between passes) refuses the archive as corrupt rather than appending
	 * a second config after the first.
	 *
	 * @dataProvider variants
	 *
	 * @param string $type    Archive type.
	 * @param bool   $padding Whether the end padding was written.
	 */
	public function test_rerun_after_divergent_walk_never_adds_a_second_config( $type, $padding ) {
		$path = $this->archive_path( $type );
		foreach ( array( 'added', 'removed' ) as $change ) {
			$before = $this->build_finished_archive( $type, $padding );
			if ( 'added' === $change ) {
				file_put_contents( $this->fixture . '/zz-new.txt', 'new' ); // phpcs:ignore -- test fixture.
			} else {
				unlink( $this->fixture . '/file-3.txt' );
			}

			$caught = null;
			try {
				$this->run_pass( $type, $path, true );
			} catch ( MainWP_Exception $e ) {
				$caught = $e;
			}

			$this->assertInstanceOf( MainWP_Exception::class, $caught, "A $change file must stop the resume." );
			$this->assertSame( MainWP_Backup_Error::ERR_FILE_CORRUPT, $caught->get_message_error_code() );
			$names = $this->header_names( $this->tar_bytes( $type, $path ) );
			$this->assertSame( 1, $this->count_entry( $names, 'clone/config.txt' ), "A $change file must not add a second config." );
			$this->assertSame( $before, $this->tar_bytes( $type, $path ), 'A refused resume must leave the archive untouched.' );

			if ( 'added' === $change ) {
				unlink( $this->fixture . '/zz-new.txt' );
			} else {
				file_put_contents( $this->fixture . '/file-3.txt', str_repeat( "content-3\n", 40 ) ); // phpcs:ignore -- test fixture.
			}
			unlink( $path );
		}
	}

	/**
	 * The full slice sequence: a budget yield mid-walk, an append slice that
	 * finishes the archive, then a re-entry that lost archive_done. Each
	 * resume opens a new gzip member for tar.gz, as in production.
	 *
	 * @dataProvider variants
	 *
	 * @param string $type    Archive type.
	 * @param bool   $padding Whether the finishing slice's end padding survived.
	 */
	public function test_yield_finish_then_rerun_keeps_one_config( $type, $padding ) {
		$path   = $this->archive_path( $type );
		$caught = null;
		try {
			$this->run_pass( $type, $path, false, time() - 1 );
		} catch ( MainWP_Backup_Budget_Exception $e ) {
			$caught = $e;
		}
		$this->assertInstanceOf( MainWP_Backup_Budget_Exception::class, $caught, 'The first slice must yield mid-walk.' );
		$this->assertSame( 0, $this->count_entry( $this->header_names( $this->tar_bytes( $type, $path ) ), 'clone/config.txt' ) );

		$this->run_pass( $type, $path, true );
		$finished = $this->tar_bytes( $type, $path );
		$this->assertSame( 1, $this->count_entry( $this->header_names( $finished ), 'clone/config.txt' ) );
		if ( ! $padding ) {
			$this->write_tar_bytes( $type, $path, substr( $finished, 0, -1024 ) );
		}

		$this->run_pass( $type, $path, true );

		$names = $this->header_names( $this->tar_bytes( $type, $path ) );
		$this->assertSame( 1, $this->count_entry( $names, 'clone' ) );
		$this->assertSame( 1, $this->count_entry( $names, 'clone/config.txt' ) );
		foreach ( array( 'file-0.txt', 'file-1.txt', 'file-2.txt', 'file-3.txt', 'nested/inner.txt' ) as $file ) {
			$this->assertSame( 1, $this->count_entry( $names, str_replace( ABSPATH, '', $this->fixture ) . '/' . $file ), $file );
		}
	}
}
