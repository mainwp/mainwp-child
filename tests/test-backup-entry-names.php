<?php
/**
 * Archive and manifest agreement for protected plugin files and .htaccess.
 *
 * @package Mainwp_Child
 */

use MainWP\Child\MainWP_Backup;
use MainWP\Child\MainWP_Child_Backup_File_Walker;
use MainWP\Child\MainWP_Helper;
use MainWP\Child\Tar_Archiver;

class BackupEntryNamesTest extends WP_UnitTestCase {

	private $fixture;
	private $scratch;
	private $root_htaccess_created = false;

	public function set_up() {
		parent::set_up();
		$this->fixture = ABSPATH . 'mwp-entry-names-' . uniqid();
		$this->scratch = sys_get_temp_dir() . '/mwp-entry-names-' . uniqid();
		mkdir( $this->fixture );
		mkdir( $this->scratch );
	}

	public function tear_down() {
		MainWP_Helper::delete_dir( $this->fixture );
		MainWP_Helper::delete_dir( $this->scratch );
		if ( $this->root_htaccess_created ) {
			unlink( ABSPATH . '.htaccess' );
		}
		parent::tear_down();
	}

	private function backup() {
		return new class() extends MainWP_Backup {
			public function extend_archive_mutex_lease() {}
			public function get_or_create_db_dump_files( $prefix, &$archiver = null ) {
				return array();
			}
		};
	}

	private function excludes( $backup ) {
		$excludes = $backup->assemble_exclude_list( array(), false, false );
		foreach ( glob( ABSPATH . '*' ) as $node ) {
			if ( $node !== $this->fixture ) {
				$excludes[] = str_replace( ABSPATH, '', $node );
			}
		}
		return $excludes;
	}

	private function archive_entries( $format, $backup, $excludes ) {
		$archive = $this->scratch . '/full.' . ( 'tar' === $format ? 'tar' : 'zip' );
		if ( 'tar' === $format ) {
			$tar = new Tar_Archiver( $backup, 'tar' );
			$this->assertTrue( $tar->create_full_backup( $archive, $excludes, false, true, false, false ) );
			$phar = new PharData( $archive );
			$entries = array();
			$prefix = 'phar://' . $phar->getPath() . '/';
			foreach ( new RecursiveIteratorIterator( $phar ) as $file ) {
				$entries[ substr( $file->getPathname(), strlen( $prefix ) ) ] = $file->getContent();
			}
			return $entries;
		}

		if ( ! class_exists( 'ZipArchive' ) ) {
			$this->markTestSkipped( 'ZipArchive unavailable for archive inspection.' );
		}
		if ( 'pclzip' === $format ) {
			$this->assertTrue( $backup->create_zip_pcl_full_backup2( $archive, $excludes, false, true, false, false ) );
		} else {
			$this->assertTrue( $backup->create_zip_full_backup( $archive, $excludes, false, true, false, false ) );
		}
		$zip = new ZipArchive();
		$this->assertTrue( $zip->open( $archive ) );
		$entries = array();
		for ( $i = 0; $i < $zip->numFiles; ++$i ) {
			$entries[ $zip->getNameIndex( $i ) ] = $zip->getFromIndex( $i );
		}
		$zip->close();
		return $entries;
	}

	private function manifest_paths( $excludes ) {
		$walker = new MainWP_Child_Backup_File_Walker( ABSPATH, $excludes );
		$walker->emit_sidecars( $this->scratch );
		$paths = array();
		foreach ( file( $this->scratch . '/files.list.staged', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES ) as $line ) {
			$paths[] = explode( "\t", $line )[0];
		}
		return $paths;
	}

	public static function producers() {
		return array( 'zip' => array( 'zip' ), 'pclzip' => array( 'pclzip' ), 'tar' => array( 'tar' ) );
	}

	/**
	 * @dataProvider producers
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_producers_and_manifest_exclude_active_child_directory( $format ) {
		$plugin = $this->fixture . '/renamed-child';
		define( 'MainWP\\Child\\MAINWP_CHILD_PLUGIN_DIR', $plugin . '/' );
		mkdir( $plugin );
		mkdir( $plugin . '-extra' );
		file_put_contents( $plugin . '/child.php', 'active child' );
		file_put_contents( $plugin . '-extra/keep.php', 'other plugin' );
		$backup = $this->backup();
		$excludes = $this->excludes( $backup );
		$relpath = str_replace( ABSPATH, '', $plugin );
		$this->assertContains( $relpath, $excludes );
		$entries = $this->archive_entries( $format, $backup, $excludes );
		$this->assertArrayNotHasKey( $relpath . '/child.php', $entries );
		$this->assertSame( 'other plugin', $entries[ $relpath . '-extra/keep.php' ] );
		$paths = $this->manifest_paths( $excludes );
		$this->assertNotContains( $relpath . '/child.php', $paths );
		$this->assertContains( $relpath . '-extra/keep.php', $paths );
	}

	public static function htaccess_cases() {
		$cases = array();
		foreach ( array( 'zip', 'pclzip', 'tar' ) as $format ) {
			$cases[ $format . '-included' ] = array( $format, false );
			$cases[ $format . '-excluded' ] = array( $format, true );
		}
		return $cases;
	}

	/** @dataProvider htaccess_cases */
	public function test_producers_and_manifest_use_real_htaccess_names( $format, $exclude ) {
		if ( ! file_exists( ABSPATH . '.htaccess' ) ) {
			$this->root_htaccess_created = true;
			file_put_contents( ABSPATH . '.htaccess', 'root rules' );
		}
		$root_content = file_get_contents( ABSPATH . '.htaccess' );
		mkdir( $this->fixture . '/nested' );
		file_put_contents( $this->fixture . '/.htaccess', 'directory rules' );
		file_put_contents( $this->fixture . '/nested/.htaccess', 'nested rules' );
		file_put_contents( $this->fixture . '/keep.txt', 'ordinary content' );
		$prefix = str_replace( ABSPATH, '', $this->fixture ) . '/';
		$expected = array(
			'.htaccess' => $root_content,
			$prefix . '.htaccess' => 'directory rules',
			$prefix . 'nested/.htaccess' => 'nested rules',
		);
		$backup = $this->backup();
		$excludes = $this->excludes( $backup );
		if ( $exclude ) {
			$excludes = array_merge( $excludes, array_keys( $expected ) );
		}
		$entries = $this->archive_entries( $format, $backup, $excludes );
		$paths = $this->manifest_paths( $excludes );
		$this->assertSame( 'ordinary content', $entries[ $prefix . 'keep.txt' ] );
		foreach ( $expected as $path => $content ) {
			$this->assertArrayNotHasKey( str_replace( '.htaccess', 'mainwp-htaccess', $path ), $entries );
			if ( $exclude ) {
				$this->assertArrayNotHasKey( $path, $entries );
				$this->assertNotContains( $path, $paths );
			} else {
				$this->assertArrayHasKey( $path, $entries );
				$this->assertSame( $content, $entries[ $path ] );
				$this->assertContains( $path, $paths );
			}
		}
	}
}
