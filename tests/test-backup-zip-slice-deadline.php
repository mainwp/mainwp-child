<?php
/**
 * ZIP slices retain complete entries across requests.
 *
 * @package Mainwp_Child
 */

use MainWP\Child\MainWP_Backup;
use MainWP\Child\MainWP_Backup_Budget_Exception;
use MainWP\Child\MainWP_Backup_Error;
use MainWP\Child\MainWP_Child_Backup_Task_Runner;
use MainWP\Child\MainWP_Helper;

class BackupZipSliceDeadlineTest extends WP_UnitTestCase {

	private $scratch;
	private $archive;

	public function set_up() {
		parent::set_up();
		$this->scratch = sys_get_temp_dir() . '/mwp-zip-slice-' . wp_generate_uuid4();
		mkdir( $this->scratch . '/tree/nested', 0755, true );
	}

	public function tear_down() {
		if ( $this->archive && is_file( $this->archive ) ) {
			unlink( $this->archive );
		}
		$files = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $this->scratch, FilesystemIterator::SKIP_DOTS ), RecursiveIteratorIterator::CHILD_FIRST );
		foreach ( $files as $file ) {
			$file->isDir() ? rmdir( $file->getPathname() ) : unlink( $file->getPathname() );
		}
		rmdir( $this->scratch );
		parent::tear_down();
	}

	public static function slice_boundaries() {
		return array(
			'next file' => array( true, false ),
			'next directory' => array( false, false ),
			'unusable partial' => array( true, true ),
		);
	}

	/** @dataProvider slice_boundaries */
	public function test_full_zip_yields_cleanly_then_appends_without_replacing_entries( $next_file, $corrupt ) {
		$backup = new class() extends MainWP_Backup {
			public $root;
			public $dump_files;
			public function get_or_create_db_dump_files( $prefix, &$archiver = null ) {
				$this->allowed_backup_bases = array( $this->root );
				return $this->dump_files;
			}
			public function add_config() {
				$this->zip_add_dir( $this->root . '/tree', array() );
			}
		};
		$backup->root = $this->scratch;
		$backup->dump_files = array( $this->scratch . '/first.sql.php' );
		file_put_contents( $backup->dump_files[0], 'first entry' );
		if ( $next_file ) {
			$backup->dump_files[] = $this->scratch . '/second.sql.php';
			file_put_contents( $backup->dump_files[1], 'second entry' );
		}
		file_put_contents( $this->scratch . '/tree/one.txt', 'one' );
		file_put_contents( $this->scratch . '/tree/nested/two.txt', 'two nested' );
		$name = 'slice-' . wp_generate_uuid4() . '.zip';
		$dirs = MainWP_Helper::get_mainwp_dir( 'backup' );
		$this->archive = $dirs[0] . $name;
		if ( $corrupt ) {
			file_put_contents( $this->archive, 'truncated ZIP' );
		}
		$backup->set_slice_deadline( time() - 1 );
		$caught = null;
		try {
			$backup->create_full_backup( array(), '', true, true, 0, $name, false, false, true, 'zip', false, true );
		} catch ( MainWP_Backup_Budget_Exception $e ) {
			$caught = $e;
		}
		$this->assertInstanceOf( MainWP_Backup_Budget_Exception::class, $caught, 'ZIP must yield after its first added file.' );
		$this->assertSame( MainWP_Backup_Error::ERR_ZIP_FAILED, $caught->get_message_error_code() );
		$first_entry = basename( WP_CONTENT_DIR ) . '/first.sql.php';
		$zip = new ZipArchive();
		$this->assertTrue( $zip->open( $this->archive, ZipArchive::CHECKCONS ) );
		$this->assertSame( 1, $zip->numFiles );
		$this->assertSame( 'first entry', $zip->getFromName( $first_entry ) );
		$zip->close();

		// Replacement would still leave one entry, so change the source to prove fast-forwarding.
		file_put_contents( $backup->dump_files[0], 'must not replace the archived entry' );
		$backup->set_slice_deadline( null );
		$result = $backup->create_full_backup( array(), '', true, true, 1, $name, false, false, true, 'zip', false, true );
		$this->assertSame( $name, $result['file'] );
		$this->assertTrue( $zip->open( $this->archive, ZipArchive::CHECKCONS ) );
		$expected = array(
			$first_entry => 'first entry',
			$this->scratch . '/tree/' => '',
			$this->scratch . '/tree/one.txt' => 'one',
			$this->scratch . '/tree/nested/' => '',
			$this->scratch . '/tree/nested/two.txt' => 'two nested',
		);
		if ( $next_file ) {
			$expected[ basename( WP_CONTENT_DIR ) . '/second.sql.php' ] = 'second entry';
		}
		$this->assertSame( count( $expected ), $zip->numFiles );
		$names = array();
		for ( $i = 0; $i < $zip->numFiles; ++$i ) {
			$stat = $zip->statIndex( $i );
			$this->assertArrayHasKey( $stat['name'], $expected );
			$this->assertSame( strlen( $expected[ $stat['name'] ] ), $stat['size'], $stat['name'] );
			$this->assertSame( $expected[ $stat['name'] ], $zip->getFromIndex( $i ) );
			$names[] = $stat['name'];
		}
		$this->assertSame( $names, array_values( array_unique( $names ) ) );
		$zip->close();
	}

	public function test_runner_preserves_partial_zip_for_append() {
		$this->archive = $this->scratch . '/partial.zip';
		$zip = new ZipArchive();
		$this->assertTrue( $zip->open( $this->archive, ZipArchive::CREATE ) );
		$zip->addFromString( 'first.txt', 'first entry' );
		$zip->close();
		$before = file_get_contents( $this->archive );
		$backup = new class() extends MainWP_Backup {
			public $root;
			public $observed;
			public $append;
			public function create_full_backup( $excludes = array(), $filePrefix = '', $addConfig = true, $includeCoreFiles = true, $file_descriptors = 0, $fileSuffix = false, $excludezip = false, $excludenonwp = false, $loadFilesBeforeZip = true, $ext = 'zip', $pid = false, $append = false ) {
				$path = $this->root . '/' . $fileSuffix;
				$this->observed = is_file( $path ) ? file_get_contents( $path ) : null;
				$this->append = $append;
				throw new MainWP_Backup_Budget_Exception( 'slice ended', null, MainWP_Backup_Error::ERR_ZIP_FAILED );
			}
		};
		$backup->root = $this->scratch;
		$runner = new class() extends MainWP_Child_Backup_Task_Runner {
			public $root;
			protected function backup_dir() {
				return $this->root;
			}
			public function resume( $sub_state ) {
				return $this->run_file_archiver( array( 'ext' => 'zip' ), $sub_state['archive_file'], $sub_state['archive_started'], time() + 5, array(), true );
			}
		};
		$runner->root = $this->scratch;
		$slot = new ReflectionProperty( MainWP_Backup::class, 'instance' );
		$slot->setAccessible( true );
		$original = $slot->getValue();
		$slot->setValue( null, $backup );
		try {
			try {
				$runner->resume( array( 'archive_file' => 'partial.zip', 'archive_started' => true ) );
				$this->fail( 'The engine budget exception must propagate.' );
			} catch ( MainWP_Backup_Budget_Exception $e ) {
				$this->assertSame( $before, $backup->observed, 'Runner must retain the partial ZIP before calling the engine.' );
				$this->assertTrue( $backup->append );
				$this->assertSame( $before, file_get_contents( $this->archive ) );
			}
		} finally {
			$slot->setValue( null, $original );
		}
	}
}
