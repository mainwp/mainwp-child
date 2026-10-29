<?php // phpcs:ignore WordPress.Files.FileName.InvalidClassFileName -- Test fixture and adapters keep their consumer-oriented filenames.
/**
 * Pin the layouts supported by the current native backup implementation.
 *
 * @package Mainwp_Child
 */

use MainWP\Child\MainWP_Backup;
use MainWP\Child\MainWP_Backup_Db_Restore_Engine;
use MainWP\Child\MainWP_Backup_Error;
use MainWP\Child\MainWP_Child_Backup_Config_Includes;
use MainWP\Child\MainWP_Child_Backup_File_Walker;
use MainWP\Child\MainWP_Child_Backup_Mutex;
use MainWP\Child\MainWP_Child_Backup_Task;
use MainWP\Child\MainWP_Child_Backup_Task_Runner;
use MainWP\Child\MainWP_Child_Restore_Task_Runner;
use MainWP\Child\MainWP_Exception;
use MainWP\Child\Tar_Archiver;

// phpcs:disable WordPress.Files.FileName.InvalidClassFileName, Generic.Files.OneObjectStructurePerFile.MultipleFound -- Test adapters share the characterization file.
// phpcs:disable WordPress.WP.AlternativeFunctions -- Direct fixture IO verifies local permission failures and archive bytes.

require_once __DIR__ . '/helpers/class-external-layout-fixture.php';

/** Existing protected roots let real task creation use disposable layouts. */
trait External_Layout_Runner_Roots {
	/**
	 * Explicit fixture roots.
	 *
	 * @var array
	 */
	public $fixture;
	/**
	 * Calls past the layout guard.
	 *
	 * @var int
	 */
	public $preparation_calls = 0;

	/**
	 * Use the fixture core root.
	 *
	 * @return string Core root.
	 */
	protected function site_root() {
		return $this->fixture['site'] . '/';
	}

	/**
	 * Use the fixture content and uploads roots.
	 *
	 * @return string[] Content roots.
	 */
	protected function content_roots() {
		return array( $this->fixture['content'], $this->fixture['uploads'] );
	}

	/**
	 * Count preparation after the guard and keep writes outside core.
	 *
	 * @return string Backup scratch directory.
	 */
	protected function backup_dir() {
		++$this->preparation_calls;
		$path = $this->fixture['base'] . '/backup/';
		if ( ! is_dir( $path ) ) {
			mkdir( $path, 0755, true );
		}
		return $path;
	}
}

/** Backup admission through explicit fixture roots. */
class External_Layout_Backup_Runner extends MainWP_Child_Backup_Task_Runner {
	use External_Layout_Runner_Roots;
}

/** DB eligibility needs task admission, not restore table housekeeping. */
class External_Layout_Restore_Engine extends MainWP_Backup_Db_Restore_Engine {
	/**
	 * Leave unrelated database tables untouched.
	 *
	 * @param string $restore_id Fixture restore identifier.
	 */
	public function cleanup_tmp_tables( $restore_id ) {}
	/**
	 * Leave unrelated database tables untouched.
	 *
	 * @param string[] $restore_ids Fixture restore identifiers.
	 */
	public function prune_aside_tables( array $restore_ids ) {}
}

/** Restore admission through explicit fixture roots. */
class External_Layout_Restore_Runner extends MainWP_Child_Restore_Task_Runner {
	use External_Layout_Runner_Roots;

	/**
	 * Replace only post-admission database housekeeping.
	 *
	 * @return External_Layout_Restore_Engine Housekeeping stub.
	 */
	protected function restore_engine() {
		return new External_Layout_Restore_Engine();
	}
}

/** Current layout refusal and single-root archive behavior. */
class BackupExternalLayoutCharacterizationTest extends WP_UnitTestCase {
	/**
	 * Owner of disposable layouts.
	 *
	 * @var MainWP_External_Layout_Fixture
	 */
	private $fixtures;
	/**
	 * Owner of ABSPATH-relative tar layouts.
	 *
	 * @var MainWP_External_Layout_Fixture|null
	 */
	private $tar_fixtures;
	/**
	 * Task rows created by this test only.
	 *
	 * @var string[]
	 */
	private $task_ids = array();

	/**
	 * Create an owner for this test's scratch paths.
	 */
	public function set_up() {
		parent::set_up();
		$this->fixtures = new MainWP_External_Layout_Fixture();
		$this->task_ids = array();
	}

	/**
	 * Remove only this test's tasks, mutex ownership and scratch paths.
	 */
	public function tear_down() {
		global $wpdb;
		foreach ( $this->task_ids as $task_id ) {
			MainWP_Child_Backup_Mutex::release( $task_id );
			$wpdb->delete( MainWP_Child_Backup_Task::instance()->get_table_name(), array( 'task_id' => $task_id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Test-owned task row cleanup.
		}
		$this->fixtures->cleanup();
		if ( null !== $this->tar_fixtures ) {
			$this->tar_fixtures->cleanup();
			$this->tar_fixtures = null;
		}
		parent::tear_down();
	}

	/**
	 * Supply the current refusal matrix.
	 *
	 * @return array Named layouts and refusal expectations.
	 */
	public static function layouts() {
		return array(
			'normal'                   => array( 'normal', false ),
			'sibling content'          => array( 'sibling-content', true ),
			'moved uploads'            => array( 'moved-uploads', true ),
			'Bedrock app'              => array( 'bedrock', true ),
			'Bedrock internal content' => array( 'bedrock-internal-content', false ),
			'moved plugin roots'       => array( 'moved-plugins', false ),
			'read-only external core'  => array( 'read-only-core', true ),
			'parent config'            => array( 'parent-config', false ),
		);
	}

	/**
	 * TODAY: only content/uploads roots decide refusal, not plugin/config roots.
	 * Source: class/class-mainwp-backup.php:1377.
	 * Outward symlink predicates remain in tests/test-backup-symlinks.php:99-109.
	 *
	 * @param string $name Fixture name.
	 * @param bool   $refused Expected refusal.
	 * @dataProvider layouts
	 */
	public function test_current_layout_refusal_matrix( $name, $refused ) {
		$layout = $this->fixtures->build( $name );
		$roots  = array( $layout['content'], $layout['uploads'] );
		$this->assertSame( $refused, MainWP_Backup::content_outside_site_root( $layout['site'], $roots ) );
		$this->assertSame( $refused, MainWP_Backup::content_outside_site_root( $layout['site'], $roots, true ) );
		foreach ( array(
			'site'       => 'core',
			'content'    => 'content',
			'uploads'    => 'uploads',
			'plugins'    => 'plugins',
			'mu_plugins' => 'mu-plugins',
		) as $role => $bytes ) {
			$this->assertSame( $bytes, file_get_contents( $layout['sentinels'][ $role ] ) );
		}
	}

	/**
	 * Supply the two file-bearing task types.
	 *
	 * @return array File task types.
	 */
	public static function file_types() {
		return array(
			'files' => array( 'files' ),
			'full'  => array( 'full' ),
		);
	}

	/**
	 * TODAY: sibling content is refused before backup preparation.
	 * Source: class/class-mainwp-child-backup-task-runner.php:149-156.
	 *
	 * @param string $type File-bearing task type.
	 * @dataProvider file_types
	 */
	public function test_backup_entry_refuses_external_content( $type ) {
		$runner          = new External_Layout_Backup_Runner();
		$runner->fixture = $this->fixtures->build( 'sibling-content' );
		$error           = $this->backup_error( $runner, $type );
		$this->assertSame( MainWP_Backup_Error::ERR_UNSUPPORTED_LAYOUT, $error );
		$this->assertSame( 0, $runner->preparation_calls );
		$this->assertDirectoryDoesNotExist( $runner->fixture['base'] . '/backup' );
	}

	/**
	 * TODAY: DB-only backup still creates a queued task with external content.
	 * Source: class/class-mainwp-child-backup-task-runner.php:154,200-224.
	 */
	public function test_backup_entry_accepts_database_with_external_content() {
		$runner           = new External_Layout_Backup_Runner();
		$runner->fixture  = $this->fixtures->build( 'sibling-content' );
		$task_id          = $runner->create_task_for_params( 'db', array() );
		$this->task_ids[] = $task_id;
		$task             = MainWP_Child_Backup_Task::instance()->get_task( $task_id );
		$this->assertNotNull( $task );
		$this->assertSame( 'db', $task->type );
		$this->assertSame( MainWP_Child_Backup_Task::PHASE_QUEUED, $task->phase );
	}

	/**
	 * TODAY: sibling content is refused before file restore creates a task.
	 * Source: class/class-mainwp-child-restore-task-runner.php:315-321.
	 *
	 * @param string $type File-bearing task type.
	 * @dataProvider file_types
	 */
	public function test_restore_entry_refuses_external_content( $type ) {
		$runner          = new External_Layout_Restore_Runner();
		$runner->fixture = $this->fixtures->build( 'sibling-content' );
		$error           = null;
		try {
			$this->task_ids[] = $runner->create_restore_task( $type, $this->restore_params() );
		} catch ( MainWP_Exception $exception ) {
			$error = $exception->get_message_error_code();
		}
		$this->assertSame( MainWP_Backup_Error::ERR_UNSUPPORTED_LAYOUT, $error );
		$this->assertSame( 0, $runner->preparation_calls );
	}

	/**
	 * TODAY: DB restore remains eligible and creates a real queued task.
	 * Source: class/class-mainwp-child-restore-task-runner.php:319,369-379.
	 */
	public function test_restore_entry_accepts_database_with_external_content() {
		$runner           = new External_Layout_Restore_Runner();
		$runner->fixture  = $this->fixtures->build( 'sibling-content' );
		$reused           = true;
		$task_id          = $runner->create_restore_task( 'db', $this->restore_params(), $reused );
		$this->task_ids[] = $task_id;
		$task             = MainWP_Child_Backup_Task::instance()->get_task( $task_id );
		$this->assertFalse( $reused );
		$this->assertNotNull( $task );
		$this->assertSame( 'restore_db', $task->type );
		$this->assertSame( MainWP_Child_Backup_Task::PHASE_QUEUED, $task->phase );
	}

	/**
	 * TODAY: backup follows lexical content symlinks, while restore refuses them.
	 * Source: class/class-mainwp-child-backup-task-runner.php:154 and
	 * class/class-mainwp-child-restore-task-runner.php:319.
	 */
	public function test_symlink_fixture_reaches_real_runner_entries() {
		$layout           = $this->fixtures->build( 'symlink-content' );
		$restore          = new External_Layout_Restore_Runner();
		$restore->fixture = $layout;
		$error            = null;
		try {
			$this->task_ids[] = $restore->create_restore_task( 'files', $this->restore_params() );
		} catch ( MainWP_Exception $exception ) {
			$error = $exception->get_message_error_code();
		}
		$this->assertSame( MainWP_Backup_Error::ERR_UNSUPPORTED_LAYOUT, $error );
		$backup           = new External_Layout_Backup_Runner();
		$backup->fixture  = $layout;
		$task_id          = $backup->create_task_for_params( 'files', array() );
		$this->task_ids[] = $task_id;
		$this->assertSame( 'files', MainWP_Child_Backup_Task::instance()->get_task( $task_id )->type );
	}

	/**
	 * TODAY: the normal v1 manifest has sorted relative paths, sizes and mtimes.
	 * Source: class/class-mainwp-child-backup-file-walker.php:231,283.
	 */
	public function test_normal_walker_v1_golden_rows() {
		$layout = $this->fixtures->build( 'normal' );
		$this->assertSame(
			"sentinel.txt\t4\t1700000000\n" .
			"wp-config.php\t6\t1700000000\n" .
			"wp-content/mu-plugins/sentinel.txt\t10\t1700000000\n" .
			"wp-content/plugins/sentinel.txt\t7\t1700000000\n" .
			"wp-content/sentinel.txt\t7\t1700000000\n" .
			"wp-content/uploads/sentinel.txt\t7\t1700000000\n",
			$this->manifest( $layout )
		);
	}

	/**
	 * TODAY: tar member names strip process ABSPATH, not an injectable site root.
	 * Source: class/class-tar-archiver.php:688,711,766.
	 */
	public function test_normal_tar_v1_golden_member_names() {
		$this->tar_fixtures = new MainWP_External_Layout_Fixture( ABSPATH );
		$layout             = $this->tar_fixtures->build( 'normal' );
		$archive            = $this->tar_fixtures->scratch . '/normal.tar';
		$tar                = new Tar_Archiver( null, 'tar' );
		$tar->create( $archive );
		try {
			$tar->add_dir( $layout['site'], array(), array(), array( $layout['site'] ) );
		} finally {
			$tar->close();
		}
		$prefix = substr( $layout['site'], strlen( ABSPATH ) );
		$names  = array();
		$handle = fopen( $archive, 'rb' );
		try {
			while ( ! feof( $handle ) ) {
				$header = fread( $handle, 512 );
				if ( '' === $header || str_repeat( "\0", 512 ) === $header ) {
					break;
				}
				$this->assertSame( 512, strlen( $header ) );
				$name  = rtrim( substr( $header, 0, 100 ), "\0" );
				$ustar = rtrim( substr( $header, 345, 155 ), "\0" );
				$name  = rtrim( ( '' === $ustar ? '' : $ustar . '/' ) . $name, '/' );
				$this->assertTrue( $name === $prefix || 0 === strpos( $name, $prefix . '/' ), $name );
				$names[] = ltrim( substr( $name, strlen( $prefix ) ), '/' );
				$size    = octdec( trim( substr( $header, 124, 12 ), "\0 " ) );
				$this->assertSame( 0, fseek( $handle, (int) ceil( $size / 512 ) * 512, SEEK_CUR ) );
			}
		} finally {
			fclose( $handle );
		}
		sort( $names, SORT_STRING );
		$this->assertSame( array( '', 'sentinel.txt', 'wp-config.php', 'wp-content', 'wp-content/mu-plugins', 'wp-content/mu-plugins/sentinel.txt', 'wp-content/plugins', 'wp-content/plugins/sentinel.txt', 'wp-content/sentinel.txt', 'wp-content/uploads', 'wp-content/uploads/sentinel.txt' ), $names );
	}

	/**
	 * TODAY: the one-root walker omits external plugin and mu-plugin roots.
	 * Source: class/class-mainwp-child-backup-file-walker.php:231,630-645.
	 */
	public function test_walker_omits_moved_plugin_roots() {
		$layout = $this->fixtures->build( 'moved-plugins' );
		$rows   = $this->manifest( $layout );
		$this->assertSame(
			"sentinel.txt\t4\t1700000000\nwp-config.php\t6\t1700000000\n" .
			"wp-content/sentinel.txt\t7\t1700000000\nwp-content/uploads/sentinel.txt\t7\t1700000000\n",
			$rows
		);
		$this->assertFileExists( $layout['plugins'] . '/sentinel.txt' );
		$this->assertFileExists( $layout['mu_plugins'] . '/sentinel.txt' );
	}

	/**
	 * TODAY: config discovery finds a parent config, but a site-root walk omits it.
	 * Source: class/class-mainwp-child-backup-config-includes.php:28-34 and
	 * class/class-mainwp-child-backup-file-walker.php:231.
	 */
	public function test_parent_config_is_found_outside_walker_root() {
		$layout = $this->fixtures->build( 'parent-config' );
		$this->assertSame( $layout['config'], MainWP_Child_Backup_Config_Includes::locate( $layout['site'] ) );
		$this->assertStringNotContainsString( 'wp-config.php', $this->manifest( $layout ) );
	}

	/**
	 * TODAY: an external layout is refused before preparing writable backup paths.
	 * Source: class/class-mainwp-child-backup-task-runner.php:154-165.
	 */
	public function test_read_only_core_refusal_precedes_preparation_and_preserves_tree() {
		$layout = $this->fixtures->build( 'read-only-core' );
		$probe  = $layout['site'] . '/write-probe';
		$create = @fopen( $probe, 'wb' ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- Expected permission failure is asserted below.
		$write  = @fopen( $layout['sentinels']['site'], 'r+b' ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- Expected permission failure is asserted below.
		if ( false !== $create ) {
			fclose( $create );
			unlink( $probe );
		}
		if ( false !== $write ) {
			fclose( $write );
		}
		if ( function_exists( 'posix_geteuid' ) && 0 === posix_geteuid() ) {
			$this->markTestSkipped( 'Tests run as root; chmod does not block writes.' );
		}
		$this->assertFalse( $create, 'Core directory must reject a real file creation.' );
		$this->assertFalse( $write, 'Core file must reject a real write handle.' );
		$before          = $this->snapshot( $layout['site'] );
		$runner          = new External_Layout_Backup_Runner();
		$runner->fixture = $layout;
		$error           = $this->backup_error( $runner, 'full' );
		$this->assertSame(
			array(
				'error'             => MainWP_Backup_Error::ERR_UNSUPPORTED_LAYOUT,
				'preparation_calls' => 0,
			),
			array(
				'error'             => $error,
				'preparation_calls' => $runner->preparation_calls,
			)
		);
		$this->assertSame( $before, $this->snapshot( $layout['site'] ) );
	}

	/**
	 * Supply minimal parameters for task admission.
	 *
	 * @return array Restore task parameters.
	 */
	private function restore_params() {
		return array(
			'restore_id'    => 'fixture-' . uniqid(),
			'source_prefix' => 'fixture_',
			'artifacts'     => array(),
		);
	}

	/**
	 * Capture refusal while retaining unexpected task IDs for cleanup.
	 *
	 * @param External_Layout_Backup_Runner $runner Fixture runner.
	 * @param string                        $type Backup type.
	 * @return string|null Refusal code, or null on unexpected acceptance.
	 */
	private function backup_error( $runner, $type ) {
		try {
			$this->task_ids[] = $runner->create_task_for_params( $type, array() );
		} catch ( MainWP_Exception $exception ) {
			return $exception->get_message_error_code();
		}
		return null;
	}

	/**
	 * Walk into sidecars outside the input tree.
	 *
	 * @param array $layout Explicit roots.
	 * @return string Literal manifest rows.
	 */
	private function manifest( $layout ) {
		$out = $layout['base'] . '/sidecars';
		mkdir( $out );
		( new MainWP_Child_Backup_File_Walker( $layout['site'] ) )->emit_sidecars( $out );
		return file_get_contents( $out . '/files.list.staged' );
	}

	/**
	 * Record core paths, hashes and mtimes without changing files.
	 *
	 * @param string $root Core path.
	 * @return array Sorted core snapshot.
	 */
	private function snapshot( $root ) {
		clearstatcache();
		$rows = array( '' => array( 'dir', filemtime( $root ) ) );
		$iter = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $root, FilesystemIterator::SKIP_DOTS ), RecursiveIteratorIterator::SELF_FIRST );
		foreach ( $iter as $path => $info ) {
			$rows[ substr( $path, strlen( $root ) + 1 ) ] = array( $info->isDir() ? 'dir' : hash_file( 'sha256', $path ), $info->getMTime() );
		}
		ksort( $rows, SORT_STRING );
		return $rows;
	}
}
