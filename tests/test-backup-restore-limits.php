<?php
/**
 * @package Mainwp_Child
 */

use MainWP\Child\MainWP_Child_Backup_File_Restorer;
use MainWP\Child\MainWP_Child_Restore_Task_Runner;
use MainWP\Child\MainWP_Backup_Error;
use MainWP\Child\MainWP_Backup;
use MainWP\Child\MainWP_Child_Backup_Task;

class LimitedBackupFileRestorer extends MainWP_Child_Backup_File_Restorer {

	public $limits = array();

	protected function extraction_limits() {
		return array_merge( parent::extraction_limits(), $this->limits );
	}
}

class LimitedRestoreCleanupRunner extends MainWP_Child_Restore_Task_Runner {

	protected function removal_depth_limit() {
		return 2;
	}
}

class BackupRestoreLimitsTest extends WP_UnitTestCase {

	protected $dir;
	protected $extract_to;
	protected $restorer;

	public function set_up() {
		parent::set_up();
		$this->dir        = sys_get_temp_dir() . '/mainwp-restore-limits-' . uniqid();
		$this->extract_to = $this->dir . '/site';
		mkdir( $this->extract_to, 0777, true );
		$this->restorer = new LimitedBackupFileRestorer();
	}

	public function tear_down() {
		$this->remove_fixture( $this->dir );
		parent::tear_down();
	}

	protected function remove_fixture( $path ) {
		if ( is_link( $path ) || ! is_dir( $path ) ) {
			if ( is_link( $path ) || file_exists( $path ) ) {
				unlink( $path );
			}
			return;
		}
		foreach ( scandir( $path ) as $entry ) {
			if ( '.' !== $entry && '..' !== $entry ) {
				$this->remove_fixture( $path . '/' . $entry );
			}
		}
		rmdir( $path );
	}

	protected function make_archive( $format, $entries, $name = 'fixture' ) {
		$path = $this->dir . '/' . $name . '.' . $format;
		if ( 'zip' === $format ) {
			$archive = new ZipArchive();
			$this->assertTrue( $archive->open( $path, ZipArchive::CREATE ) );
		} else {
			$archive = new PharData( $path );
		}
		foreach ( $entries as $entry => $content ) {
			$archive->addFromString( $entry, $content );
		}
		if ( 'zip' === $format ) {
			$this->assertTrue( $archive->close() );
		}
		return $path;
	}

	protected function config( $parts ) {
		$hashes = array();
		foreach ( $parts as $part ) {
			$hashes[ basename( $part ) ] = hash_file( 'sha256', $part );
		}
		return array( 'parts' => $parts, 'hashes' => $hashes, 'extract_to' => $this->extract_to );
	}

	public static function archive_formats() {
		return array( 'zip' => array( 'zip' ), 'tar' => array( 'tar' ) );
	}

	/** @dataProvider archive_formats */
	public function test_entry_count_cap_survives_slices_and_parts( $format ) {
		$this->restorer->limits = array( 'entries' => 1 );
		$one = $this->make_archive( $format, array( 'one.txt' => 'one' ), 'one' );
		$two = $this->make_archive( $format, array( 'two.txt' => 'two' ), 'two' );
		$config = $this->config( array( $one, $two ) );
		$first = $this->restorer->restore_slice( array(), $config, 0 );
		$this->assertNull( $first['error'] );
		$result = $this->restorer->restore_slice( $first['cursor'], $config, microtime( true ) + 60 );
		$this->assertSame( MainWP_Backup_Error::ERR_RESTORE_EXTRACT, $result['error_code'], 'Entry-count cap must reject the second entry.' );
		$this->assertStringContainsString( 'two.txt', $result['error'] );
		$this->assertFileDoesNotExist( $this->extract_to . '/two.txt' );
	}

	/** @dataProvider archive_formats */
	public function test_expanded_bytes_cap_counts_streamed_bytes( $format ) {
		$this->restorer->limits = array( 'expanded_bytes' => 7 );
		$part = $this->make_archive( $format, array( 'one.txt' => '1234', 'two.txt' => '5678' ) );
		$config = $this->config( array( $part ) );
		$first = $this->restorer->restore_slice( array(), $config, 0 );
		$this->assertNull( $first['error'] );
		$result = $this->restorer->restore_slice( $first['cursor'], $config, microtime( true ) + 60 );
		$this->assertSame( MainWP_Backup_Error::ERR_RESTORE_EXTRACT, $result['error_code'], 'Expanded-byte cap must reject cumulative output.' );
		$this->assertSame( 7, $result['cursor']['expanded_bytes'] );
		$this->assertFileDoesNotExist( $this->extract_to . '/two.txt' );
		$this->assertSame( array(), glob( $this->extract_to . '/.mainwp-restore-*.tmp' ) );
		$this->assertStringContainsString( 'two.txt', $result['error'] );
	}

	public function test_compression_ratio_cap() {
		$this->restorer->limits = array( 'ratio' => 2 );
		$part = $this->make_archive( 'zip', array( 'large.txt' => random_bytes( 70000 ) . str_repeat( 'a', 210000 ) ) );
		$zip = new ZipArchive();
		$zip->open( $part );
		$stat = $zip->statIndex( 0 );
		$zip->close();
		$this->assertGreaterThan( 65536, $stat['comp_size'] );
		$this->assertGreaterThan( 2 * $stat['comp_size'], $stat['size'] );
		$result = $this->restorer->restore_slice( array(), $this->config( array( $part ) ), microtime( true ) + 60 );
		$this->assertSame( MainWP_Backup_Error::ERR_RESTORE_EXTRACT, $result['error_code'], 'Compression-ratio cap must reject the entry before extraction.' );
		$this->assertFileDoesNotExist( $this->extract_to . '/large.txt' );
	}

	public static function compressed_limits() {
		return array(
			'bytes' => array( array( 'expanded_bytes' => 32 ) ),
			'ratio' => array( array( 'ratio_min_bytes' => 1, 'ratio' => 2 ) ),
		);
	}

	/** @dataProvider compressed_limits */
	public function test_compressed_tar_preparation_enforces_limits( $limits ) {
		$this->restorer->limits = $limits;
		$part = $this->dir . '/ordinary.tar.gz';
		file_put_contents( $part, gzencode( str_repeat( 'ordinary bytes ', 1000 ) ) );
		$result = $this->restorer->restore_slice( array(), $this->config( array( $part ) ), microtime( true ) + 60 );
		$this->assertSame( MainWP_Backup_Error::ERR_RESTORE_EXTRACT, $result['error_code'] );
		$this->assertStringContainsString( 'The compressed archive ordinary.tar.gz expands beyond the restore size limit.', $result['error'] );
		$this->assertSame( array(), glob( $this->dir . '/*.tar' ) );
		$this->assertSame( array(), glob( $this->dir . '/*.tmp' ) );
	}

	public function test_ratio_threshold_is_inclusive_for_metadata_and_stream() {
		$this->restorer->limits = array( 'ratio_min_bytes' => 65536, 'ratio' => 1 );
		$cursor = array( 'entries' => 0, 'expanded_bytes' => 0 );
		$check = new ReflectionMethod( MainWP_Child_Backup_File_Restorer::class, 'check_entry_limits' );
		$check->setAccessible( true );
		try {
			$check->invokeArgs( $this->restorer, array( 65537, 65536, &$cursor, 'ordinary.txt' ) );
			$this->fail( 'The ratio threshold must include equality.' );
		} catch ( \MainWP\Child\MainWP_Exception $e ) {
			$this->assertStringContainsString( 'ordinary.txt', $e->getMessage() );
			$this->assertSame( MainWP_Backup_Error::ERR_RESTORE_EXTRACT, $e->get_message_error_code() );
		}
		$stream = fopen( 'php://temp', 'w+b' );
		$out = fopen( 'php://temp', 'w+b' );
		fwrite( $stream, str_repeat( 'a', 65537 ) );
		rewind( $stream );
		$copy = new ReflectionMethod( MainWP_Child_Backup_File_Restorer::class, 'copy_bounded_entry' );
		$copy->setAccessible( true );
		try {
			$copy->invokeArgs( $this->restorer, array( $stream, $out, &$cursor, 65536, 'ordinary.txt' ) );
			$this->fail( 'Streamed bytes must obey the inclusive ratio threshold.' );
		} catch ( \MainWP\Child\MainWP_Exception $e ) {
			$this->assertStringContainsString( 'ordinary.txt', $e->getMessage() );
			$this->assertSame( 65536, $cursor['expanded_bytes'] );
		} finally {
			fclose( $stream );
			fclose( $out );
		}
	}

	public static function checkpoint_outcomes() {
		return array( 'owned' => array( false ), 'lost' => array( true ) );
	}

	/** @dataProvider checkpoint_outcomes */
	public function test_file_runner_checkpoints_before_entry_write( $abandon ) {
		$runner = new class() extends MainWP_Child_Restore_Task_Runner {
			public $root;
			public $abandon;
			public $saved;
			public $temp_existed;
			public $final_state;
			protected function site_root() {
				return $this->root;
			}
			protected function checkpoint_sub_state( $task, $version, $sub_state, $backup_dir, $then_yield ) {
				$this->saved = json_decode( wp_json_encode( $sub_state ), true );
				$this->temp_existed = file_exists( $this->saved['file_restore']['part_cursor']['pending_temp'] );
				return $this->slice_result( $this->abandon ? $version : $version + 1, $this->abandon, $this->abandon );
			}
			protected function advance_phase( $task, $version, $sub_state, $backup_dir ) {
				$this->final_state = $sub_state;
				return $this->slice_result( $version, true );
			}
		};
		$runner->root = $this->extract_to;
		$runner->abandon = $abandon;
		$task = (object) array( 'task_id' => 'checkpoint-test' );
		$scratch = $runner->scratch_dir( $task->task_id, $this->dir );
		mkdir( $scratch );
		$part = $this->make_archive( 'zip', array( 'ordinary.txt' => 'replacement' ) );
		copy( $part, $scratch . basename( $part ) );
		$state = array( 'params' => array( 'artifacts' => array( array(
			'role' => 'part', 'file' => basename( $part ), 'sha256' => hash_file( 'sha256', $part ),
		) ) ) );
		$method = new ReflectionMethod( MainWP_Child_Restore_Task_Runner::class, 'run_restoring_files' );
		$method->setAccessible( true );
		$result = $method->invoke( $runner, $task, 1, $state, time() + 60, $this->dir );
		$this->assertFalse( $runner->temp_existed );
		$this->assertSame( 0, $runner->saved['file_restore']['part_cursor']['entries'] );
		$this->assertSame( $this->extract_to . '/.mainwp-restore-' . md5( 'ordinary.txt' ) . '.tmp', $runner->saved['file_restore']['part_cursor']['pending_temp'] );
		$this->assertSame( $abandon, $result['abandon'] );
		if ( $abandon ) {
			$this->assertSame( 1, $result['version'] );
			$this->assertFileDoesNotExist( $this->extract_to . '/ordinary.txt' );
		} else {
			$this->assertSame( 2, $result['version'] );
			$this->assertSame( '', $runner->final_state['file_restore']['part_cursor']['pending_temp'] );
			$this->assertSame( 'replacement', file_get_contents( $this->extract_to . '/ordinary.txt' ) );
		}
		$this->assertSame( array(), glob( $this->extract_to . '/.mainwp-restore-*.tmp' ) );
	}

	public static function cursor_storage() {
		return array( 'inline' => array( false ), 'sidecar' => array( true ) );
	}

	/** @dataProvider cursor_storage */
	public function test_terminal_cleanup_removes_persisted_pending_temp( $sidecar ) {
		$part = $this->make_archive( 'zip', array( 'ordinary.txt' => 'replacement' ) );
		$config = $this->config( array( $part ) );
		$saved = null;
		$config['checkpoint_cursor'] = function ( $cursor ) use ( &$saved, $sidecar ) {
			$this->assertFileDoesNotExist( $cursor['pending_temp'] );
			$this->assertSame( 0, $cursor['entries'] );
			$state = array( 'file_restore' => array( 'part_cursor' => $cursor ) );
			if ( $sidecar ) {
				$state['padding'] = str_repeat( 'a', MainWP_Child_Backup_Task::SUB_STATE_INLINE_MAX );
			}
			$saved = MainWP_Child_Backup_Task::instance()->write_sub_state( 'pending-test', $state, $this->dir, 1 );
			$this->assertNotFalse( $saved );
			return true;
		};
		$this->restorer->limits = array( 'expanded_bytes' => 3 );
		$result = $this->restorer->restore_slice( array(), $config, microtime( true ) + 60 );
		$this->assertSame( MainWP_Backup_Error::ERR_RESTORE_EXTRACT, $result['error_code'] );
		$state = MainWP_Child_Backup_Task::instance()->read_sub_state( (object) array( 'sub_state' => $saved ), $this->dir );
		$temp = $state['file_restore']['part_cursor']['pending_temp'];
		$this->assertSame( $temp, $result['cursor']['pending_temp'] );
		file_put_contents( $temp, 'interrupted output' );
		$runner = new class() extends MainWP_Child_Restore_Task_Runner {
			protected function restore_engine() {
				return new class() {
					public function exit_maintenance() {}
				};
			}
		};
		$method = new ReflectionMethod( MainWP_Child_Restore_Task_Runner::class, 'terminal_cleanup' );
		$method->setAccessible( true );
		$method->invoke( $runner, (object) array( 'task_id' => 'pending-test' ), $state, $this->dir );
		$this->assertFileDoesNotExist( $temp );
		$method->invoke( $runner, (object) array( 'task_id' => 'old-cursor' ), array(), $this->dir );
	}

	public function test_rrmdir_depth_cap() {
		$root = $this->dir . '/deep';
		mkdir( $root . '/a/b/c', 0777, true );
		file_put_contents( $root . '/a/b/c/keep.txt', 'keep' );
		foreach ( array( '', '/a', '/a/b' ) as $level ) {
			file_put_contents( $root . $level . '/remove.txt', 'remove' );
			mkdir( $root . $level . '/empty' );
		}
		MainWP_Backup::reset_skipped_paths();
		$this->invoke_rrmdir( $root );
		foreach ( array( '', '/a', '/a/b' ) as $level ) {
			$this->assertFileDoesNotExist( $root . $level . '/remove.txt' );
		}
		$this->assertDirectoryDoesNotExist( $root . '/empty' );
		$this->assertDirectoryDoesNotExist( $root . '/a/empty' );
		$this->assertDirectoryExists( $root . '/a/b/empty' );
		$this->assertSame( 'keep', file_get_contents( $root . '/a/b/c/keep.txt' ) );
		$this->assertSame( array(), MainWP_Backup::get_skipped_paths() );
		MainWP_Backup::reset_skipped_paths();
	}

	public function test_rrmdir_unlinks_symlinks_without_following_them() {
		$outside = $this->dir . '/outside';
		mkdir( $outside );
		file_put_contents( $outside . '/keep.txt', 'keep' );
		$root = $this->dir . '/cleanup';
		mkdir( $root );
		symlink( $outside, $root . '/nested-link' );
		$this->invoke_rrmdir( $root );
		$this->assertFileExists( $outside . '/keep.txt' );
		$this->assertDirectoryDoesNotExist( $root );
		symlink( $outside, $root );
		$this->invoke_rrmdir( $root );
		$this->assertFileExists( $outside . '/keep.txt', 'Cleanup must not descend through a root symlink.' );
		$this->assertFalse( is_link( $root ) );
	}

	protected function invoke_rrmdir( $path ) {
		$method = new ReflectionMethod( MainWP_Child_Restore_Task_Runner::class, 'rrmdir' );
		$method->setAccessible( true );
		$method->invoke( new LimitedRestoreCleanupRunner(), $path );
	}

	/** @dataProvider archive_formats */
	public function test_normal_small_archive_extracts( $format ) {
		$this->restorer->limits = array( 'entries' => 1, 'expanded_bytes' => 1024, 'ratio' => 2 );
		$content = str_repeat( 'a', 1024 );
		$part = $this->make_archive( $format, array( 'small.txt' => $content ) );
		$result = $this->restorer->restore_slice( array(), $this->config( array( $part ) ), microtime( true ) + 60 );
		$this->assertTrue( $result['done'] );
		$this->assertNull( $result['error'] );
		$this->assertSame( $content, file_get_contents( $this->extract_to . '/small.txt' ) );
	}
}
