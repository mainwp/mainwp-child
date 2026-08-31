<?php
/**
 * Class TarArchiverBudgetTest
 *
 * @package Mainwp_Child
 */

use MainWP\Child\MainWP_Backup;
use MainWP\Child\MainWP_Backup_Budget_Exception;
use MainWP\Child\Tar_Archiver;

/**
 * A chunk-buffering archiver: the buffered write path only runs with debug
 * on, so the plain and bzip2 branches of write_chunk() have no other cover.
 */
class Chunked_Tar_Archiver extends Tar_Archiver {

	/**
	 * Buffer through write_chunk() with a small chunk so several flushes run.
	 *
	 * @param object $backup MainWP_Backup instance.
	 * @param string $type   Archive type.
	 */
	public function __construct( $backup, $type = 'tar' ) {
		parent::__construct( $backup, $type );
		$this->debug     = true;
		$this->chunkSize = 1024;
	}
}

/**
 * Tests for the tar archiver slice time budget.
 *
 * A bounded continuation slice needs the file traversal to stop near its
 * deadline and leave a structurally valid archive on disk that a later
 * append run can extend. Without a deadline the archiver must behave
 * exactly as before.
 */
class TarArchiverBudgetTest extends WP_UnitTestCase {

	/**
	 * Fixture directory inside the uploads tree (the traversal guards
	 * reject paths outside ABSPATH/WP_CONTENT_DIR).
	 *
	 * @var string
	 */
	protected $fixture_dir;

	/**
	 * Archive scratch path.
	 *
	 * @var string
	 */
	protected $archive_path;

	/**
	 * Fixture file names, in creation order.
	 *
	 * @var string[]
	 */
	protected $fixture_files = array();

	/**
	 * Build a flat fixture dir with several small files.
	 */
	public function set_up() {
		parent::set_up();
		$uploads           = wp_upload_dir();
		$this->fixture_dir = $uploads['basedir'] . '/tar-budget-fixture-' . uniqid();
		mkdir( $this->fixture_dir, 0777, true );

		$this->fixture_files = array();
		for ( $i = 0; $i < 5; $i++ ) {
			$name = sprintf( 'file-%d.txt', $i );
			file_put_contents( $this->fixture_dir . '/' . $name, str_repeat( "content-$i\n", 50 ) ); // phpcs:ignore -- test fixture.
			$this->fixture_files[] = $name;
		}

		$this->archive_path = $uploads['basedir'] . '/tar-budget-' . uniqid() . '.tar';
	}

	/**
	 * Remove fixtures and archives.
	 */
	public function tear_down() {
		foreach ( (array) glob( $this->fixture_dir . '/*' ) as $file ) {
			unlink( $file );
		}
		if ( is_dir( $this->fixture_dir ) ) {
			rmdir( $this->fixture_dir );
		}
		if ( file_exists( $this->archive_path ) ) {
			unlink( $this->archive_path );
		}
		parent::tear_down();
	}

	/**
	 * List entry names from a tar file by walking its 512-byte headers.
	 *
	 * @param string $path Tar file path.
	 *
	 * @return string[] Entry names.
	 */
	protected function list_tar_entries( $path ) {
		$entries = array();
		$fh      = fopen( $path, 'rb' ); // phpcs:ignore -- test reader.
		while ( ! feof( $fh ) ) {
			$header = fread( $fh, 512 );
			if ( false === $header || strlen( $header ) < 512 ) {
				break;
			}
			$name = rtrim( substr( $header, 0, 100 ), "\0 " );
			if ( '' === $name ) {
				break;
			}
			$size      = octdec( trim( substr( $header, 124, 12 ) ) );
			$entries[] = $name;
			if ( $size > 0 ) {
				fseek( $fh, (int) ( ceil( $size / 512 ) * 512 ), SEEK_CUR );
			}
		}
		fclose( $fh );
		return $entries;
	}

	/**
	 * The buffered (chunked) write path emits the chunk bytes, not the byte
	 * count: the plain branch used to write the decimal digits of the length
	 * instead of the buffer, producing an unreadable archive that still
	 * reported success.
	 */
	public function test_chunked_writes_emit_the_buffer_not_its_length() {
		$archiver = new Chunked_Tar_Archiver( MainWP_Backup::get(), 'tar' );
		$archiver->create( $this->archive_path );
		$archiver->add_dir( $this->fixture_dir, array() );
		$archiver->close();

		$entries = $this->list_tar_entries( $this->archive_path );
		foreach ( $this->fixture_files as $name ) {
			$this->assertContains( str_replace( ABSPATH, '', $this->fixture_dir ) . '/' . $name, $entries );
		}

		$reader = new Tar_Archiver( MainWP_Backup::get(), 'tar' );
		$reader->read( $this->archive_path );
		$content = $reader->get_from_name( str_replace( ABSPATH, '', $this->fixture_dir ) . '/file-2.txt' );
		$reader->close();

		$this->assertSame( str_repeat( "content-2\n", 50 ), $content );
	}

	/**
	 * Without a budget the traversal archives every file - legacy behavior
	 * is untouched.
	 */
	public function test_no_budget_archives_everything() {
		$archiver = new Tar_Archiver( MainWP_Backup::get(), 'tar' );
		$archiver->create( $this->archive_path );
		$archiver->add_dir( $this->fixture_dir, array() );
		$archiver->close();

		$entries = $this->list_tar_entries( $this->archive_path );
		foreach ( $this->fixture_files as $name ) {
			$this->assertContains( str_replace( ABSPATH, '', $this->fixture_dir ) . '/' . $name, $entries );
		}
	}

	/**
	 * An expired deadline stops the traversal after at least one entry -
	 * never zero (a slice always makes forward progress) and never all of
	 * them - and the on-disk archive stays a readable prefix.
	 */
	public function test_expired_deadline_stops_after_partial_progress() {
		$archiver = new Tar_Archiver( MainWP_Backup::get(), 'tar' );
		$archiver->create( $this->archive_path );
		$archiver->set_budget_deadline( time() );

		try {
			$archiver->add_dir( $this->fixture_dir, array() );
			$this->fail( 'Expected the slice budget to interrupt the traversal' );
		} catch ( MainWP_Backup_Budget_Exception $e ) {
			$this->assertNotEmpty( $e->getMessage() );
		}

		$entries = $this->list_tar_entries( $this->archive_path );
		$files   = array_filter(
			$entries,
			function ( $name ) {
				return false !== strpos( $name, 'file-' );
			}
		);
		$this->assertGreaterThanOrEqual( 1, count( $files ), 'A slice must make forward progress before bailing' );
		$this->assertLessThan( count( $this->fixture_files ), count( $files ), 'The expired budget must stop the traversal early' );
	}

	/**
	 * The archive left behind by a budget bail is structurally valid: every
	 * archived entry is complete and readable by the archiver itself.
	 */
	public function test_bailed_archive_is_a_readable_prefix() {
		$archiver = new Tar_Archiver( MainWP_Backup::get(), 'tar' );
		$archiver->create( $this->archive_path );
		$archiver->set_budget_deadline( time() );

		try {
			$archiver->add_dir( $this->fixture_dir, array() );
		} catch ( MainWP_Backup_Budget_Exception $e ) {
			unset( $e );
		}

		$entries = $this->list_tar_entries( $this->archive_path );
		$first   = null;
		foreach ( $entries as $name ) {
			if ( false !== strpos( $name, 'file-' ) ) {
				$first = $name;
				break;
			}
		}
		$this->assertNotNull( $first );

		$reader  = new Tar_Archiver( MainWP_Backup::get(), 'tar' );
		$reader->read( $this->archive_path );
		$content = $reader->get_from_name( $first );
		$reader->close();

		$index = (int) substr( basename( $first ), 5, 1 );
		$this->assertSame( str_repeat( "content-$index\n", 50 ), $content );
	}

	/**
	 * Clearing the deadline (null) restores unbounded traversal.
	 */
	public function test_null_deadline_disables_budget() {
		$archiver = new Tar_Archiver( MainWP_Backup::get(), 'tar' );
		$archiver->create( $this->archive_path );
		$archiver->set_budget_deadline( time() );
		$archiver->set_budget_deadline( null );
		$archiver->add_dir( $this->fixture_dir, array() );
		$archiver->close();

		$entries = $this->list_tar_entries( $this->archive_path );
		$files   = array_filter(
			$entries,
			function ( $name ) {
				return false !== strpos( $name, 'file-' );
			}
		);
		$this->assertCount( count( $this->fixture_files ), $files );
	}
}
