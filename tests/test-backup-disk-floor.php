<?php
/**
 * Disk headroom across backup writers.
 *
 * @package Mainwp_Child
 */

use MainWP\Child\MainWP_Backup;
use MainWP\Child\MainWP_Backup_Db_Dumper;
use MainWP\Child\MainWP_Backup_Error;
use MainWP\Child\MainWP_Child_Backup_Incremental_Zip_Writer;
use MainWP\Child\MainWP_Child_Backup_Task_Runner;
use MainWP\Child\MainWP_Exception;
use MainWP\Child\MainWP_Helper;
use MainWP\Child\Tar_Archiver;

class Disk_Floor_Test_Backup extends MainWP_Backup {
	public $after_add;
	public $zip_supported = true;
	public function heartbeat() {}
	public function extend_archive_mutex_lease() {}
	public function check_zip_support() { return $this->zip_supported; }
	public function allow( $dir ) { $this->allowed_backup_bases = array( $dir ); }
	public function zip_handle() { return $this->zip; }
	public function tar_handle() { return $this->archiver; }
	public function add_file_to_zipp( $path, $entry ) {
		$result = parent::add_file_to_zipp( $path, $entry );
		if ( null !== $this->after_add ) { call_user_func( $this->after_add, $this ); }
		return $result;
	}
}

class Disk_Floor_Pcl_Backup extends Disk_Floor_Test_Backup {
	public $source_dir;
	public $make_content = true;
	private $started = false;
	public function check_zip_support() { return false; }
	public function copy_dir( $nodes, $excludes, $backupfolder, $excludenonwp, $ancestors = array() ) {
		if ( ! $this->started ) {
			$this->started = true;
			if ( $this->make_content ) { mkdir( $backupfolder . basename( WP_CONTENT_DIR ) ); }
			$nodes = array( $this->source_dir );
		}
		parent::copy_dir( $nodes, array(), $backupfolder, false, array( ABSPATH ) );
	}
}

class Disk_Floor_Stream_Dumper extends MainWP_Backup_Db_Dumper {
	public $bytes = 33554432;
	protected function tier2_dump_chunk( $table, $tmp, &$cursor, $heartbeat = null ) {
		$handle = fopen( $tmp, 'ab' );
		try {
			$this->write_checked( $handle, str_repeat( 's', $this->bytes ), $heartbeat );
		} finally {
			fclose( $handle );
		}
		return true;
	}
}

class BackupDiskFloorTest extends WP_UnitTestCase {
	private $dir;
	private $src;
	private $probe;
	private $extra_paths = array();
	private const MB = 1048576;
	private const GB = 1073741824;

	public function set_up() {
		parent::set_up();
		$this->dir = wp_upload_dir()['basedir'] . '/disk-floor-' . uniqid();
		$this->src = ABSPATH . 'disk-floor-source-' . uniqid();
		mkdir( $this->dir, 0777, true );
		mkdir( $this->src );
		$this->probe = new ReflectionProperty( MainWP_Backup::class, 'disk_space_probe' );
		$this->probe->setAccessible( true );
		$this->space( 20 * self::GB, 10 * self::GB );
	}

	public function tear_down() {
		$this->probe->setValue( null, null );
		gc_collect_cycles();
		foreach ( array_merge( $this->extra_paths, array( $this->dir, $this->src ) ) as $path ) {
			if ( is_dir( $path ) ) { MainWP_Helper::delete_dir( $path ); }
			elseif ( is_file( $path ) ) { unlink( $path ); }
		}
		parent::tear_down();
	}

	private function space( $total, $free ) {
		$this->probe->setValue( null, static function ( $function, $directory ) use ( $total, $free ) {
			return 'disk_total_space' === $function ? $total : $free;
		} );
	}

	private function source( $name, $bytes = 100 ) {
		$path = $this->src . '/' . $name;
		file_put_contents( $path, str_repeat( 'x', $bytes ) );
		return $path;
	}

	private function disk_error( $operation ) {
		try {
			$operation();
			$this->fail( 'Expected a disk-floor refusal.' );
		} catch ( MainWP_Exception $e ) {
			$this->assertSame( MainWP_Backup_Error::ERR_DISK_FULL, $e->get_message_error_code() );
			return $e;
		}
	}

	private function backup() {
		if ( ! class_exists( 'ZipArchive' ) ) { $this->markTestSkipped( 'ZipArchive unavailable.' ); }
		$backup = new Disk_Floor_Test_Backup();
		$backup->allow( $this->src );
		return $backup;
	}

	/** @dataProvider floor_sizes */
	public function test_floor_scales_with_disk_and_clamps( $total, $expected ) {
		$this->space( $total, 10 * self::GB );
		$this->assertSame( $expected, MainWP_Backup::get_disk_space_floor( $this->dir ) );
	}

	public static function floor_sizes() {
		return array(
			'small' => array( 2000 * self::MB, 200 * self::MB ),
			'two-gib' => array( 2 * self::GB, (int) ( 2 * self::GB / 10 ) ),
			'large' => array( 20 * self::GB, self::GB ),
			'huge' => array( 2048 * self::GB, self::GB ),
			'tiny' => array( 500 * self::MB, 100 * self::MB ),
		);
	}

	/** @dataProvider unavailable_readings */
	public function test_unavailable_total_uses_minimum_floor( $reading ) {
		$this->space( $reading, 99 * self::MB );
		$this->assertSame( 100 * self::MB, MainWP_Backup::get_disk_space_floor( $this->dir ) );
		$this->disk_error( function () { MainWP_Backup::assert_disk_space_during_backup( $this->dir ); } );
	}

	/** @dataProvider unavailable_readings */
	public function test_unavailable_free_disables_floor( $reading ) {
		$this->space( 20 * self::GB, $reading );
		MainWP_Backup::assert_disk_space_during_backup( $this->dir, PHP_INT_MAX, self::GB );
		$this->addToAssertionCount( 1 );
	}

	public static function unavailable_readings() {
		// The private probe returns false for both a disabled function and a failed read.
		return array( 'function-unavailable' => array( false ), 'probe-false' => array( false ) );
	}

	public function test_floor_message_reports_free_and_needed_and_keeps_registry() {
		$this->space( 20 * self::GB, self::GB + 100 );
		$e = $this->disk_error( function () {
			MainWP_Backup::assert_disk_space_during_backup( $this->dir, null, 256 * self::MB );
		} );
		$this->assertSame(
			sprintf( 'The backup stopped because the child site ran low on disk space. It had %1$s free and the backup needed at least %2$s. Free up disk space on the child site and try again.', esc_html( size_format( self::GB + 100, 1 ) ), esc_html( size_format( self::GB + 256 * self::MB, 1 ) ) ),
			$e->getMessage()
		);
		$this->assertSame( 'Could not write backup data. The server may be out of disk space. Free up disk space on the child site and try again.', MainWP_Backup_Error::message( MainWP_Backup_Error::ERR_DISK_FULL ) );

		// disk_free_space() returns a float; a full disk reads 0.0, which size_format() alone turns into false.
		$this->space( (float) ( 20 * self::GB ), 0.0 );
		$full = $this->disk_error( function () {
			MainWP_Backup::assert_disk_space_during_backup( $this->dir );
		} );
		$this->assertStringContainsString( 'It had 0.0 B free and the backup needed at least 1.0 GB.', $full->getMessage() );
	}

	public function test_explicit_minimum_and_equality_remain_supported() {
		$this->space( 20 * self::GB, 2048 );
		MainWP_Backup::assert_disk_space_during_backup( $this->dir, 1024 );
		MainWP_Backup::assert_disk_space_during_backup( $this->dir, 1024, 1024 );
		$this->disk_error( function () { MainWP_Backup::assert_disk_space_during_backup( $this->dir, PHP_INT_MAX ); } );
	}

	public function test_mb_zip_queued_bytes_refuse_before_next_add_and_discard() {
		$backup = $this->backup();
		$a = $this->source( 'a', 100 );
		$b = $this->source( 'b', 100 );
		$adds = 0;
		$weak = null;
		$backup->after_add = function ( $writer ) use ( &$adds, &$weak ) {
			++$adds;
			$weak = WeakReference::create( $writer->zip_handle() );
			$this->space( 20 * self::GB, self::GB + 150 );
		};
		$path = $this->dir . '/queued.zip';
		$this->disk_error( function () use ( $backup, $a, $b, $path ) { $backup->m_zip_file( array( $a, $b ), $path ); } );
		$this->assertSame( 1, $adds, 'The second entry must never be queued.' );
		$this->assertNull( $backup->zip_handle() );
		$this->assertNull( $weak->get(), 'No live handle may survive refusal.' );
		unset( $backup );
		gc_collect_cycles();
		$this->assertFileDoesNotExist( $path );
	}

	public function test_mb_zip_large_file_is_refused_at_its_add() {
		$backup = $this->backup();
		$file = $this->source( 'large', 1024 );
		$this->space( 20 * self::GB, self::GB + 1023 );
		$this->disk_error( function () use ( $backup, $file ) { $backup->m_zip_file( array( $file ), $this->dir . '/large.zip' ); } );
		$this->assertNull( $backup->zip_handle() );
		$this->assertFileDoesNotExist( $this->dir . '/large.zip' );
	}

	public function test_mb_zip_close_rechecks_pending_string_and_file_bytes() {
		$backup = $this->backup();
		$file = $this->source( 'file', 100 );
		$backup->after_add = function ( $writer ) {
			$writer->add_file_from_string_to_zip( 'config', str_repeat( 'c', 200 ) );
			$this->space( 20 * self::GB, self::GB + 299 );
		};
		$e = $this->disk_error( function () use ( $backup, $file ) { $backup->m_zip_file( array( $file ), $this->dir . '/close.zip' ); } );
		$this->assertStringContainsString( size_format( self::GB + 300, 1 ), $e->getMessage() );
		$this->assertNull( $backup->zip_handle() );
		unset( $backup );
		gc_collect_cycles();
		$this->assertFileDoesNotExist( $this->dir . '/close.zip' );
	}

	public function test_mb_zip_string_is_refused_before_it_is_queued() {
		$backup = $this->backup();
		$file = $this->source( 'file', 100 );
		$backup->after_add = function ( $writer ) {
			$this->space( 20 * self::GB, self::GB + 299 );
			$writer->add_file_from_string_to_zip( 'config', str_repeat( 'c', 200 ) );
		};
		$this->disk_error( function () use ( $backup, $file ) { $backup->m_zip_file( array( $file ), $this->dir . '/string.zip' ); } );
		$this->assertNull( $backup->zip_handle() );
		$this->assertFileDoesNotExist( $this->dir . '/string.zip' );
	}

	public function test_mb_zip_append_counts_rewrite_copy_and_destructor_preserves_prior_archive() {
		$backup = $this->backup();
		$file = $this->source( 'first' );
		$path = $this->dir . '/append.zip';
		$backup->m_zip_file( array( $file ), $path );
		$before = file_get_contents( $path );
		$file2 = $this->source( 'second' );
		$backup->after_add = function () use ( $before ) { $this->space( 20 * self::GB, self::GB + strlen( $before ) + 99 ); };
		$this->disk_error( function () use ( $backup, $file2, $path ) { $backup->m_zip_file( array( $file2 ), $path ); } );
		$this->assertSame( $before, file_get_contents( $path ) );
		$this->assertNull( $backup->zip_handle() );
		unlink( $path );
		unset( $backup );
		gc_collect_cycles();
		$this->assertFileDoesNotExist( $path );
	}

	public function test_real_zip_close_exposes_full_temporary_part_copy() {
		if ( PHP_VERSION_ID < 80000 || ! class_exists( 'ZipArchive' ) || ! method_exists( 'ZipArchive', 'registerProgressCallback' ) ) {
			$this->markTestSkipped( 'ZipArchive progress callbacks require PHP 8.0+.' );
		}
		$path = $this->dir . '/rewrite.zip';
		$zip = new ZipArchive();
		$this->assertTrue( $zip->open( $path, ZipArchive::CREATE ) );
		$zip->addFromString( 'prior', random_bytes( 2 * self::MB ) );
		$zip->setCompressionName( 'prior', ZipArchive::CM_STORE );
		$this->assertTrue( $zip->close() );
		$old_size = filesize( $path );
		$this->assertTrue( $zip->open( $path ) );
		$zip->addFromString( 'next', random_bytes( 2 * self::MB ) );
		$zip->setCompressionName( 'next', ZipArchive::CM_STORE );
		$observed = array();
		$this->assertTrue( $zip->registerProgressCallback( 0.001, function ( $progress ) use ( $path, &$observed ) {
			foreach ( (array) glob( $path . '.*.part' ) as $copy ) {
				clearstatcache( true, $copy );
				$observed[] = filesize( $copy );
			}
		} ) );
		$this->assertTrue( $zip->close() );
		$this->assertNotEmpty( $observed, 'close() must expose its .part rewrite in the same directory.' );
		$this->assertGreaterThanOrEqual( $old_size - 1024, max( $observed ), 'The temporary file includes the prior archive data.' );
		$this->assertSame( array(), glob( $path . '.*.part' ) );
	}

	public function test_db_only_fresh_request_enforces_floor_and_unlinks_partial() {
		$backup = $this->backup();
		$file = $this->source( 'dump.sql.php', 100 );
		$backup->after_add = function () { $this->space( 20 * self::GB, self::GB + 99 ); };
		$prefix = $this->dir . '/database';
		$slot = new ReflectionProperty( MainWP_Backup::class, 'backup_dir_in_use' );
		$slot->setAccessible( true );
		$this->assertEmpty( $slot->getValue( $backup ) );
		$this->disk_error( function () use ( $backup, $file, $prefix ) { $backup->archive_db_files( array( $file ), $prefix, 'zip' ); } );
		$this->assertNull( $backup->zip_handle() );
		$this->assertFileExists( $file );
		unset( $backup );
		gc_collect_cycles();
		$this->assertFileDoesNotExist( $prefix . '.sql.zip' );
	}

	public function test_full_backup_nonfloor_failure_releases_zip_and_tar_before_unlink() {
		$this->backup();
		$backup = new class extends Disk_Floor_Test_Backup {
			public $weak_zip;
			public $weak_tar;
			public $tar_path;
			public function get_or_create_db_dump_files( $prefix, &$archiver = null ) {
				$this->add_file_from_string_to_zip( 'queued', 'unflushed data' );
				$this->weak_zip = WeakReference::create( $this->zip );
				$this->archiver = new Tar_Archiver( $this, 'tar' );
				$this->tar_path = $prefix . '.open.tar';
				$this->archiver->create( $this->tar_path );
				$this->weak_tar = WeakReference::create( $this->archiver );
				throw new RuntimeException( 'source failure' );
			}
		};
		$name = 'disk-floor-cleanup-' . uniqid() . '.zip';
		$path = MainWP_Helper::get_mainwp_dir( 'backup' )[0] . $name;
		try {
			$backup->create_full_backup( array(), '', true, true, 0, $name );
			$this->fail( 'Expected the source failure.' );
		} catch ( RuntimeException $e ) {
			$this->assertSame( 'source failure', $e->getMessage() );
		}
		$this->extra_paths[] = $backup->tar_path;
		$this->assertNull( $backup->zip_handle() );
		$this->assertNull( $backup->tar_handle() );
		$this->assertNull( $backup->weak_zip->get() );
		$this->assertNull( $backup->weak_tar->get() );
		unset( $backup );
		gc_collect_cycles();
		$this->assertFileDoesNotExist( $path );
	}

	public function test_full_tar_failure_closes_stream_before_partial_unlink() {
		$backup = new class extends Disk_Floor_Test_Backup {
			public $weak_tar;
			public function get_or_create_db_dump_files( $prefix, &$archiver = null ) {
				$this->weak_tar = WeakReference::create( $archiver );
				$method = new ReflectionMethod( $archiver, 'add_file_from_string' );
				$method->setAccessible( true );
				$method->invoke( $archiver, 'queued', 'some tar bytes' );
				throw new RuntimeException( 'tar source failure' );
			}
		};
		$name = 'disk-floor-cleanup-' . uniqid() . '.tar';
		$path = MainWP_Helper::get_mainwp_dir( 'backup' )[0] . $name;
		$this->extra_paths[] = $path . '.log';
		try {
			$backup->create_full_backup( array(), '', true, true, 0, $name, false, false, true, 'tar' );
			$this->fail( 'Expected the tar source failure.' );
		} catch ( RuntimeException $e ) {
			$this->assertSame( 'tar source failure', $e->getMessage() );
		}
		$this->assertNull( $backup->tar_handle() );
		$retained = $backup->weak_tar->get();
		if ( null !== $retained ) { $this->assertFalse( $retained->is_open(), 'Any exception trace retaining the archiver must hold a closed stream.' ); }
		unset( $retained, $e, $backup );
		gc_collect_cycles();
		$this->assertFileDoesNotExist( $path );
	}

	public function test_incremental_add_and_close_enforce_pending_and_release_handles() {
		$this->backup();
		$file = $this->source( 'first' );
		$writer = new MainWP_Child_Backup_Incremental_Zip_Writer( $this->dir, 'core', null, null, '', array( $this->src ) );
		$writer->open_new_part( 1 );
		$writer->add_file( $file, 'first' );
		$this->space( 20 * self::GB, self::GB + 199 );
		$this->disk_error( function () use ( $writer, $file ) { $writer->add_file( $file, 'second' ); } );
		$this->assertFalse( $writer->is_open() );
		unset( $writer );
		gc_collect_cycles();
		$this->assertFileDoesNotExist( $this->dir . '/core.part001.zip' );

		$this->space( 20 * self::GB, 10 * self::GB );
		$writer = new MainWP_Child_Backup_Incremental_Zip_Writer( $this->dir, 'core', null, null, '', array( $this->src ) );
		$writer->open_new_part( 1 );
		$writer->add_file( $file, 'first' );
		$this->space( 20 * self::GB, self::GB + 99 );
		$this->disk_error( function () use ( $writer ) { $writer->close_part(); } );
		$this->assertFalse( $writer->is_open() );
		unset( $writer );
		gc_collect_cycles();
		$this->assertFileDoesNotExist( $this->dir . '/core.part001.zip' );
	}

	public function test_incremental_append_counts_copy_and_never_rewrites_refused_part() {
		$this->backup();
		$file = $this->source( 'first' );
		$writer = new MainWP_Child_Backup_Incremental_Zip_Writer( $this->dir, 'core', null, null, '', array( $this->src ) );
		$writer->open_new_part( 1 );
		$writer->add_file( $file, 'first' );
		$writer->close_part();
		$path = $this->dir . '/core.part001.zip';
		$before = file_get_contents( $path );
		$this->assertTrue( $writer->open_for_append( 1, 1, 100 ) );
		$this->space( 20 * self::GB, self::GB + strlen( $before ) + 99 );
		$this->disk_error( function () use ( $writer, $file ) { $writer->add_file( $file, 'second' ); } );
		$this->assertFalse( $writer->is_open() );
		$this->assertSame( $before, file_get_contents( $path ) );
		unlink( $path );
		unset( $writer );
		gc_collect_cycles();
		$this->assertFileDoesNotExist( $path );
	}

	public function test_incremental_append_close_discards_queued_entries_without_rewrite() {
		$this->backup();
		$file = $this->source( 'first' );
		$writer = new MainWP_Child_Backup_Incremental_Zip_Writer( $this->dir, 'core', null, null, '', array( $this->src ) );
		$writer->open_new_part( 1 );
		$writer->add_file( $file, 'first' );
		$writer->close_part();
		$path = $this->dir . '/core.part001.zip';
		$before = file_get_contents( $path );
		$this->assertTrue( $writer->open_for_append( 1, 1, 100 ) );
		$writer->add_file( $file, 'second' );
		$slot = new ReflectionProperty( $writer, 'zip' );
		$slot->setAccessible( true );
		$weak = WeakReference::create( $slot->getValue( $writer ) );
		$this->space( 20 * self::GB, self::GB + strlen( $before ) + 99 );
		$this->disk_error( function () use ( $writer ) { $writer->close_part(); } );
		$this->assertNull( $weak->get() );
		$this->assertSame( $before, file_get_contents( $path ) );
		unlink( $path );
		unset( $writer );
		gc_collect_cycles();
		$this->assertFileDoesNotExist( $path );
	}

	public function test_incremental_runner_discards_before_an_exception_unwinds_writer() {
		$this->backup();
		$this->source( 'first' );
		file_put_contents( $this->dir . '/changes.list', "first\t100\t123\n" );
		$runner = new class extends MainWP_Child_Backup_Task_Runner {
			public $root;
			protected function site_root() { return $this->root . '/'; }
			protected function dump_heartbeat() {
				$calls = 0;
				return static function () use ( &$calls ) {
					if ( ++$calls > 1 ) { throw new RuntimeException( 'slice interrupted' ); }
				};
			}
			public function pack( $dir ) {
				return $this->pack_incremental_changes( array( 'pack_cursor' => array(
					'component' => '', 'part_index' => 0, 'part_entry_count' => 0,
					'part_byte_count' => 0, 'part_start_line' => 0, 'line' => 0, 'next_index' => array(),
				) ), time() + 30, $dir );
			}
		};
		$runner->root = $this->src;
		try {
			$runner->pack( $this->dir );
			$this->fail( 'Expected a failure after the first queued file.' );
		} catch ( RuntimeException $e ) {
			$this->assertSame( 'slice interrupted', $e->getMessage() );
		}
		unset( $runner );
		gc_collect_cycles();
		$this->assertSame( array(), glob( $this->dir . '/*.zip' ), 'The writer must discard before its destructor can flush.' );
	}

	public function test_mb_zip_disk_drop_below_floor_discards_before_close() {
		$backup = $this->backup();
		$file = $this->source( 'file' );
		$backup->after_add = function () { $this->space( 20 * self::GB, self::GB - 1 ); };
		$this->disk_error( function () use ( $backup, $file ) { $backup->archive_db_files( array( $file ), $this->dir . '/dropped', 'zip' ); } );
		$this->assertNull( $backup->zip_handle() );
		unset( $backup );
		gc_collect_cycles();
		$this->assertFileDoesNotExist( $this->dir . '/dropped.sql.zip' );
	}

	public function test_mb_zip_append_add_counts_existing_copy() {
		$backup = $this->backup();
		$file = $this->source( 'first' );
		$path = $this->dir . '/append-add.zip';
		$backup->m_zip_file( array( $file ), $path );
		$before = file_get_contents( $path );
		$this->space( 20 * self::GB, self::GB + strlen( $before ) + 99 );
		$this->disk_error( function () use ( $backup, $file, $path ) { $backup->m_zip_file( array( $file ), $path ); } );
		$this->assertNull( $backup->zip_handle() );
		$this->assertSame( $before, file_get_contents( $path ) );
	}

	public function test_legacy_tar_large_file_refuses_at_bounded_chunk() {
		$file = $this->source( 'large', 12 * self::MB );
		$path = $this->dir . '/large.tar';
		$tar = new Tar_Archiver( new Disk_Floor_Test_Backup(), 'tar' );
		$tar->create( $path );
		$seen = array();
		$this->probe->setValue( null, static function ( $function ) use ( $path, &$seen ) {
			if ( 'disk_total_space' === $function ) { return 20 * self::GB; }
			clearstatcache( true, $path );
			$size = filesize( $path );
			$seen[] = $size;
			return self::GB + 7 * self::MB - $size;
		} );
		$method = new ReflectionMethod( $tar, 'add_file' );
		$method->setAccessible( true );
		$this->disk_error( function () use ( $tar, $file, $method ) { $method->invoke( $tar, $file, 'large' ); } );
		$tar->close();
		clearstatcache( true, $path );
		$this->assertCount( 2, $seen, 'The second bounded content write must check again.' );
		$this->assertSame( 512 + 5120000, filesize( $path ) );
	}

	public function test_tar_string_refuses_before_writing_content() {
		$path = $this->dir . '/string.tar';
		$tar = new Tar_Archiver( new Disk_Floor_Test_Backup(), 'tar' );
		$tar->create( $path );
		$this->space( 20 * self::GB, self::GB + 99 );
		$method = new ReflectionMethod( $tar, 'add_file_from_string' );
		$method->setAccessible( true );
		$this->disk_error( function () use ( $tar, $method ) { $method->invoke( $tar, 'config', str_repeat( 'c', 100 ) ); } );
		$tar->close();
		$this->assertSame( 0, filesize( $path ) );
	}

	private function seeded_dump( $prefix ) {
		$tmp = $prefix . '-fixture.sql.php.tmp';
		file_put_contents( $tmp, 'header' );
		return array(
			'tables' => array( 'fixture' ), 'index' => 0, 'started' => true,
			'files' => array(), 'first' => false, 'pk_col' => null,
			'last_id' => null, 'offset' => 0,
		);
	}

	public function test_dump_floor_fails_within_sixteen_mib_and_removes_partial() {
		$prefix = $this->dir . '/dump';
		$cursor = $this->seeded_dump( $prefix );
		$tmp = $prefix . '-fixture.sql.php.tmp';
		$observed = array();
		$this->probe->setValue( null, function ( $function, $directory ) use ( $tmp, &$observed ) {
			$this->assertSame( $this->dir, $directory );
			if ( 'disk_total_space' === $function ) { return 20 * self::GB; }
			clearstatcache( true, $tmp );
			$observed[] = filesize( $tmp );
			return self::GB + 4 * self::MB - filesize( $tmp );
		} );
		$dumper = new Disk_Floor_Stream_Dumper();
		$e = $this->disk_error( function () use ( $dumper, $prefix, $cursor ) { $dumper->dump_tier2_slice( $prefix, $cursor ); } );
		$this->assertCount( 2, $observed );
		$this->assertGreaterThanOrEqual( 4 * self::MB, $observed[1] );
		$this->assertLessThanOrEqual( 20 * self::MB, $observed[1], 'The floor cannot wait for the full 32 MiB SQL value.' );
		$this->assertStringContainsString( 'It had ', $e->getMessage() );
		$this->assertFileDoesNotExist( $tmp );
		$this->assertFileDoesNotExist( $prefix . '-fixture.sql.php' );
	}

	public function test_restore_aside_dump_keeps_existing_policy_and_restores_backup_floor() {
		$prefix = $this->dir . '/aside';
		$cursor = $this->seeded_dump( $prefix );
		$dumper = new Disk_Floor_Stream_Dumper();
		$probes = 0;
		$this->probe->setValue( null, static function ( $function ) use ( &$probes ) {
			++$probes;
			return 'disk_total_space' === $function ? 20 * self::GB : 0;
		} );
		$result = $dumper->dump_tables_tier2_slice( array( 'fixture' ), $prefix, $cursor );
		$this->assertTrue( $result['done'] );
		$this->assertSame( 0, $probes, 'Restore aside dumps must not call the backup floor.' );
		$this->assertFileExists( $prefix . '-fixture.sql.php' );
		$prefix = $this->dir . '/backup-after-aside';
		$cursor = $this->seeded_dump( $prefix );
		$this->disk_error( function () use ( $dumper, $prefix, $cursor ) { $dumper->dump_tier2_slice( $prefix, $cursor ); } );
		$this->assertGreaterThan( 0, $probes );
	}

	public function test_restore_aside_failure_restores_backup_floor() {
		$dumper = new class extends MainWP_Backup_Db_Dumper {
			public $fail = true;
			protected function tier2_dump_chunk( $table, $tmp, &$cursor, $heartbeat = null ) {
				if ( $this->fail ) { throw new RuntimeException( 'aside write failed' ); }
				$handle = fopen( $tmp, 'ab' );
				try { $this->write_checked( $handle, str_repeat( 's', 16777216 ) ); }
				finally { fclose( $handle ); }
				return true;
			}
		};
		$prefix = $this->dir . '/failed-aside';
		$cursor = $this->seeded_dump( $prefix );
		try {
			$dumper->dump_tables_tier2_slice( array( 'fixture' ), $prefix, $cursor );
			$this->fail( 'The aside dump must propagate its failure.' );
		} catch ( RuntimeException $e ) {
			$this->assertSame( 'aside write failed', $e->getMessage() );
		}
		$this->space( 20 * self::GB, 0 );
		$dumper->fail = false;
		$cursor = $this->seeded_dump( $prefix );
		$this->disk_error( function () use ( $dumper, $prefix, $cursor ) { $dumper->dump_tier2_slice( $prefix, $cursor ); } );
	}

	private function pcl_backup() {
		$backup = new Disk_Floor_Pcl_Backup();
		$backup->source_dir = $this->src;
		$backup->allow( $this->src );
		$backup->set_prepared_db_files( array() );
		return $backup;
	}

	/** @dataProvider pcl_growth_stages */
	public function test_pcl_staging_and_create_refuse_and_remove_staged_tree( $headroom ) {
		$backup = $this->pcl_backup();
		$file = $this->source( 'file', 100 );
		$this->space( 20 * self::GB, self::GB + $headroom );
		$prefix = $this->dir . '/pcl';
		$this->disk_error( function () use ( $backup, $prefix ) {
			$backup->create_zip_pcl_full_backup2( $prefix . '.zip', array(), false, true, false, false );
		} );
		$this->assertFileExists( $file );
		$this->assertDirectoryDoesNotExist( $this->dir . '/backup' );
		$this->assertFileDoesNotExist( $prefix . '.zip' );
	}

	public static function pcl_growth_stages() {
		return array( 'copy' => array( 99 ), 'create-two-copies' => array( 199 ) );
	}

	public function test_pcl_database_add_counts_both_staged_copies() {
		$backup = $this->pcl_backup();
		$file = $this->source( 'dump.sql.php', 100 );
		$this->space( 20 * self::GB, self::GB + 199 );
		$this->disk_error( function () use ( $backup, $file ) { $backup->archive_db_files( array( $file ), $this->dir . '/db', 'zip' ); } );
		$this->assertFileExists( $file );
		$this->assertFileDoesNotExist( $this->dir . '/db.sql.zip' );
	}

	/** @dataProvider fragment_failures */
	public function test_pcl_fragment_refusal_or_copy_failure_keeps_source( $floor_refuses ) {
		$backup = $this->pcl_backup();
		$backup->make_content = $floor_refuses;
		$fragment = $this->dir . '/prepared.sql.php';
		file_put_contents( $fragment, str_repeat( 's', 200 ) );
		$backup->set_prepared_db_files( array( $fragment ) );
		$this->space( 20 * self::GB, $floor_refuses ? self::GB + 199 : 10 * self::GB );
		try {
			$backup->create_zip_pcl_full_backup2( $this->dir . '/fragment.zip', array(), false, true, false, false );
			$this->fail( 'Fragment staging must fail.' );
		} catch ( MainWP_Exception $e ) {
			$this->assertSame( $floor_refuses ? MainWP_Backup_Error::ERR_DISK_FULL : MainWP_Backup_Error::ERR_ZIP_FAILED, $e->get_message_error_code() );
		}
		$this->assertSame( str_repeat( 's', 200 ), file_get_contents( $fragment ) );
		$this->assertDirectoryDoesNotExist( $this->dir . '/backup' );
	}

	public static function fragment_failures() {
		return array( 'floor' => array( true ), 'missing-target-directory' => array( false ) );
	}
}
