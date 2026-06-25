<?php
/**
 * Class BackupRestoreTaskRunnerTest
 *
 * @package Mainwp_Child
 */

use MainWP\Child\MainWP_Backup_Db_Restore_Engine;
use MainWP\Child\MainWP_Backup_Error;
use MainWP\Child\MainWP_Child_Backup_Task;
use MainWP\Child\MainWP_Child_Backup_Task_Runner;
use MainWP\Child\MainWP_Child_Restore_Task_Runner;
use MainWP\Child\MainWP_Exception;

/**
 * Scripted DB restore engine: no database side effects, behavior set
 * by the test.
 */
class Fake_Restore_Engine extends MainWP_Backup_Db_Restore_Engine {

	/**
	 * Error the replay should report, or null.
	 *
	 * @var string|null
	 */
	public $replay_error = null;

	/**
	 * Whether replay_slice should throw instead of returning an error.
	 *
	 * @var bool
	 */
	public $replay_throw = false;

	/**
	 * Whether exit_maintenance should throw, to model a post-replay failure.
	 *
	 * @var bool
	 */
	public $exit_maintenance_throw = false;

	/**
	 * Whether the first replay slice yields unfinished, so a task can be
	 * paused mid-replay in the replaying_db phase.
	 *
	 * @var bool
	 */
	public $replay_yield_once = false;

	/**
	 * Replay slice counter backing replay_yield_once.
	 *
	 * @var int
	 */
	public $replay_calls = 0;

	/**
	 * Tables the replay reports.
	 *
	 * @var array
	 */
	public $tables = array(
		'items' => array(
			'status' => 'ok',
			'rows'   => 2,
		),
	);

	/**
	 * Call log.
	 *
	 * @var string[]
	 */
	public $calls = array();

	/**
	 * Last config handed to replay_slice.
	 *
	 * @var array|null
	 */
	public $last_replay_config = null;

	/**
	 * Last expected-count map handed to verify_row_counts.
	 *
	 * @var array|null
	 */
	public $last_verify_expected = null;

	/**
	 * Scripted replay.
	 *
	 * @param array     $cursor   Cursor.
	 * @param array     $config   Config.
	 * @param float|int $deadline Deadline.
	 * @param bool      $unclean  Unclean resume flag.
	 *
	 * @return array Slice result.
	 */
	public function replay_slice( $cursor, $config, $deadline, $unclean = false ) {
		$this->calls[]            = 'replay_slice' . ( $unclean ? ':unclean' : '' ) . ( ! empty( $config['fk_path'] ) ? ':fk' : '' );
		$this->last_replay_config = $config;
		if ( $this->replay_throw ) {
			throw new MainWP_Exception( 'replay exploded', null, MainWP_Backup_Error::ERR_RESTORE_DB_REPLAY ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- test fixture message.
		}
		if ( null !== $this->replay_error ) {
			return array(
				'done'       => false,
				'cursor'     => array( 'tables' => array() ),
				'error'      => $this->replay_error,
				'error_code' => MainWP_Backup_Error::ERR_RESTORE_DB_REPLAY,
			);
		}
		if ( $this->replay_yield_once && 0 === $this->replay_calls++ ) {
			return array(
				'done'       => false,
				'cursor'     => array( 'tables' => array() ),
				'error'      => null,
				'error_code' => null,
			);
		}
		return array(
			'done'       => true,
			'cursor'     => array( 'tables' => $this->tables ),
			'error'      => null,
			'error_code' => null,
		);
	}

	/**
	 * Scripted swap.
	 *
	 * @param array $stems  Stems.
	 * @param array $config Config.
	 *
	 * @return array Swap result.
	 */
	public function swap_tables( $stems, $config ) {
		$this->calls[] = 'swap_tables:' . implode( ',', $stems );
		return array(
			'done'       => true,
			'old_tables' => array( 'old' . $config['restore_id'] . '_items' ),
			'rename_sql' => 'RENAME TABLE ...',
			'error'      => null,
			'error_code' => null,
		);
	}

	/**
	 * Whether the scripted verification reports matching row counts.
	 *
	 * @var bool
	 */
	public $verify_ok = true;

	/**
	 * Scripted verification.
	 *
	 * @param array  $expected      Expected counts.
	 * @param string $target_prefix Prefix.
	 *
	 * @return array Verification result.
	 */
	public function verify_row_counts( $expected, $target_prefix ) {
		$this->calls[]               = 'verify_row_counts';
		$this->last_verify_expected = $expected;
		return array(
			'ok'         => $this->verify_ok,
			'mismatches' => $this->verify_ok ? array() : array( 'items' => array( 'expected' => 2, 'actual' => 1 ) ),
		);
	}

	/**
	 * Tracked drop.
	 *
	 * @param string $restore_id Restore id.
	 */
	public function drop_old_tables( $restore_id ) {
		$this->calls[] = 'drop_old_tables';
	}

	/**
	 * Tracked cleanup.
	 *
	 * @param string $restore_id Restore id.
	 */
	public function cleanup_tmp_tables( $restore_id ) {
		$this->calls[] = 'cleanup_tmp_tables';
	}

	/**
	 * Tracked maintenance exit.
	 */
	public function exit_maintenance() {
		$this->calls[] = 'exit_maintenance';
		if ( $this->exit_maintenance_throw ) {
			throw new \RuntimeException( 'exit maintenance failed' );
		}
	}

	/**
	 * Whether the swap-grant probe reports the grants exist.
	 *
	 * @var bool
	 */
	public $grants_ok = true;

	/**
	 * Scripted grant probe.
	 *
	 * @return bool
	 */
	public function probe_swap_grants() {
		$this->calls[] = 'probe_swap_grants';
		return $this->grants_ok;
	}

	/**
	 * Error the aside snapshot should report, or null.
	 *
	 * @var string|null
	 */
	public $snapshot_error = null;

	/**
	 * Error the rollback should report, or null.
	 *
	 * @var string|null
	 */
	public $rollback_error = null;

	/**
	 * Live tables the dump-table split reports as existing.
	 *
	 * @var string[]
	 */
	public $fk_existing = array( 'rt_items' );

	/**
	 * Stems the dump-table split reports as new (absent live).
	 *
	 * @var string[]
	 */
	public $fk_new_stems = array();

	/**
	 * No-op maintenance entry: the real one writes a .maintenance flag into
	 * the WordPress root, which would break the next test's bootstrap.
	 */
	public function begin_maintenance() {
		$this->calls[] = 'begin_maintenance';
	}

	/**
	 * Scripted dump-table split.
	 *
	 * @param string[] $files         Dump files.
	 * @param string   $source_prefix Source prefix.
	 * @param string   $target_prefix Target prefix.
	 * @param string[] $skip_stems    Skip list.
	 *
	 * @return array Existing/new split.
	 */
	public function list_dump_live_tables( $files, $source_prefix, $target_prefix, $skip_stems = array() ) {
		$this->calls[] = 'list_dump_live_tables';
		return array(
			'existing'  => $this->fk_existing,
			'new_stems' => $this->fk_new_stems,
		);
	}

	/**
	 * Scripted aside snapshot.
	 *
	 * @param array     $cursor   Cursor.
	 * @param array     $config   Config.
	 * @param float|int $deadline Deadline.
	 *
	 * @return array Slice result.
	 */
	public function snapshot_tables_slice( $cursor, $config, $deadline ) {
		$this->calls[] = 'snapshot_tables_slice';
		if ( null !== $this->snapshot_error ) {
			return array(
				'done'       => false,
				'cursor'     => array(),
				'files'      => array(),
				'error'      => $this->snapshot_error,
				'error_code' => MainWP_Backup_Error::ERR_RESTORE_SNAPSHOT_FAILED,
			);
		}
		return array(
			'done'       => true,
			'cursor'     => array(),
			'files'      => array( '/tmp/aside-rt_items.sql.php' ),
			'error'      => null,
			'error_code' => null,
		);
	}

	/**
	 * Scripted rollback.
	 *
	 * @param array     $cursor   Cursor.
	 * @param array     $config   Config.
	 * @param float|int $deadline Deadline.
	 * @param bool      $unclean  Unclean resume flag.
	 *
	 * @return array Slice result.
	 */
	public function rollback_aside_slice( $cursor, $config, $deadline, $unclean = false ) {
		$this->calls[] = 'rollback_aside_slice';
		if ( null !== $this->rollback_error ) {
			return array(
				'done'       => false,
				'cursor'     => array(),
				'error'      => $this->rollback_error,
				'error_code' => MainWP_Backup_Error::ERR_RESTORE_ROLLBACK_FAILED,
			);
		}
		return array(
			'done'       => true,
			'cursor'     => array(),
			'error'      => null,
			'error_code' => null,
		);
	}
}

/**
 * Restore runner with every environment dependency pinned.
 */
class Testable_Restore_Task_Runner extends MainWP_Child_Restore_Task_Runner {

	/**
	 * Pinned backup dir.
	 *
	 * @var string
	 */
	public $test_backup_dir = '';

	/**
	 * Pinned site root.
	 *
	 * @var string
	 */
	public $test_site_root = '';

	/**
	 * Shared fake engine.
	 *
	 * @var Fake_Restore_Engine
	 */
	public $fake_engine;

	/**
	 * Sentinel result.
	 *
	 * @var bool
	 */
	public $sentinel_ok = true;

	/**
	 * Pinned dir.
	 *
	 * @return string
	 */
	protected function backup_dir() {
		return $this->test_backup_dir;
	}

	/**
	 * Pinned root.
	 *
	 * @return string
	 */
	protected function site_root() {
		return $this->test_site_root;
	}

	/**
	 * Shared fake.
	 *
	 * @return MainWP_Backup_Db_Restore_Engine
	 */
	protected function restore_engine() {
		return $this->fake_engine;
	}

	/**
	 * Scratch prefix.
	 *
	 * @return string
	 */
	protected function target_prefix() {
		return 'rt_';
	}

	/**
	 * Expose the WooCommerce skip-stem builder for direct assertion.
	 *
	 * @param array $sub_state Resume cursor.
	 *
	 * @return string[] Stems.
	 */
	public function expose_woo_skip_stems( $sub_state ) {
		return $this->build_woo_skip_stems( $sub_state );
	}

	/**
	 * Expose the preserve gate for direct assertion.
	 *
	 * @param string[] $files         Dump files.
	 * @param string   $source_prefix Source prefix.
	 *
	 * @return string|null Error code or null.
	 */
	public function expose_woo_preserve_gate( $files, $source_prefix ) {
		return $this->woo_preserve_gate( $files, $source_prefix );
	}

	/**
	 * Expose the capture step for direct assertion.
	 *
	 * @param array  $sub_state Resume cursor.
	 * @param string $scratch   Scratch dir.
	 *
	 * @return array Capture summary.
	 */
	public function expose_capture_woo_state( $sub_state, $scratch ) {
		return $this->capture_woo_state( $sub_state, $scratch );
	}

	/**
	 * Expose the merge step for direct assertion.
	 *
	 * @param array  $sub_state Resume cursor.
	 * @param string $scratch   Scratch dir.
	 *
	 * @return array Merge summary.
	 */
	public function expose_merge_woo_state( $sub_state, $scratch ) {
		return $this->merge_woo_state( $sub_state, $scratch );
	}

	/**
	 * Expose the orphan scan for direct assertion.
	 *
	 * @return array Orphan counts.
	 */
	public function expose_scan_woo_orphans() {
		return $this->scan_woo_orphans();
	}

	/**
	 * Stubbed sentinel.
	 *
	 * @param string $target_prefix Prefix.
	 * @param string $restore_id    Restore id.
	 *
	 * @return bool
	 */
	protected function sentinel_round_trip( $target_prefix, $restore_id ) {
		return $this->sentinel_ok;
	}

	/**
	 * Scripted multisite flag.
	 *
	 * @var bool
	 */
	public $fake_is_multisite = false;

	/**
	 * Pinned multisite check.
	 *
	 * @return bool
	 */
	protected function is_multisite_install() {
		return $this->fake_is_multisite;
	}

	/**
	 * Loopback stub.
	 *
	 * @return int
	 */
	protected function loopback_status() {
		return 200;
	}

	/**
	 * Injected sub-blog URLs (blog_id => home URL). Child tests boot
	 * frontend-only and cannot enable real multisite, so this seam stands
	 * in for the production get_sites() walk.
	 *
	 * @var array<int,string>
	 */
	public $fake_sub_site_urls = array();

	/**
	 * Scripted sub-blog URLs.
	 *
	 * @return array<int,string>
	 */
	protected function sub_site_urls() {
		return $this->fake_sub_site_urls;
	}

	/**
	 * Public proxy so tests can exercise the protected smoke check.
	 *
	 * @return array<int,array>
	 */
	public function expose_sub_site_probes() {
		return $this->sub_site_probes();
	}

	/**
	 * No cache side effects in tests.
	 */
	protected function flush_caches() {
	}

	/**
	 * Scripted free disk space, or null to defer to the real probe.
	 *
	 * @var float|null
	 */
	public $fake_free_space = null;

	/**
	 * Scripted free space, so the snapshot disk preflight can be driven
	 * without a real low-disk host.
	 *
	 * @param string $dir Directory.
	 *
	 * @return float|null
	 */
	protected function free_disk_space( $dir ) {
		if ( null !== $this->fake_free_space ) {
			return $this->fake_free_space;
		}
		return parent::free_disk_space( $dir );
	}

	/**
	 * Scripted aside-copy size estimate, or null to query information_schema.
	 * The real query is unreliable inside the WP test transaction (a freshly
	 * created table has no settled information_schema row), so the disk
	 * preflight is driven through this seam.
	 *
	 * @var int|null
	 */
	public $fake_snapshot_bytes = null;

	/**
	 * Scripted size estimate.
	 *
	 * @param string[] $tables Tables.
	 *
	 * @return int
	 */
	protected function estimate_live_tables_bytes( $tables ) {
		if ( null !== $this->fake_snapshot_bytes ) {
			return (int) $this->fake_snapshot_bytes;
		}
		return parent::estimate_live_tables_bytes( $tables );
	}
}

/**
 * Restore runner whose cloud downloads are scripted: URLs map to bytes
 * (or false for a failed transfer), and every pulled URL is recorded.
 */
class Cloud_Pull_Test_Restore_Runner extends Testable_Restore_Task_Runner {

	/**
	 * Scripted responses: url => bytes, or false for a failure.
	 *
	 * @var array<string,string|false>
	 */
	public $pull_responses = array();

	/**
	 * Every URL the runner tried to pull.
	 *
	 * @var string[]
	 */
	public $pulled_urls = array();

	/**
	 * Scripted download.
	 *
	 * @param string $url       Presigned URL.
	 * @param string $dest_path Local target.
	 *
	 * @return bool
	 */
	protected function download_url_to_file( $url, $dest_path ) {
		$this->pulled_urls[] = $url;
		if ( ! array_key_exists( $url, $this->pull_responses ) || false === $this->pull_responses[ $url ] ) {
			return false;
		}
		file_put_contents( $dest_path, $this->pull_responses[ $url ] ); // phpcs:ignore -- fixture.
		return true;
	}
}

/**
 * Restore runner whose DNS resolution is scripted so the SSRF host
 * checks run without real network lookups.
 */
class Ssrf_Resolver_Test_Restore_Runner extends MainWP_Child_Restore_Task_Runner {

	/**
	 * Scripted host => IP map. A host absent from the map resolves to
	 * itself, matching the gethostbyname-failure contract.
	 *
	 * @var array<string,string>
	 */
	public static $resolved = array();

	/**
	 * Scripted resolution: a mapped host yields its IP(s) (a string or an
	 * array), an unmapped host yields none - matching the real resolver's
	 * empty-on-failure contract.
	 *
	 * @param string $host Hostname.
	 *
	 * @return string[]
	 */
	protected static function resolve_host_ips( $host ) {
		return isset( self::$resolved[ $host ] ) ? (array) self::$resolved[ $host ] : array();
	}
}

/**
 * Tests for the restore task runner: the full phase pipeline over real
 * artifacts, failure cleanup discipline, the in-place FK route, and
 * watchdog ownership routing.
 */
class BackupRestoreTaskRunnerTest extends WP_UnitTestCase {

	/**
	 * Scratch dir.
	 *
	 * @var string
	 */
	protected $dir;

	/**
	 * Fake site root.
	 *
	 * @var string
	 */
	protected $site;

	/**
	 * Runner under test.
	 *
	 * @var Testable_Restore_Task_Runner
	 */
	protected $runner;

	/**
	 * Spine.
	 *
	 * @var MainWP_Child_Backup_Task
	 */
	protected $spine;

	/**
	 * Build scratch dirs, runner, fake engine.
	 */
	public function set_up() {
		parent::set_up();
		$this->dir  = sys_get_temp_dir() . '/mainwp-restore-runner-' . uniqid();
		$this->site = $this->dir . '/site';
		mkdir( $this->site, 0777, true );

		$this->runner                  = new Testable_Restore_Task_Runner();
		$this->runner->test_backup_dir = $this->dir;
		$this->runner->test_site_root  = $this->site;
		$this->runner->fake_engine     = new Fake_Restore_Engine();

		$this->spine = MainWP_Child_Backup_Task::instance();
		$this->spine->install();

		// The pinned target prefix is rt_; the rewrite phase pages this
		// table for real.
		global $wpdb;
		$wpdb->query( 'CREATE TABLE IF NOT EXISTS `rt_items` (id INT NOT NULL, val TEXT, PRIMARY KEY (id))' ); // phpcs:ignore WordPress.DB -- test fixture.
	}

	/**
	 * Cleanup.
	 */
	public function tear_down() {
		global $wpdb;
		$wpdb->query( 'DELETE FROM ' . $this->spine->get_table_name() ); // phpcs:ignore WordPress.DB -- test cleanup.
		$wpdb->query( 'DROP TABLE IF EXISTS `rt_items`' ); // phpcs:ignore WordPress.DB -- test cleanup.
		foreach ( array( 'rt_options', 'rt_users', 'rt_usermeta', 'rt_postmeta', 'rt_wc_orders', 'rt_wc_product_meta_lookup', 'rt_actionscheduler_logs', 'rt_actionscheduler_actions' ) as $woo_table ) {
			$wpdb->query( 'DROP TABLE IF EXISTS `' . $woo_table . '`' ); // phpcs:ignore WordPress.DB -- test cleanup.
		}
		$this->rrmdir( $this->dir );
		parent::tear_down();
	}

	/**
	 * Recursive cleanup.
	 *
	 * @param string $dir Directory.
	 */
	protected function rrmdir( $dir ) {
		if ( ! is_dir( $dir ) ) {
			return;
		}
		foreach ( (array) scandir( $dir ) as $entry ) {
			if ( '.' === $entry || '..' === $entry ) {
				continue;
			}
			$path = $dir . '/' . $entry;
			if ( is_dir( $path ) ) {
				$this->rrmdir( $path );
			} else {
				unlink( $path );
			}
		}
		rmdir( $dir );
	}

	/**
	 * Build the db dump zip artifact in the backup dir (adoptable).
	 *
	 * @param bool $fk Whether the banner declares foreign keys.
	 *
	 * @return array Artifact entry.
	 */
	protected function make_db_artifact( $fk = false ) {
		$banner = '-- mainwp-backup-info: {"site_url":"' . site_url() . '","home_url":"' . home_url() . '","table_prefix":"src_"}' . "\n"
			. '-- mainwp-backup-meta: {"has_foreign_keys":' . ( $fk ? 'true' : 'false' ) . ',"tier":1}';
		$sql    = '<?php exit(); ?>' . "\n" . $banner . "\n\nDROP TABLE IF EXISTS `src_items`;\nCREATE TABLE `src_items` (id INT);\nINSERT INTO `src_items` (`id`) VALUES (1),(2);\n";

		$zip_path = $this->dir . '/db.zip';
		$zip      = new ZipArchive();
		$zip->open( $zip_path, ZipArchive::CREATE );
		$zip->addFromString( 'dbBackup-src_items.sql.php', $sql );
		$zip->close();

		return array(
			'role'       => 'db',
			'file'       => 'db.zip',
			'size'       => (int) filesize( $zip_path ),
			'sha256'     => hash_file( 'sha256', $zip_path ),
			'adopt_only' => false,
		);
	}

	/**
	 * Build a file-part zip artifact in the backup dir.
	 *
	 * @return array Artifact entry.
	 */
	protected function make_part_artifact() {
		$zip_path = $this->dir . '/part001.zip';
		$zip      = new ZipArchive();
		$zip->open( $zip_path, ZipArchive::CREATE );
		$zip->addFromString( 'wp-content/uploads/restored.txt', 'restored-content' );
		$zip->close();

		return array(
			'role'       => 'part',
			'file'       => 'part001.zip',
			'size'       => (int) filesize( $zip_path ),
			'sha256'     => hash_file( 'sha256', $zip_path ),
			'adopt_only' => false,
		);
	}

	/**
	 * Build a plain-full-style archive in the backup dir: site files plus
	 * the embedded per-table dump and zero-byte marker a full backup
	 * carries, with no separate db artifact.
	 *
	 * @param bool $bannerless Whether the embedded dump omits its banner.
	 *
	 * @return array Artifact entry.
	 */
	protected function make_full_archive_with_embedded_dump( $bannerless = false ) {
		$sql = '<?php exit(); ?>' . "\n";
		if ( ! $bannerless ) {
			$sql .= '-- mainwp-backup-info: {"site_url":"' . site_url() . '","home_url":"' . home_url() . '","table_prefix":"bn_"}' . "\n"
				. '-- mainwp-backup-meta: {"has_foreign_keys":false,"tier":1}' . "\n";
		}
		$sql .= "\nDROP TABLE IF EXISTS `bn_items`;\nCREATE TABLE `bn_items` (id INT);\nINSERT INTO `bn_items` (`id`) VALUES (1),(2);\n";

		$zip_path = $this->dir . '/full001.zip';
		$zip      = new ZipArchive();
		$zip->open( $zip_path, ZipArchive::CREATE );
		$zip->addFromString( 'wp-content/uploads/restored.txt', 'restored-content' );
		$zip->addFromString( 'wp-content/dbBackup-bn_items.sql.php', $sql );
		$zip->addFromString( 'wp-content/dbBackup.sql', '' );
		$zip->close();

		return array(
			'role'       => 'part',
			'file'       => 'full001.zip',
			'size'       => (int) filesize( $zip_path ),
			'sha256'     => hash_file( 'sha256', $zip_path ),
			'adopt_only' => false,
		);
	}

	/**
	 * Build a files-only archive in the backup dir: site files, no
	 * database dump of any kind. A files snapshot's archive rides the
	 * full download slot, so its role is 'full'.
	 *
	 * @return array Artifact entry.
	 */
	protected function make_files_archive_artifact() {
		$zip_path = $this->dir . '/files001.zip';
		$zip      = new ZipArchive();
		$zip->open( $zip_path, ZipArchive::CREATE );
		$zip->addFromString( 'wp-content/uploads/restored.txt', 'restored-content' );
		$zip->close();

		return array(
			'role'       => 'full',
			'file'       => 'files001.zip',
			'size'       => (int) filesize( $zip_path ),
			'sha256'     => hash_file( 'sha256', $zip_path ),
			'adopt_only' => false,
		);
	}

	/**
	 * Build a tombstones artifact in the backup dir.
	 *
	 * @return array Artifact entry.
	 */
	protected function make_tombstones_artifact() {
		$path = $this->dir . '/tombstones.list';
		file_put_contents( $path, "stale.txt\n" ); // phpcs:ignore -- fixture.
		return array(
			'role'       => 'tombstones',
			'file'       => 'tombstones.list',
			'size'       => (int) filesize( $path ),
			'sha256'     => hash_file( 'sha256', $path ),
			'adopt_only' => false,
		);
	}

	/**
	 * Create a restore task whose artifacts sit in the backup dir for
	 * adoption.
	 *
	 * @param string $sub_type  'full' or 'db'.
	 * @param array  $artifacts Artifact entries.
	 * @param array  $over      Param overrides.
	 *
	 * @return string Task id.
	 */
	protected function make_task( $sub_type, $artifacts, $over = array() ) {
		$params = array_merge(
			array(
				'restore_id'       => 'rrun01',
				'source_prefix'    => 'src_',
				'artifacts'        => $artifacts,
				'has_foreign_keys' => null,
				'file_rollback'    => false,
			),
			$over
		);
		return $this->runner->create_restore_task( $sub_type, $params );
	}

	/**
	 * Drive the task to a terminal phase.
	 *
	 * @param string $task_id Task id.
	 *
	 * @return object Final task row.
	 */
	protected function drive( $task_id ) {
		$guard = 0;
		$task  = $this->spine->get_task( $task_id );
		while ( ! MainWP_Child_Backup_Task::is_terminal_phase( $task->phase ) && $guard < 30 ) {
			$task = $this->runner->advance_one_slice( $task, MainWP_Child_Backup_Task_Runner::LEASE_OWNER_DASHBOARD );
			++$guard;
		}
		return $task;
	}

	/**
	 * With preserve_orders set, the WooCommerce HPOS order, order-item, and
	 * order-derived analytics stems join the replay skip list so the live
	 * order tables are left in place.
	 */
	public function test_preserve_orders_builds_woo_skip_stems() {
		$stems = $this->runner->expose_woo_skip_stems(
			array( 'params' => array( 'preserve_orders' => true ) )
		);

		foreach ( array( 'wc_orders', 'wc_orders_meta', 'wc_order_addresses', 'woocommerce_order_items', 'woocommerce_order_itemmeta', 'wc_customer_lookup' ) as $stem ) {
			$this->assertContains( $stem, $stems );
		}
		// The shared product/postmeta tables are never skip-listed: they revert
		// and the merge phase re-applies stock from the captured live values.
		$this->assertNotContains( 'wc_product_meta_lookup', $stems );
		$this->assertNotContains( 'posts', $stems );
		$this->assertNotContains( 'users', $stems );
	}

	/**
	 * A restore that did not opt in adds nothing, so its replay is byte for
	 * byte the same as before the feature.
	 */
	public function test_no_preserve_orders_builds_empty_woo_skip_stems() {
		$this->assertSame( array(), $this->runner->expose_woo_skip_stems( array( 'params' => array() ) ) );
		$this->assertSame( array(), $this->runner->expose_woo_skip_stems( array( 'params' => array( 'preserve_orders' => false ) ) ) );
	}

	/**
	 * A non-preserve db restore replays with only the spine table skip-listed,
	 * proving the array_merge with an empty WooCommerce list is a no-op.
	 */
	public function test_non_preserve_restore_skips_only_the_spine_table() {
		$task_id = $this->make_task( 'db', array( $this->make_db_artifact() ) );
		$task    = $this->drive( $task_id );

		$this->assertSame( MainWP_Child_Backup_Task::PHASE_COMPLETED, $task->phase );
		$this->assertSame(
			array( 'mainwp_child_backup_task' ),
			$this->runner->fake_engine->last_replay_config['skip_stems']
		);
	}

	/**
	 * Create the rt_-prefixed WooCommerce fixture tables and seed a live store:
	 * HPOS authoritative, sync off, a managed-stock product, and a customer
	 * (ID 50) referenced by a live order. Option overrides drive the gate tests.
	 *
	 * @param array $options Option overrides keyed by option name.
	 */
	protected function seed_woo_tables( $options = array() ) {
		global $wpdb;
		$wpdb->query( 'CREATE TABLE IF NOT EXISTS `rt_options` (option_id BIGINT NOT NULL AUTO_INCREMENT, option_name VARCHAR(191) NOT NULL, option_value LONGTEXT, autoload VARCHAR(20) DEFAULT \'yes\', PRIMARY KEY (option_id), UNIQUE KEY option_name (option_name))' ); // phpcs:ignore WordPress.DB
		$wpdb->query( 'CREATE TABLE IF NOT EXISTS `rt_users` (ID BIGINT NOT NULL AUTO_INCREMENT, user_login VARCHAR(60) DEFAULT \'\', user_pass VARCHAR(255) DEFAULT \'\', user_nicename VARCHAR(50) DEFAULT \'\', user_email VARCHAR(100) DEFAULT \'\', user_url VARCHAR(100) DEFAULT \'\', user_registered DATETIME DEFAULT \'2020-01-01 00:00:00\', user_activation_key VARCHAR(255) DEFAULT \'\', user_status INT DEFAULT 0, display_name VARCHAR(250) DEFAULT \'\', PRIMARY KEY (ID))' ); // phpcs:ignore WordPress.DB
		$wpdb->query( 'CREATE TABLE IF NOT EXISTS `rt_usermeta` (umeta_id BIGINT NOT NULL AUTO_INCREMENT, user_id BIGINT NOT NULL DEFAULT 0, meta_key VARCHAR(255) DEFAULT NULL, meta_value LONGTEXT, PRIMARY KEY (umeta_id))' ); // phpcs:ignore WordPress.DB
		$wpdb->query( 'CREATE TABLE IF NOT EXISTS `rt_postmeta` (meta_id BIGINT NOT NULL AUTO_INCREMENT, post_id BIGINT NOT NULL DEFAULT 0, meta_key VARCHAR(255) DEFAULT NULL, meta_value LONGTEXT, PRIMARY KEY (meta_id))' ); // phpcs:ignore WordPress.DB
		$wpdb->query( 'CREATE TABLE IF NOT EXISTS `rt_wc_orders` (id BIGINT NOT NULL AUTO_INCREMENT, customer_id BIGINT NOT NULL DEFAULT 0, date_created_gmt DATETIME DEFAULT NULL, PRIMARY KEY (id))' ); // phpcs:ignore WordPress.DB
		$wpdb->query( 'CREATE TABLE IF NOT EXISTS `rt_wc_product_meta_lookup` (product_id BIGINT NOT NULL, stock_quantity DOUBLE DEFAULT NULL, stock_status VARCHAR(100) DEFAULT \'instock\', PRIMARY KEY (product_id))' ); // phpcs:ignore WordPress.DB

		$opts = array_merge(
			array(
				'woocommerce_custom_orders_table_enabled'           => 'yes',
				'woocommerce_custom_orders_table_data_sync_enabled' => 'no',
				'woocommerce_db_version'                            => '9.4.0',
			),
			$options
		);
		foreach ( $opts as $name => $value ) {
			$wpdb->query( $wpdb->prepare( 'INSERT INTO `rt_options` (option_name, option_value) VALUES (%s, %s)', $name, $value ) ); // phpcs:ignore WordPress.DB
		}

		// Admin (old), a since-backup customer (50) referenced by a live order,
		// and a guest order (customer 0, not a user).
		$wpdb->query( "INSERT INTO `rt_users` (ID, user_login, user_email, user_registered, display_name) VALUES (1, 'admin', 'a@x.test', '2020-01-01 00:00:00', 'Admin'), (50, 'buyer', 'b@x.test', '2025-06-01 00:00:00', 'Buyer Fifty')" ); // phpcs:ignore WordPress.DB
		$wpdb->query( "INSERT INTO `rt_usermeta` (user_id, meta_key, meta_value) VALUES (50, 'first_name', 'Buyer'), (50, 'billing_city', 'Townsville')" ); // phpcs:ignore WordPress.DB
		$wpdb->query( "INSERT INTO `rt_wc_orders` (id, customer_id, date_created_gmt) VALUES (1001, 50, '2025-06-02 12:00:00'), (1002, 0, '2025-06-03 12:00:00')" ); // phpcs:ignore WordPress.DB
		$wpdb->query( "INSERT INTO `rt_postmeta` (post_id, meta_key, meta_value) VALUES (100, '_manage_stock', 'yes'), (100, '_stock', '8'), (100, '_stock_status', 'instock')" ); // phpcs:ignore WordPress.DB
		$wpdb->query( "INSERT INTO `rt_wc_product_meta_lookup` (product_id, stock_quantity, stock_status) VALUES (100, 8, 'instock')" ); // phpcs:ignore WordPress.DB
	}

	/**
	 * Write a per-table dump file into the scratch dir and return its path.
	 *
	 * @param string $name Table name (source-prefixed).
	 * @param string $body Statement body after the CREATE.
	 *
	 * @return string Dump file path.
	 */
	protected function write_woo_dump( $name, $body = '' ) {
		$path = $this->dir . '/dbBackup-' . $name . '.sql.php';
		$sql  = '<?php exit(); ?>' . "\n" . 'DROP TABLE IF EXISTS `' . $name . '`;' . "\n" . 'CREATE TABLE `' . $name . '` (id INT);' . "\n" . $body;
		file_put_contents( $path, $sql ); // phpcs:ignore WordPress.WP.AlternativeFunctions -- test fixture.
		return $path;
	}

	/**
	 * The standard supported-config dump file set: an options dump carrying the
	 * given WooCommerce version and an HPOS wc_orders dump.
	 *
	 * @param string $db_version Backup's woocommerce_db_version.
	 *
	 * @return string[] Dump file paths.
	 */
	protected function woo_dump_files( $db_version = '9.4.0' ) {
		return array(
			$this->write_woo_dump( 'src_options', "INSERT INTO `src_options` (option_id, option_name, option_value, autoload) VALUES (1,'siteurl','http://x.test','yes'),(2,'woocommerce_db_version','" . $db_version . "','yes');\n" ),
			$this->write_woo_dump( 'src_wc_orders', "INSERT INTO `src_wc_orders` (id) VALUES (1001);\n" ),
		);
	}

	/**
	 * Build an HPOS db.zip artifact: the banner-carrying items dump plus an
	 * options dump (with the WooCommerce version) and a wc_orders dump, so the
	 * preserve gate sees a supported backup.
	 *
	 * @param bool   $fk         Whether the banner declares foreign keys.
	 * @param string $db_version Backup's woocommerce_db_version.
	 *
	 * @return array Artifact entry.
	 */
	protected function make_hpos_db_artifact( $fk = false, $db_version = '9.4.0', $prefix = 'src_' ) {
		$banner = '-- mainwp-backup-info: {"site_url":"' . site_url() . '","home_url":"' . home_url() . '","table_prefix":"' . $prefix . '"}' . "\n"
			. '-- mainwp-backup-meta: {"has_foreign_keys":' . ( $fk ? 'true' : 'false' ) . ',"tier":1}';
		$items   = '<?php exit(); ?>' . "\n" . $banner . "\n\nDROP TABLE IF EXISTS `" . $prefix . "items`;\nCREATE TABLE `" . $prefix . "items` (id INT);\nINSERT INTO `" . $prefix . "items` (`id`) VALUES (1),(2);\n";
		$options = '<?php exit(); ?>' . "\nDROP TABLE IF EXISTS `" . $prefix . "options`;\nCREATE TABLE `" . $prefix . "options` (option_id INT);\nINSERT INTO `" . $prefix . "options` (option_id, option_name, option_value, autoload) VALUES (1,'woocommerce_db_version','" . $db_version . "','yes');\n";
		$orders  = '<?php exit(); ?>' . "\nDROP TABLE IF EXISTS `" . $prefix . "wc_orders`;\nCREATE TABLE `" . $prefix . "wc_orders` (id INT);\nINSERT INTO `" . $prefix . "wc_orders` (id) VALUES (1001);\n";

		$zip_path = $this->dir . '/db.zip';
		$zip      = new ZipArchive();
		$zip->open( $zip_path, ZipArchive::CREATE );
		$zip->addFromString( 'dbBackup-' . $prefix . 'items.sql.php', $items );
		$zip->addFromString( 'dbBackup-' . $prefix . 'options.sql.php', $options );
		$zip->addFromString( 'dbBackup-' . $prefix . 'wc_orders.sql.php', $orders );
		$zip->close();

		return array(
			'role'       => 'db',
			'file'       => 'db.zip',
			'size'       => (int) filesize( $zip_path ),
			'sha256'     => hash_file( 'sha256', $zip_path ),
			'adopt_only' => false,
		);
	}

	/**
	 * The gate passes for an HPOS-authoritative, sync-off store whose
	 * WooCommerce version matches the backup.
	 */
	public function test_woo_gate_passes_for_supported_config() {
		$this->seed_woo_tables();
		$this->assertNull( $this->runner->expose_woo_preserve_gate( $this->woo_dump_files( '9.4.0' ), 'src_' ) );
	}

	/**
	 * A backup with no wc_orders dump is legacy storage and aborts.
	 */
	public function test_woo_gate_aborts_for_legacy_backup() {
		$this->seed_woo_tables();
		$only_options = array( $this->write_woo_dump( 'src_options', "INSERT INTO `src_options` (option_id, option_name, option_value) VALUES (2,'woocommerce_db_version','9.4.0');\n" ) );
		$this->assertSame( MainWP_Backup_Error::ERR_RESTORE_WOO_LEGACY_ORDERS, $this->runner->expose_woo_preserve_gate( $only_options, 'src_' ) );
	}

	/**
	 * A live store that does not use HPOS as the authoritative store aborts.
	 */
	public function test_woo_gate_aborts_when_live_not_hpos() {
		$this->seed_woo_tables( array( 'woocommerce_custom_orders_table_enabled' => 'no' ) );
		$this->assertSame( MainWP_Backup_Error::ERR_RESTORE_WOO_LEGACY_ORDERS, $this->runner->expose_woo_preserve_gate( $this->woo_dump_files(), 'src_' ) );
	}

	/**
	 * A live store still synchronizing orders to the posts table aborts.
	 */
	public function test_woo_gate_aborts_in_sync_mode() {
		$this->seed_woo_tables( array( 'woocommerce_custom_orders_table_data_sync_enabled' => 'yes' ) );
		$this->assertSame( MainWP_Backup_Error::ERR_RESTORE_WOO_SYNC_MODE, $this->runner->expose_woo_preserve_gate( $this->woo_dump_files(), 'src_' ) );
	}

	/**
	 * A WooCommerce version change between the backup and now aborts.
	 */
	public function test_woo_gate_aborts_on_version_skew() {
		$this->seed_woo_tables( array( 'woocommerce_db_version' => '9.4.0' ) );
		$this->assertSame( MainWP_Backup_Error::ERR_RESTORE_WOO_VERSION_SKEW, $this->runner->expose_woo_preserve_gate( $this->woo_dump_files( '9.3.0' ), 'src_' ) );
	}

	/**
	 * Capture records the customer and stock a kept order references; after a
	 * simulated revert, merge re-inserts the absent customer (original ID) and
	 * re-applies the live stock.
	 */
	public function test_woo_capture_then_merge_restores_customer_and_stock() {
		global $wpdb;
		$this->seed_woo_tables();
		$scratch = $this->dir . '/scratch/';
		mkdir( $scratch, 0777, true );
		$sub_state = array( 'params' => array( 'preserve_orders' => true, 'woo_order_since' => 0 ) );

		$summary = $this->runner->expose_capture_woo_state( $sub_state, $scratch );
		$this->assertSame( 1, $summary['customers'] );
		$this->assertSame( 1, $summary['stock'] );
		$sub_state['woo_capture'] = array( 'file' => $summary['file'] );

		// Simulate the restore reverting wp_users (drop the since-backup buyer)
		// and product stock (back to the backup's higher count).
		$wpdb->query( 'DELETE FROM `rt_users` WHERE ID = 50' ); // phpcs:ignore WordPress.DB
		$wpdb->query( 'DELETE FROM `rt_usermeta` WHERE user_id = 50' ); // phpcs:ignore WordPress.DB
		$wpdb->query( "UPDATE `rt_postmeta` SET meta_value = '20' WHERE post_id = 100 AND meta_key = '_stock'" ); // phpcs:ignore WordPress.DB

		$merge = $this->runner->expose_merge_woo_state( $sub_state, $scratch );
		$this->assertSame( 1, $merge['customers_restored'] );
		$this->assertSame( 1, $merge['stock_applied'] );
		$this->assertSame( 0, $merge['products_missing'] );

		$this->assertSame( '50', $wpdb->get_var( 'SELECT ID FROM `rt_users` WHERE ID = 50' ) ); // phpcs:ignore WordPress.DB
		$this->assertSame( 2, (int) $wpdb->get_var( 'SELECT COUNT(*) FROM `rt_usermeta` WHERE user_id = 50' ) ); // phpcs:ignore WordPress.DB
		$this->assertSame( '8', $wpdb->get_var( "SELECT meta_value FROM `rt_postmeta` WHERE post_id = 100 AND meta_key = '_stock'" ) ); // phpcs:ignore WordPress.DB
	}

	/**
	 * Merge never duplicates a customer the backup already restored.
	 */
	public function test_woo_merge_skips_present_customer() {
		$this->seed_woo_tables();
		$scratch = $this->dir . '/scratch/';
		mkdir( $scratch, 0777, true );
		$sub_state = array( 'params' => array( 'preserve_orders' => true, 'woo_order_since' => 0 ) );
		$summary   = $this->runner->expose_capture_woo_state( $sub_state, $scratch );
		$sub_state['woo_capture'] = array( 'file' => $summary['file'] );

		// User 50 is still present (no revert) at merge time.
		$merge = $this->runner->expose_merge_woo_state( $sub_state, $scratch );
		$this->assertSame( 0, $merge['customers_restored'] );
		$this->assertSame( 1, $merge['customers_present'] );

		global $wpdb;
		$this->assertSame( 1, (int) $wpdb->get_var( 'SELECT COUNT(*) FROM `rt_users` WHERE ID = 50' ) ); // phpcs:ignore WordPress.DB
	}

	/**
	 * Stock for a product the restore deleted is skipped and counted, never
	 * re-inserted as an orphan row.
	 */
	public function test_woo_merge_skips_deleted_product_stock() {
		global $wpdb;
		$this->seed_woo_tables();
		$scratch = $this->dir . '/scratch/';
		mkdir( $scratch, 0777, true );
		$sub_state = array( 'params' => array( 'preserve_orders' => true, 'woo_order_since' => 0 ) );
		$summary   = $this->runner->expose_capture_woo_state( $sub_state, $scratch );
		$sub_state['woo_capture'] = array( 'file' => $summary['file'] );

		// The restore removed product 100 entirely.
		$wpdb->query( 'DELETE FROM `rt_postmeta` WHERE post_id = 100' ); // phpcs:ignore WordPress.DB

		$merge = $this->runner->expose_merge_woo_state( $sub_state, $scratch );
		$this->assertSame( 0, $merge['stock_applied'] );
		$this->assertSame( 1, $merge['products_missing'] );
	}

	/**
	 * When the restore reverted a product to an unmanaged state, its _stock meta
	 * row is gone; the merge inserts it back rather than silently applying
	 * nothing through a zero-row UPDATE.
	 */
	public function test_woo_merge_inserts_absent_stock_meta() {
		global $wpdb;
		$this->seed_woo_tables();
		$scratch = $this->dir . '/scratch/';
		mkdir( $scratch, 0777, true );
		$sub_state = array( 'params' => array( 'preserve_orders' => true, 'woo_order_since' => 0 ) );
		$summary   = $this->runner->expose_capture_woo_state( $sub_state, $scratch );
		$sub_state['woo_capture'] = array( 'file' => $summary['file'] );

		// The product still exists (it keeps other postmeta), but its _stock row
		// was reverted away.
		$wpdb->query( "DELETE FROM `rt_postmeta` WHERE post_id = 100 AND meta_key = '_stock'" ); // phpcs:ignore WordPress.DB

		$merge = $this->runner->expose_merge_woo_state( $sub_state, $scratch );
		$this->assertSame( 1, $merge['stock_applied'] );
		$this->assertSame( '8', $wpdb->get_var( "SELECT meta_value FROM `rt_postmeta` WHERE post_id = 100 AND meta_key = '_stock'" ) ); // phpcs:ignore WordPress.DB
	}

	/**
	 * The capture date bound excludes a customer who registered before the
	 * backup (already in the backup, so the revert keeps them).
	 */
	public function test_woo_capture_excludes_pre_backup_customer() {
		global $wpdb;
		$this->seed_woo_tables();
		// A customer who ordered but registered well before the backup.
		$wpdb->query( "INSERT INTO `rt_users` (ID, user_login, user_email, user_registered, display_name) VALUES (52, 'old', 'o@x.test', '2019-01-01 00:00:00', 'Old Buyer')" ); // phpcs:ignore WordPress.DB
		$wpdb->query( "INSERT INTO `rt_wc_orders` (id, customer_id, date_created_gmt) VALUES (1003, 52, '2025-06-04 12:00:00')" ); // phpcs:ignore WordPress.DB

		$scratch = $this->dir . '/scratch/';
		mkdir( $scratch, 0777, true );
		// Bound to since-2025; user 50 (2025) is captured, user 52 (2019) is not.
		$sub_state = array( 'params' => array( 'preserve_orders' => true, 'woo_order_since' => strtotime( '2025-05-01 00:00:00 UTC' ) ) );
		$summary   = $this->runner->expose_capture_woo_state( $sub_state, $scratch );
		$this->assertSame( 1, $summary['customers'] );

		$payload = json_decode( (string) file_get_contents( $scratch . 'woo-preserve.json' ), true );
		$ids     = array_map( 'intval', wp_list_pluck( $payload['users'], 'ID' ) );
		$this->assertContains( 50, $ids );
		$this->assertNotContains( 52, $ids );
	}

	/**
	 * The orphan scan counts kept orders whose customer is missing, and reports
	 * zero once the customer is present.
	 */
	public function test_woo_orphan_scan_counts_missing_customer() {
		global $wpdb;
		$this->seed_woo_tables();
		$wpdb->query( 'DELETE FROM `rt_users` WHERE ID = 50' ); // phpcs:ignore WordPress.DB
		$this->assertSame( 1, $this->runner->expose_scan_woo_orphans()['orphan_customer_orders'] );

		$wpdb->query( "INSERT INTO `rt_users` (ID, user_login, user_email) VALUES (50, 'buyer', 'b@x.test')" ); // phpcs:ignore WordPress.DB
		$this->assertSame( 0, $this->runner->expose_scan_woo_orphans()['orphan_customer_orders'] );
	}

	/**
	 * A preserve restore on the swap path runs the gate, records the preserve
	 * outcome, and skips the order tables - WITHOUT entering maintenance mode,
	 * since the dashboard-polled restore cannot reach a child held offline by a
	 * WordPress maintenance 503.
	 */
	public function test_preserve_pipeline_swap_path() {
		$this->seed_woo_tables();
		$task_id = $this->make_task( 'db', array( $this->make_hpos_db_artifact() ), array( 'preserve_orders' => true, 'woo_order_since' => 0 ) );
		$task    = $this->drive( $task_id );

		$this->assertSame( MainWP_Child_Backup_Task::PHASE_COMPLETED, $task->phase );
		$calls = $this->runner->fake_engine->calls;
		$this->assertNotContains( 'begin_maintenance', $calls );
		$this->assertContains( 'wc_orders', $this->runner->fake_engine->last_replay_config['skip_stems'] );

		$sub_state = $this->spine->read_sub_state( $task, $this->dir );
		$this->assertTrue( ! empty( $sub_state['woo_capture']['done'] ) );
		$this->assertTrue( ! empty( $sub_state['woo_merge']['done'] ) );
		$this->assertArrayHasKey( 'woo_preserve', $sub_state['progress'] );
	}

	/**
	 * A preserve restore drops users and usermeta from the post-restore
	 * row-count check: the merge re-inserts the kept customers into those tables
	 * after the swap, so they legitimately exceed the backup counts and a strict
	 * check would fail a correct restore.
	 */
	public function test_preserve_excludes_merged_tables_from_verify() {
		$this->seed_woo_tables();
		$this->runner->fake_engine->tables = array(
			'items'    => array( 'status' => 'ok', 'rows' => 2 ),
			'users'    => array( 'status' => 'ok', 'rows' => 1 ),
			'usermeta' => array( 'status' => 'ok', 'rows' => 18 ),
		);
		$task_id = $this->make_task( 'db', array( $this->make_hpos_db_artifact() ), array( 'preserve_orders' => true, 'woo_order_since' => 0 ) );
		$task    = $this->drive( $task_id );

		$this->assertSame( MainWP_Child_Backup_Task::PHASE_COMPLETED, $task->phase );
		$expected = $this->runner->fake_engine->last_verify_expected;
		$this->assertArrayHasKey( 'items', $expected );
		$this->assertArrayNotHasKey( 'users', $expected );
		$this->assertArrayNotHasKey( 'usermeta', $expected );
	}

	/**
	 * WooCommerce's Action Scheduler writes to its log and action tables
	 * continuously through the async runner, which the restore's own loopback
	 * can wake mid-restore. Those transient bookkeeping rows are not restored
	 * content, so a strict count check would fail a correct restore. They are
	 * dropped from the check on every path, not only the preserve path.
	 */
	public function test_verify_excludes_action_scheduler_tables() {
		global $wpdb;
		$wpdb->query( 'CREATE TABLE IF NOT EXISTS `rt_actionscheduler_logs` (log_id BIGINT NOT NULL AUTO_INCREMENT, action_id BIGINT NOT NULL DEFAULT 0, message LONGTEXT, PRIMARY KEY (log_id))' ); // phpcs:ignore WordPress.DB
		$wpdb->query( 'CREATE TABLE IF NOT EXISTS `rt_actionscheduler_actions` (action_id BIGINT NOT NULL AUTO_INCREMENT, hook VARCHAR(191) DEFAULT \'\', status VARCHAR(20) DEFAULT \'\', PRIMARY KEY (action_id))' ); // phpcs:ignore WordPress.DB

		$this->runner->fake_engine->tables = array(
			'items'                   => array( 'status' => 'ok', 'rows' => 2 ),
			'actionscheduler_logs'    => array( 'status' => 'ok', 'rows' => 17 ),
			'actionscheduler_actions' => array( 'status' => 'ok', 'rows' => 5 ),
		);
		$task_id = $this->make_task( 'db', array( $this->make_db_artifact() ) );
		$task    = $this->drive( $task_id );

		$this->assertSame( MainWP_Child_Backup_Task::PHASE_COMPLETED, $task->phase );
		$expected = $this->runner->fake_engine->last_verify_expected;
		$this->assertArrayHasKey( 'items', $expected );
		$this->assertArrayNotHasKey( 'actionscheduler_logs', $expected );
		$this->assertArrayNotHasKey( 'actionscheduler_actions', $expected );
	}

	/**
	 * A preserve restore on the in-place foreign-key path still reaches the
	 * merge phase, which the FK route would otherwise skip.
	 */
	public function test_preserve_pipeline_fk_path_reaches_merge() {
		$this->seed_woo_tables();
		$task_id = $this->make_task( 'db', array( $this->make_hpos_db_artifact( true, '9.4.0', 'rt_' ) ), array( 'preserve_orders' => true, 'woo_order_since' => 0, 'has_foreign_keys' => true, 'source_prefix' => 'rt_' ) );
		$task    = $this->drive( $task_id );

		$this->assertSame( MainWP_Child_Backup_Task::PHASE_COMPLETED, $task->phase );
		$sub_state = $this->spine->read_sub_state( $task, $this->dir );
		$this->assertTrue( ! empty( $sub_state['woo_merge']['done'] ) );
		$this->assertArrayHasKey( 'woo_preserve', $sub_state['progress'] );
	}

	/**
	 * An unsupported store aborts the preserve restore before any maintenance or
	 * database change: sync mode leaves the site online and untouched.
	 */
	public function test_preserve_pipeline_aborts_in_sync_mode() {
		$this->seed_woo_tables( array( 'woocommerce_custom_orders_table_data_sync_enabled' => 'yes' ) );
		$task_id = $this->make_task( 'db', array( $this->make_hpos_db_artifact() ), array( 'preserve_orders' => true, 'woo_order_since' => 0 ) );
		$task    = $this->drive( $task_id );

		$this->assertSame( MainWP_Child_Backup_Task::PHASE_FAILED, $task->phase );
		$payload = $this->spine->status_payload( $task, $this->dir );
		$this->assertSame( MainWP_Backup_Error::ERR_RESTORE_WOO_SYNC_MODE, $payload['error_code'] );
		$this->assertNotContains( 'begin_maintenance', $this->runner->fake_engine->calls );
	}

	/**
	 * Without preserve_orders an HPOS restore never enters the WooCommerce
	 * phases and never touches maintenance on the swap path.
	 */
	public function test_no_preserve_skips_woo_phases() {
		$this->seed_woo_tables();
		$task_id = $this->make_task( 'db', array( $this->make_hpos_db_artifact() ) );
		$task    = $this->drive( $task_id );

		$this->assertSame( MainWP_Child_Backup_Task::PHASE_COMPLETED, $task->phase );
		$sub_state = $this->spine->read_sub_state( $task, $this->dir );
		$this->assertArrayNotHasKey( 'woo_capture', $sub_state );
		$this->assertArrayNotHasKey( 'woo_merge', $sub_state );
		$this->assertNotContains( 'begin_maintenance', $this->runner->fake_engine->calls );
	}

	/**
	 * A db-only restore runs the whole pipeline to completed: adoption,
	 * verification, dump extraction, replay, swap, rewrite, verify.
	 */
	public function test_db_restore_pipeline_completes() {
		$task_id = $this->make_task( 'db', array( $this->make_db_artifact() ) );
		$task    = $this->drive( $task_id );

		$this->assertSame( MainWP_Child_Backup_Task::PHASE_COMPLETED, $task->phase );

		$sub_state = $this->spine->read_sub_state( $task, $this->dir );
		$this->assertTrue( $sub_state['verification']['row_counts_ok'] );
		$this->assertTrue( $sub_state['verification']['sentinel_ok'] );

		$calls = $this->runner->fake_engine->calls;
		$this->assertContains( 'swap_tables:items', $calls );
		$this->assertContains( 'verify_row_counts', $calls );
		$this->assertContains( 'drop_old_tables', $calls );

		// Scratch staging is gone after a verified restore.
		$this->assertDirectoryDoesNotExist( $this->dir . '/restore-' . $task_id );
	}

	/**
	 * A full restore extracts file parts over the site root and applies
	 * tombstones before the database phases.
	 */
	public function test_full_restore_applies_files_and_tombstones() {
		file_put_contents( $this->site . '/stale.txt', 'old' ); // phpcs:ignore -- fixture.

		$task_id = $this->make_task(
			'full',
			array( $this->make_part_artifact(), $this->make_tombstones_artifact(), $this->make_db_artifact() )
		);
		$task    = $this->drive( $task_id );

		$this->assertSame( MainWP_Child_Backup_Task::PHASE_COMPLETED, $task->phase );
		$this->assertSame( 'restored-content', file_get_contents( $this->site . '/wp-content/uploads/restored.txt' ) );
		$this->assertFileDoesNotExist( $this->site . '/stale.txt' );
	}

	/**
	 * A replay failure lands terminal failed with tmp tables cleaned,
	 * maintenance lifted, scratch removed - and old tables NOT dropped.
	 */
	public function test_replay_failure_cleans_up_but_keeps_old_tables() {
		$this->runner->fake_engine->replay_error = 'Replay failed on table items: forced';

		$task_id = $this->make_task( 'db', array( $this->make_db_artifact() ) );
		$task    = $this->drive( $task_id );

		$this->assertSame( MainWP_Child_Backup_Task::PHASE_FAILED, $task->phase );

		$payload = $this->spine->status_payload( $task );
		$this->assertSame( MainWP_Backup_Error::ERR_RESTORE_DB_REPLAY, $payload['error_code'] );

		$calls = $this->runner->fake_engine->calls;
		$this->assertContains( 'cleanup_tmp_tables', $calls );
		$this->assertContains( 'exit_maintenance', $calls );
		$this->assertNotContains( 'drop_old_tables', $calls );
		$this->assertDirectoryDoesNotExist( $this->dir . '/restore-' . $task_id );
	}

	/**
	 * A database-bearing restore whose target user lacks the table-swap
	 * grants fails at preflight, before any replay or swap runs.
	 */
	public function test_preflight_fails_when_db_grants_missing() {
		$this->runner->fake_engine->grants_ok = false;

		$task_id = $this->make_task( 'db', array( $this->make_db_artifact() ) );
		$task    = $this->drive( $task_id );

		$this->assertSame( MainWP_Child_Backup_Task::PHASE_FAILED, $task->phase );

		$payload = $this->spine->status_payload( $task );
		$this->assertSame( MainWP_Backup_Error::ERR_RESTORE_DB_PERMS, $payload['error_code'] );

		$calls = $this->runner->fake_engine->calls;
		$this->assertContains( 'probe_swap_grants', $calls );
		$this->assertNotContains( 'swap_tables:items', $calls );
	}

	/**
	 * A files-only restore never touches the database, so the grant probe
	 * is skipped even when the grants would fail.
	 */
	public function test_preflight_skips_grant_probe_for_files_only_restore() {
		$this->runner->fake_engine->grants_ok = false;

		$task_id = $this->make_task( 'files', array( $this->make_db_artifact() ) );
		$this->drive( $task_id );

		$this->assertNotContains( 'probe_swap_grants', $this->runner->fake_engine->calls );
	}

	/**
	 * Preflight refuses a restore whose scratch target cannot be made
	 * writable, surfacing the not-writable code before any change.
	 */
	public function test_preflight_rejects_unwritable_scratch_target() {
		$blocker = $this->dir . '/blocker-file';
		file_put_contents( $blocker, 'x' ); // phpcs:ignore WordPress.WP.AlternativeFunctions -- test fixture.

		$task   = (object) array(
			'task_id' => 'pf-write',
			'type'    => 'restore_db',
		);
		$method = new \ReflectionMethod( $this->runner, 'preflight_restore' );
		$method->setAccessible( true );

		try {
			$method->invoke( $this->runner, $task, $blocker . '/under-a-file' );
			$this->fail( 'Preflight must reject an uncreatable scratch target.' );
		} catch ( MainWP_Exception $e ) {
			$this->assertSame( MainWP_Backup_Error::ERR_DIR_NOT_WRITABLE, $e->get_message_error_code() );
		}
	}

	/**
	 * A dump declaring foreign keys takes the in-place route: replay
	 * runs with fk_path, the swap phase never executes, and maintenance
	 * mode is exited on the way out.
	 */
	public function test_fk_dump_takes_in_place_route_and_skips_swap() {
		$task_id = $this->make_task( 'db', array( $this->make_db_artifact( true ) ), array( 'source_prefix' => 'rt_' ) );
		$task    = $this->drive( $task_id );

		$this->assertSame( MainWP_Child_Backup_Task::PHASE_COMPLETED, $task->phase );

		$calls = $this->runner->fake_engine->calls;
		$this->assertContains( 'replay_slice:fk', $calls );
		$this->assertContains( 'exit_maintenance', $calls );
		foreach ( $calls as $call ) {
			$this->assertStringNotContainsString( 'swap_tables', $call );
		}
	}

	/**
	 * The foreign-key path copies the live tables aside before the in-place
	 * replay touches them: the snapshot runs before replay_slice.
	 */
	public function test_fk_path_snapshots_before_replay() {
		$task_id = $this->make_task( 'db', array( $this->make_db_artifact( true ) ), array( 'source_prefix' => 'rt_' ) );
		$task    = $this->drive( $task_id );

		$this->assertSame( MainWP_Child_Backup_Task::PHASE_COMPLETED, $task->phase );

		$calls         = $this->runner->fake_engine->calls;
		$snapshot_at   = array_search( 'snapshot_tables_slice', $calls, true );
		$replay_at     = array_search( 'replay_slice:fk', $calls, true );
		$this->assertNotFalse( $snapshot_at, 'The snapshot must run on the foreign-key path.' );
		$this->assertNotFalse( $replay_at );
		$this->assertLessThan( $replay_at, $snapshot_at, 'The aside snapshot must precede the in-place replay.' );
	}

	/**
	 * A foreign-key replay failure rolls the live tables back to their
	 * pre-restore state and reports the rolled-back code - not a bare
	 * replay failure that would leave the database half written.
	 */
	public function test_fk_replay_failure_rolls_back_and_reports_rolled_back() {
		$this->runner->fake_engine->replay_error = 'Replay failed on table items: forced';

		$task_id = $this->make_task( 'db', array( $this->make_db_artifact( true ) ), array( 'source_prefix' => 'rt_' ) );
		$task    = $this->drive( $task_id );

		$this->assertSame( MainWP_Child_Backup_Task::PHASE_FAILED, $task->phase );

		$payload = $this->spine->status_payload( $task );
		$this->assertSame( MainWP_Backup_Error::ERR_RESTORE_ROLLED_BACK, $payload['error_code'] );

		$calls = $this->runner->fake_engine->calls;
		$this->assertContains( 'rollback_aside_slice', $calls );
		$this->assertContains( 'exit_maintenance', $calls );
		$this->assertDirectoryDoesNotExist( $this->dir . '/restore-' . $task_id );
	}

	/**
	 * When the rollback itself cannot finish, the task reports the
	 * rollback-failed code and maintenance mode is deliberately left on so
	 * the possibly-inconsistent site is not served.
	 */
	public function test_fk_rollback_failure_reports_rollback_failed_and_keeps_maintenance() {
		$this->runner->fake_engine->replay_error   = 'Replay failed on table items: forced';
		$this->runner->fake_engine->rollback_error = 'disk full during rollback';

		$task_id = $this->make_task( 'db', array( $this->make_db_artifact( true ) ), array( 'source_prefix' => 'rt_' ) );
		$task    = $this->drive( $task_id );

		$this->assertSame( MainWP_Child_Backup_Task::PHASE_FAILED, $task->phase );

		$payload = $this->spine->status_payload( $task );
		$this->assertSame( MainWP_Backup_Error::ERR_RESTORE_ROLLBACK_FAILED, $payload['error_code'] );

		$calls = $this->runner->fake_engine->calls;
		$this->assertContains( 'rollback_aside_slice', $calls );
		// Maintenance is held: no exit_maintenance after the failed rollback.
		$this->assertNotContains( 'exit_maintenance', $calls );
	}

	/**
	 * A snapshot failure stops the restore before any live table changes:
	 * the replay never runs and the snapshot-failed code is reported.
	 */
	public function test_fk_snapshot_failure_fails_before_any_replay() {
		$this->runner->fake_engine->snapshot_error = 'could not write aside dump';

		$task_id = $this->make_task( 'db', array( $this->make_db_artifact( true ) ), array( 'source_prefix' => 'rt_' ) );
		$task    = $this->drive( $task_id );

		$this->assertSame( MainWP_Child_Backup_Task::PHASE_FAILED, $task->phase );

		$payload = $this->spine->status_payload( $task );
		$this->assertSame( MainWP_Backup_Error::ERR_RESTORE_SNAPSHOT_FAILED, $payload['error_code'] );

		$this->assertNotContains( 'replay_slice:fk', $this->runner->fake_engine->calls );
	}

	/**
	 * The foreign-key snapshot needs room for the aside copy. When free disk
	 * space is below the estimate the restore fails before the site goes
	 * offline - the live database is untouched and no replay runs.
	 */
	public function test_fk_snapshot_disk_preflight_fails_before_any_change() {
		$this->runner->fake_snapshot_bytes = 50 * 1024 * 1024; // The aside needs 50MB.
		$this->runner->fake_free_space     = 1.0;              // One byte free: it cannot fit.

		$task_id = $this->make_task( 'db', array( $this->make_db_artifact( true ) ), array( 'source_prefix' => 'rt_' ) );
		$task    = $this->drive( $task_id );

		$this->assertSame( MainWP_Child_Backup_Task::PHASE_FAILED, $task->phase );

		$payload = $this->spine->status_payload( $task );
		$this->assertSame( MainWP_Backup_Error::ERR_RESTORE_SNAPSHOT_FAILED, $payload['error_code'] );

		$calls = $this->runner->fake_engine->calls;
		$this->assertNotContains( 'snapshot_tables_slice', $calls, 'No aside copy may be written when the disk preflight fails.' );
		$this->assertNotContains( 'replay_slice:fk', $calls, 'The in-place replay must not run when the disk preflight fails.' );
	}

	/**
	 * Ample free disk space lets the foreign-key restore proceed: the preflight
	 * does not false-positive on a host that clearly has room.
	 */
	public function test_fk_snapshot_disk_preflight_allows_ample_space() {
		$this->runner->fake_free_space = 1.0e12; // A terabyte free.

		$task_id = $this->make_task( 'db', array( $this->make_db_artifact( true ) ), array( 'source_prefix' => 'rt_' ) );
		$task    = $this->drive( $task_id );

		$this->assertSame( MainWP_Child_Backup_Task::PHASE_COMPLETED, $task->phase );
		$this->assertContains( 'replay_slice:fk', $this->runner->fake_engine->calls );
	}

	/**
	 * The foreign-key path snapshots, replays, and may replay again to roll
	 * back, so it widens the wall-clock budget once the path is committed. A
	 * non-foreign-key restore keeps the budget it was created with.
	 */
	public function test_fk_detection_extends_wall_clock_budget() {
		$fk_id      = $this->make_task( 'db', array( $this->make_db_artifact( true ) ), array( 'source_prefix' => 'rt_' ) );
		$fk_created = $this->spine->get_task( $fk_id )->max_wall_clock_at;
		$fk_task    = $this->drive( $fk_id );

		$this->assertSame( MainWP_Child_Backup_Task::PHASE_COMPLETED, $fk_task->phase );
		$this->assertGreaterThan(
			strtotime( $fk_created ),
			strtotime( $this->spine->get_task( $fk_id )->max_wall_clock_at ),
			'The foreign-key path must widen the wall-clock budget.'
		);

		$nf_id      = $this->make_task( 'db', array( $this->make_db_artifact( false ) ) );
		$nf_created = $this->spine->get_task( $nf_id )->max_wall_clock_at;
		$nf_task    = $this->drive( $nf_id );

		$this->assertSame( MainWP_Child_Backup_Task::PHASE_COMPLETED, $nf_task->phase );
		$this->assertSame(
			$nf_created,
			$this->spine->get_task( $nf_id )->max_wall_clock_at,
			'A non-foreign-key restore must keep its original budget.'
		);
	}

	/**
	 * A foreign-key replay that throws (not just returns an error) still routes
	 * to the rollback, so a half-applied database is recovered.
	 */
	public function test_fk_thrown_replay_failure_rolls_back() {
		$this->runner->fake_engine->replay_throw = true;

		$task_id = $this->make_task( 'db', array( $this->make_db_artifact( true ) ), array( 'source_prefix' => 'rt_' ) );
		$task    = $this->drive( $task_id );

		$this->assertSame( MainWP_Child_Backup_Task::PHASE_FAILED, $task->phase );
		$this->assertSame( MainWP_Backup_Error::ERR_RESTORE_ROLLED_BACK, $this->spine->status_payload( $task )['error_code'] );
		$this->assertContains( 'rollback_aside_slice', $this->runner->fake_engine->calls );
	}

	/**
	 * A non-foreign-key replay that throws fails terminally as before - there
	 * is nothing to roll back because the swap path never touched live tables.
	 */
	public function test_non_fk_thrown_replay_failure_fails_without_rollback() {
		$this->runner->fake_engine->replay_throw = true;

		$task_id = $this->make_task( 'db', array( $this->make_db_artifact( false ) ) );
		$task    = $this->drive( $task_id );

		$this->assertSame( MainWP_Child_Backup_Task::PHASE_FAILED, $task->phase );
		$this->assertNotContains( 'rollback_aside_slice', $this->runner->fake_engine->calls );
	}

	/**
	 * A failure after the replay has already applied (here, exiting maintenance
	 * throws) must not roll back: the restore succeeded, so undoing it would
	 * lose the restored data. It fails terminally instead.
	 */
	public function test_fk_post_replay_failure_does_not_roll_back() {
		$this->runner->fake_engine->exit_maintenance_throw = true;

		$task_id = $this->make_task( 'db', array( $this->make_db_artifact( true ) ), array( 'source_prefix' => 'rt_' ) );
		$task    = $this->drive( $task_id );

		$this->assertSame( MainWP_Child_Backup_Task::PHASE_FAILED, $task->phase );
		$this->assertContains( 'replay_slice:fk', $this->runner->fake_engine->calls );
		$this->assertNotContains( 'rollback_aside_slice', $this->runner->fake_engine->calls );
	}

	/**
	 * A foreign-key restore that runs past its wall-clock deadline mid-replay is
	 * rerouted to roll the half-applied database back rather than failing
	 * outright, and the deadline is pushed out so the rollback has time. The
	 * rollback then runs even though no enter_fk_rollback set it up.
	 */
	public function test_wall_clock_reroutes_fk_replay_to_rollback() {
		$this->runner->fake_engine->replay_yield_once = true;

		$task_id = $this->make_task( 'db', array( $this->make_db_artifact( true ) ), array( 'source_prefix' => 'rt_' ) );

		// Step until the first replay slice has yielded: snapshot done, paused
		// in the replaying_db phase.
		$task  = $this->spine->get_task( $task_id );
		$guard = 0;
		while ( MainWP_Child_Backup_Task::PHASE_REPLAYING_DB !== $task->phase && $guard < 20 ) {
			$task = $this->runner->advance_one_slice( $task, MainWP_Child_Backup_Task_Runner::LEASE_OWNER_DASHBOARD );
			++$guard;
		}
		$this->assertSame( MainWP_Child_Backup_Task::PHASE_REPLAYING_DB, $task->phase );

		$this->force_deadline_past( $task_id );
		$expired = strtotime( $this->spine->get_task( $task_id )->max_wall_clock_at );
		$this->assertTrue( $this->spine->enforce_wall_clock_deadline( $task_id ) );

		$task = $this->spine->get_task( $task_id );
		$this->assertSame( MainWP_Child_Backup_Task::PHASE_FK_ROLLBACK, $task->phase, 'A timed-out fk replay must reroute to rollback.' );
		$this->assertGreaterThan( $expired, strtotime( $task->max_wall_clock_at ), 'The rollback must get a fresh deadline past the one that expired.' );

		$task = $this->drive( $task_id );
		$this->assertSame( MainWP_Child_Backup_Task::PHASE_FAILED, $task->phase );
		$this->assertSame( MainWP_Backup_Error::ERR_RESTORE_ROLLED_BACK, $this->spine->status_payload( $task )['error_code'] );
		$this->assertContains( 'rollback_aside_slice', $this->runner->fake_engine->calls );
	}

	/**
	 * The deadline reroute waits for the lease: while a runner still holds it
	 * (a replay may still be writing the live tables), the task is neither
	 * rerouted nor failed. A later poll, once the lease is free, reroutes.
	 */
	public function test_wall_clock_does_not_reroute_while_lease_held() {
		$this->runner->fake_engine->replay_yield_once = true;

		$task_id = $this->make_task( 'db', array( $this->make_db_artifact( true ) ), array( 'source_prefix' => 'rt_' ) );

		$task  = $this->spine->get_task( $task_id );
		$guard = 0;
		while ( MainWP_Child_Backup_Task::PHASE_REPLAYING_DB !== $task->phase && $guard < 20 ) {
			$task = $this->runner->advance_one_slice( $task, MainWP_Child_Backup_Task_Runner::LEASE_OWNER_DASHBOARD );
			++$guard;
		}
		$this->assertSame( MainWP_Child_Backup_Task::PHASE_REPLAYING_DB, $task->phase );

		// A live runner holds the lease, modelling a replay still in flight.
		$held = $this->spine->acquire_lease( $task_id, 'live-runner', (int) $this->spine->get_task( $task_id )->version, 180 );
		$this->assertTrue( $held );

		$this->force_deadline_past( $task_id );
		$this->assertFalse( $this->spine->enforce_wall_clock_deadline( $task_id ), 'The reroute must wait while the lease is held.' );
		$this->assertSame( MainWP_Child_Backup_Task::PHASE_REPLAYING_DB, $this->spine->get_task( $task_id )->phase, 'A held-lease task is neither rerouted nor failed.' );

		// Once the lease frees, the next poll reroutes to the rollback.
		$this->spine->release_lease( $task_id, 'live-runner', (int) $this->spine->get_task( $task_id )->version );
		$this->assertTrue( $this->spine->enforce_wall_clock_deadline( $task_id ) );
		$this->assertSame( MainWP_Child_Backup_Task::PHASE_FK_ROLLBACK, $this->spine->get_task( $task_id )->phase );
	}

	/**
	 * A rollback that itself runs past the deadline fails terminally with the
	 * rollback-failed code and is not rerouted again, so it cannot loop. The
	 * site stays in maintenance because nothing exits it.
	 */
	public function test_wall_clock_during_rollback_reports_rollback_failed() {
		$task_id = $this->spine->create_task( 'restore_db', array(), 20, 3600 );
		$version = (int) $this->spine->get_task( $task_id )->version;
		$this->spine->checkpoint( $task_id, $version, array( 'phase' => MainWP_Child_Backup_Task::PHASE_FK_ROLLBACK ) );

		$this->force_deadline_past( $task_id );
		$this->assertTrue( $this->spine->enforce_wall_clock_deadline( $task_id ) );

		$task = $this->spine->get_task( $task_id );
		$this->assertSame( MainWP_Child_Backup_Task::PHASE_FAILED, $task->phase );
		$this->assertSame( MainWP_Backup_Error::ERR_RESTORE_ROLLBACK_FAILED, $this->spine->status_payload( $task )['error_code'] );
	}

	/**
	 * A non-foreign-key restore that times out mid-replay still fails outright:
	 * the swap path keeps the old tables aside, so there is nothing to roll back.
	 */
	public function test_wall_clock_non_fk_replay_still_fails() {
		$task_id = $this->spine->create_task( 'restore_db', array(), 20, 3600 );
		$version = (int) $this->spine->get_task( $task_id )->version;
		$this->spine->checkpoint(
			$task_id,
			$version,
			array(
				'phase'     => MainWP_Child_Backup_Task::PHASE_REPLAYING_DB,
				'sub_state' => array( 'db_replay' => array( 'fk_path' => false, 'snapshot_done' => false ) ),
			)
		);

		$this->force_deadline_past( $task_id );
		$this->assertTrue( $this->spine->enforce_wall_clock_deadline( $task_id ) );

		$task = $this->spine->get_task( $task_id );
		$this->assertSame( MainWP_Child_Backup_Task::PHASE_FAILED, $task->phase );
		$this->assertSame( MainWP_Backup_Error::ERR_WALL_CLOCK, $this->spine->status_payload( $task )['error_code'] );
	}

	/**
	 * Force a task's wall-clock deadline into the past.
	 *
	 * @param string $task_id Task id.
	 */
	protected function force_deadline_past( $task_id ) {
		global $wpdb;
		$wpdb->query( $wpdb->prepare( 'UPDATE ' . $this->spine->get_table_name() . ' SET max_wall_clock_at = DATE_SUB(NOW(), INTERVAL 60 SECOND) WHERE task_id = %s', $task_id ) ); // phpcs:ignore WordPress.DB -- test fixture.
	}

	/**
	 * On the foreign-key path a post-replay verification failure rolls the
	 * live tables back from the aside snapshot instead of failing terminally
	 * and discarding it (the in-place replay already overwrote the tables).
	 */
	public function test_fk_verify_failure_rolls_back() {
		$this->runner->fake_engine->verify_ok = false;

		$task_id = $this->make_task( 'db', array( $this->make_db_artifact( true ) ), array( 'source_prefix' => 'rt_' ) );
		$task    = $this->drive( $task_id );

		$this->assertSame( MainWP_Child_Backup_Task::PHASE_FAILED, $task->phase );

		$payload = $this->spine->status_payload( $task );
		$this->assertSame( MainWP_Backup_Error::ERR_RESTORE_ROLLED_BACK, $payload['error_code'] );

		$calls = $this->runner->fake_engine->calls;
		$this->assertContains( 'replay_slice:fk', $calls );
		$this->assertContains( 'verify_row_counts', $calls );
		$this->assertContains( 'rollback_aside_slice', $calls );
	}

	/**
	 * The swap path keeps the old tables aside, so a verification failure
	 * there fails terminally as before, never entering the rollback phase.
	 */
	public function test_swap_path_verify_failure_does_not_roll_back() {
		$this->runner->fake_engine->verify_ok = false;

		$task_id = $this->make_task( 'db', array( $this->make_db_artifact( false ) ) );
		$task    = $this->drive( $task_id );

		$this->assertSame( MainWP_Child_Backup_Task::PHASE_FAILED, $task->phase );

		$payload = $this->spine->status_payload( $task );
		$this->assertSame( MainWP_Backup_Error::ERR_RESTORE_DB_REPLAY, $payload['error_code'] );
		$this->assertNotContains( 'rollback_aside_slice', $this->runner->fake_engine->calls );
	}

	/**
	 * A plain full archive with no separate db artifact replays the
	 * embedded dump the file phase extracted, honoring the dump banner's
	 * prefix, and completes - then sweeps the dump files off the site.
	 */
	public function test_full_archive_restore_replays_embedded_dump() {
		$task_id = $this->make_task(
			'full',
			array( $this->make_full_archive_with_embedded_dump() ),
			array(
				'source_prefix'    => '',
				'has_foreign_keys' => false,
			)
		);
		$task    = $this->drive( $task_id );

		$this->assertSame( MainWP_Child_Backup_Task::PHASE_COMPLETED, $task->phase );
		$this->assertSame( 'restored-content', file_get_contents( $this->site . '/wp-content/uploads/restored.txt' ) );

		$config = $this->runner->fake_engine->last_replay_config;
		$this->assertSame( 'bn_', $config['source_prefix'], 'The embedded dump banner declares the source prefix.' );
		$this->assertSame( array( $this->site . '/wp-content/dbBackup-bn_items.sql.php' ), $config['files'] );

		// The dump files are restore byproducts: replayed, then swept.
		$this->assertFileDoesNotExist( $this->site . '/wp-content/dbBackup-bn_items.sql.php' );
		$this->assertFileDoesNotExist( $this->site . '/wp-content/dbBackup.sql' );
	}

	/**
	 * A bannerless embedded dump (older full backups) defaults its source
	 * prefix to the live prefix - the only sane default for a same-site
	 * restore.
	 */
	public function test_bannerless_embedded_dump_defaults_to_live_prefix() {
		$task_id = $this->make_task(
			'full',
			array( $this->make_full_archive_with_embedded_dump( true ) ),
			array(
				'source_prefix'    => '',
				'has_foreign_keys' => false,
			)
		);
		$task    = $this->drive( $task_id );

		$this->assertSame( MainWP_Child_Backup_Task::PHASE_COMPLETED, $task->phase );
		$this->assertSame( 'rt_', $this->runner->fake_engine->last_replay_config['source_prefix'] );
	}

	/**
	 * A cross-prefix restore of a dump that declares foreign keys is refused
	 * before any write: the prefix rewriter does not rewrite REFERENCES
	 * clauses, so replaying it would point constraints at the wrong table.
	 */
	public function test_cross_prefix_fk_restore_is_refused() {
		$task_id = $this->make_task(
			'db',
			array( $this->make_db_artifact( true ) ),
			array(
				'source_prefix'    => 'other_',
				'has_foreign_keys' => true,
			)
		);
		$task = $this->drive( $task_id );

		$this->assertSame( MainWP_Child_Backup_Task::PHASE_FAILED, $task->phase );
		$this->assertSame( MainWP_Backup_Error::ERR_RESTORE_CROSS_PREFIX_FK, $this->spine->status_payload( $task )['error_code'] );
		// The refusal happens in preparation - no replay is attempted.
		$this->assertNotContains( 'replay_slice:fk', $this->runner->fake_engine->calls );
	}

	/**
	 * A cross-prefix restore WITHOUT foreign keys proceeds - the refusal is
	 * gated on foreign keys, not the prefix change alone.
	 */
	public function test_cross_prefix_without_fk_is_allowed() {
		$task_id = $this->make_task(
			'db',
			array( $this->make_db_artifact( false ) ),
			array(
				'source_prefix'    => 'other_',
				'has_foreign_keys' => false,
			)
		);
		$task = $this->drive( $task_id );

		$payload = $this->spine->status_payload( $task );
		$code    = isset( $payload['error_code'] ) ? $payload['error_code'] : '';
		$this->assertNotSame( MainWP_Backup_Error::ERR_RESTORE_CROSS_PREFIX_FK, $code, 'A cross-prefix non-FK restore must not be refused.' );
	}

	/**
	 * A non-FK restore failed from outside the runner (wall-clock / watchdog)
	 * still gets terminal cleanup: tmp tables dropped and maintenance lifted.
	 */
	public function test_cleanup_terminal_task_cleans_non_fk_restore() {
		$task_id = $this->make_task( 'db', array( $this->make_db_artifact( false ) ) );
		$task    = $this->spine->get_task( $task_id );

		$this->runner->cleanup_terminal_task( $task );

		$this->assertContains( 'cleanup_tmp_tables', $this->runner->fake_engine->calls );
		$this->assertContains( 'exit_maintenance', $this->runner->fake_engine->calls );
	}

	/**
	 * A timed-out FK rollback keeps maintenance on by design - cleanup must
	 * leave it alone so the possibly-inconsistent site is not served.
	 */
	public function test_cleanup_terminal_task_keeps_maintenance_for_fk_rollback() {
		global $wpdb;
		$task_id = $this->make_task( 'db', array( $this->make_db_artifact( true ) ), array( 'source_prefix' => 'rt_' ) );
		$wpdb->query( $wpdb->prepare( 'UPDATE ' . $this->spine->get_table_name() . ' SET phase = %s WHERE task_id = %s', MainWP_Child_Backup_Task::PHASE_FK_ROLLBACK, $task_id ) ); // phpcs:ignore -- test fixture.
		$task = $this->spine->get_task( $task_id );

		$this->runner->cleanup_terminal_task( $task );

		$this->assertNotContains( 'exit_maintenance', $this->runner->fake_engine->calls, 'FK rollback must keep maintenance on.' );
	}

	/**
	 * A backup task is not the restore runner's concern - cleanup is a no-op.
	 */
	public function test_cleanup_terminal_task_skips_backup_tasks() {
		$task_id = $this->spine->create_task( 'full' );
		$task    = $this->spine->get_task( $task_id );

		$this->runner->cleanup_terminal_task( $task );

		$this->assertNotContains( 'exit_maintenance', $this->runner->fake_engine->calls );
		$this->assertNotContains( 'cleanup_tmp_tables', $this->runner->fake_engine->calls );
	}

	/**
	 * Stale dump litter a previous restore deposited is swept after the
	 * replay even when this restore carries its own db artifact.
	 */
	public function test_dump_litter_is_swept_after_replay() {
		mkdir( $this->site . '/wp-content', 0777, true );
		file_put_contents( $this->site . '/dbBackup-old.sql.php', 'stale' ); // phpcs:ignore -- fixture.
		file_put_contents( $this->site . '/dbBackup.sql', '' ); // phpcs:ignore -- fixture.
		file_put_contents( $this->site . '/wp-content/dbBackup-options.sql.php', 'stale' ); // phpcs:ignore -- fixture.

		$task_id = $this->make_task( 'db', array( $this->make_db_artifact() ) );
		$task    = $this->drive( $task_id );

		$this->assertSame( MainWP_Child_Backup_Task::PHASE_COMPLETED, $task->phase );
		$this->assertFileDoesNotExist( $this->site . '/dbBackup-old.sql.php' );
		$this->assertFileDoesNotExist( $this->site . '/dbBackup.sql' );
		$this->assertFileDoesNotExist( $this->site . '/wp-content/dbBackup-options.sql.php' );
	}

	/**
	 * No db artifact and no embedded dumps is a terminal failure with a
	 * clear message, not a silent files-only restore.
	 */
	public function test_no_db_artifact_and_no_embedded_dump_fails() {
		$task_id = $this->make_task( 'full', array( $this->make_part_artifact() ) );
		$task    = $this->drive( $task_id );

		$this->assertSame( MainWP_Child_Backup_Task::PHASE_FAILED, $task->phase );

		$payload = $this->spine->status_payload( $task );
		$this->assertSame( MainWP_Backup_Error::ERR_RESTORE_DB_REPLAY, $payload['error_code'] );
		$this->assertStringContainsString( 'No database artifact', $payload['error'] );
	}

	/**
	 * An artifact whose bytes differ from the manifest hash is not
	 * adopted, and the missing transfer keeps the task in receiving.
	 */
	public function test_adoption_refuses_hash_mismatch() {
		$artifact = $this->make_db_artifact();
		// Corrupt the on-disk copy after recording the manifest hash.
		file_put_contents( $this->dir . '/db.zip', 'tampered bytes' ); // phpcs:ignore -- fixture.

		$task_id = $this->make_task( 'db', array( $artifact ) );
		$task    = $this->spine->get_task( $task_id );
		$task    = $this->runner->advance_one_slice( $task, MainWP_Child_Backup_Task_Runner::LEASE_OWNER_DASHBOARD );

		$this->assertSame( MainWP_Child_Backup_Task::PHASE_RECEIVING_ARTIFACTS, $task->phase );

		$sub_state = $this->spine->read_sub_state( $task, $this->dir );
		$this->assertEmpty( $sub_state['receiving']['files'] );
	}

	/**
	 * Cancelling an idle restore task lands it terminal failed with the
	 * cancel code and runs the same cleanup as a failure: tmp tables
	 * dropped, maintenance lifted, scratch removed, old tables kept.
	 */
	public function test_cancel_idle_restore_task_lands_failed_with_cancel_code() {
		$task_id = $this->make_task( 'db', array( $this->make_db_artifact() ) );

		$outcome = $this->runner->cancel_restore_task( $task_id );

		$this->assertSame( 'ok', $outcome['result'] );

		$task = $this->spine->get_task( $task_id );
		$this->assertSame( MainWP_Child_Backup_Task::PHASE_FAILED, $task->phase );

		$payload = $this->spine->status_payload( $task );
		$this->assertSame( MainWP_Backup_Error::ERR_RESTORE_CANCELLED, $payload['error_code'] );

		$calls = $this->runner->fake_engine->calls;
		$this->assertContains( 'cleanup_tmp_tables', $calls );
		$this->assertContains( 'exit_maintenance', $calls );
		$this->assertNotContains( 'drop_old_tables', $calls );
		$this->assertDirectoryDoesNotExist( $this->dir . '/restore-' . $task_id );
	}

	/**
	 * Cancel never breaks a live lease: while another runner owns the
	 * task the cancel reports busy and changes nothing.
	 */
	public function test_cancel_while_lease_held_returns_busy() {
		$task_id = $this->make_task( 'db', array( $this->make_db_artifact() ) );
		$task    = $this->spine->get_task( $task_id );
		$this->assertTrue( $this->spine->acquire_lease( $task_id, 'other-runner', (int) $task->version ) );

		$outcome = $this->runner->cancel_restore_task( $task_id );

		$this->assertSame( 'busy', $outcome['result'] );
		$task = $this->spine->get_task( $task_id );
		$this->assertSame( MainWP_Child_Backup_Task::PHASE_QUEUED, $task->phase );
	}

	/**
	 * Cancelling a finished task is a no-op that reports the terminal
	 * phase instead of failing a completed restore.
	 */
	public function test_cancel_terminal_task_reports_already_terminal() {
		$task_id = $this->make_task( 'db', array( $this->make_db_artifact() ) );
		$this->drive( $task_id );

		$outcome = $this->runner->cancel_restore_task( $task_id );

		$this->assertSame( 'already_terminal', $outcome['result'] );
		$this->assertSame( MainWP_Child_Backup_Task::PHASE_COMPLETED, $outcome['phase'] );

		$task = $this->spine->get_task( $task_id );
		$this->assertSame( MainWP_Child_Backup_Task::PHASE_COMPLETED, $task->phase );
	}

	/**
	 * Cancelling an unknown or non-restore task reports an error.
	 */
	public function test_cancel_unknown_task_reports_error() {
		$outcome = $this->runner->cancel_restore_task( 'no-such-task' );

		$this->assertArrayHasKey( 'error', $outcome );
	}

	/**
	 * The backup runner's watchdog listener leaves restore tasks alone.
	 */
	public function test_backup_runner_ignores_restore_tasks_on_resume() {
		$task_id = $this->make_task( 'db', array( $this->make_db_artifact() ) );
		$before  = $this->spine->get_task( $task_id );

		MainWP_Child_Backup_Task_Runner::instance()->handle_watchdog_resume( $before, (int) $before->version );

		$after = $this->spine->get_task( $task_id );
		$this->assertSame( $before->phase, $after->phase );
		$this->assertSame( (int) $before->version, (int) $after->version );
	}

	/**
	 * The restore runner's listener leaves backup tasks alone.
	 */
	public function test_restore_runner_ignores_backup_tasks_on_resume() {
		$task_id = $this->spine->create_task( 'db', array() );
		$before  = $this->spine->get_task( $task_id );

		$this->runner->handle_watchdog_resume( $before, (int) $before->version );

		$after = $this->spine->get_task( $task_id );
		$this->assertSame( $before->phase, $after->phase );
		$this->assertSame( (int) $before->version, (int) $after->version );
	}

	/**
	 * The single-slot invariant blocks a second task while a restore is
	 * active.
	 */
	public function test_active_restore_blocks_second_task() {
		$this->make_task( 'db', array( $this->make_db_artifact() ) );

		$this->expectException( \MainWP\Child\MainWP_Exception::class );
		$this->make_task( 'db', array( $this->make_db_artifact() ) );
	}

	/**
	 * The restore runner owns the files-only restore type, so the
	 * watchdog routes its stalled tasks here.
	 */
	public function test_is_restore_type_includes_restore_files() {
		$this->assertTrue( MainWP_Child_Restore_Task_Runner::is_restore_type( 'restore_files' ) );
	}

	/**
	 * A files sub_type creates a restore_files task.
	 */
	public function test_create_restore_files_task_creates_restore_files_type() {
		$task_id = $this->make_task( 'files', array( $this->make_files_archive_artifact() ) );
		$task    = $this->spine->get_task( $task_id );

		$this->assertSame( 'restore_files', $task->type );
	}

	/**
	 * A files-only restore extracts the archive and applies tombstones,
	 * completes through the verify phase, and never touches a database
	 * phase: no replay, no swap, no tmp-table cleanup, no maintenance
	 * mode.
	 */
	public function test_files_only_restore_pipeline_completes_without_db_phases() {
		file_put_contents( $this->site . '/stale.txt', 'old' ); // phpcs:ignore -- fixture.

		$task_id = $this->make_task(
			'files',
			array( $this->make_files_archive_artifact(), $this->make_tombstones_artifact() )
		);

		// Task creation runs the idempotency sweep for every restore
		// type; the assertion below is about the pipeline phases.
		$this->runner->fake_engine->calls = array();

		$task = $this->drive( $task_id );

		$this->assertSame( MainWP_Child_Backup_Task::PHASE_COMPLETED, $task->phase );
		$this->assertSame( 'restored-content', file_get_contents( $this->site . '/wp-content/uploads/restored.txt' ) );
		$this->assertFileDoesNotExist( $this->site . '/stale.txt' );

		$calls = $this->runner->fake_engine->calls;
		$this->assertContains( 'verify_row_counts', $calls );
		foreach ( $calls as $call ) {
			$this->assertStringNotContainsString( 'replay_slice', $call );
			$this->assertStringNotContainsString( 'swap_tables', $call );
			$this->assertStringNotContainsString( 'cleanup_tmp_tables', $call );
			$this->assertStringNotContainsString( 'exit_maintenance', $call );
		}

		// Scratch staging is gone after a verified restore.
		$this->assertDirectoryDoesNotExist( $this->dir . '/restore-' . $task_id );
	}

	/**
	 * GUID columns are excluded from URL rewriting for the main posts
	 * table and every sub-blog posts table, and nothing else.
	 */
	public function test_guid_exclusions_cover_sub_blog_posts_tables() {
		$exclude = $this->runner->guid_exclusions( array( 'posts', '2_posts', '10_posts', 'comments', 'postmeta', '2_options' ), 'rt_' );

		$this->assertSame( array( 'guid' ), $exclude['rt_posts'] );
		$this->assertSame( array( 'guid' ), $exclude['rt_2_posts'] );
		$this->assertSame( array( 'guid' ), $exclude['rt_10_posts'] );
		$this->assertArrayNotHasKey( 'rt_comments', $exclude );
		$this->assertArrayNotHasKey( 'rt_postmeta', $exclude );
		$this->assertArrayNotHasKey( 'rt_2_options', $exclude );
	}

	/**
	 * Restore task creation no longer refuses multisite installs: the
	 * capability gate lives on the dashboard, and an old dashboard
	 * talking to a new child simply restores network-wide.
	 */
	public function test_create_restore_task_allows_multisite() {
		$this->runner->fake_is_multisite = true;
		$task_id                         = $this->make_task( 'db', array( $this->make_db_artifact() ) );
		$this->assertNotEmpty( $task_id );

		$task = $this->spine->get_task( $task_id );
		$this->assertSame( 'restore_db', $task->type );
	}

	/**
	 * On a single-site install the sub-blog smoke check is skipped even
	 * when sub-site URLs happen to be present: the multisite gate wins.
	 */
	public function test_sub_site_probes_empty_on_single_site() {
		$this->runner->fake_is_multisite  = false;
		$this->runner->fake_sub_site_urls = array( 2 => 'http://example.org/sub/' );

		$this->assertSame( array(), $this->runner->expose_sub_site_probes() );
	}

	/**
	 * A multisite network with only the main site (no sub-blogs) records
	 * no probes.
	 */
	public function test_sub_site_probes_empty_when_network_has_no_sub_blogs() {
		$this->runner->fake_is_multisite  = true;
		$this->runner->fake_sub_site_urls = array();

		$this->assertSame( array(), $this->runner->expose_sub_site_probes() );
	}

	/**
	 * On multisite the smoke check probes the first sub-site only and
	 * records its blog id, URL, and HTTP status.
	 */
	public function test_sub_site_probes_records_first_sub_site() {
		$this->runner->fake_is_multisite  = true;
		$this->runner->fake_sub_site_urls = array(
			2 => 'http://example.org/blog2/',
			3 => 'http://example.org/blog3/',
		);

		$probed = array();
		$filter = function ( $pre, $args, $url ) use ( &$probed ) {
			$probed[] = $url;
			return array(
				'response' => array(
					'code'    => 200,
					'message' => 'OK',
				),
				'body'     => '',
				'headers'  => array(),
			);
		};
		add_filter( 'pre_http_request', $filter, 10, 3 );

		$probes = $this->runner->expose_sub_site_probes();

		remove_filter( 'pre_http_request', $filter, 10 );

		$this->assertCount( 1, $probes );
		$this->assertSame( 2, $probes[0]['blog_id'] );
		$this->assertSame( 'http://example.org/blog2/', $probes[0]['url'] );
		$this->assertSame( 200, $probes[0]['status'] );
		$this->assertSame( array( 'http://example.org/blog2/' ), $probed );
	}

	/**
	 * A failed sub-site request records status 0 and never throws - the
	 * smoke check is advisory.
	 */
	public function test_sub_site_probe_records_zero_on_http_error() {
		$this->runner->fake_is_multisite  = true;
		$this->runner->fake_sub_site_urls = array( 2 => 'http://example.org/blog2/' );

		$filter = function () {
			return new WP_Error( 'http_request_failed', 'boom' );
		};
		add_filter( 'pre_http_request', $filter, 10, 3 );

		$probes = $this->runner->expose_sub_site_probes();

		remove_filter( 'pre_http_request', $filter, 10 );

		$this->assertCount( 1, $probes );
		$this->assertSame( 0, $probes[0]['status'] );
	}

	/**
	 * A multisite restore carries the sub-blog probe results through the
	 * verification payload alongside the existing fields.
	 */
	public function test_verification_payload_carries_sub_site_probes() {
		$this->runner->fake_is_multisite  = true;
		$this->runner->fake_sub_site_urls = array( 2 => 'http://example.org/blog2/' );

		$filter = function () {
			return array(
				'response' => array(
					'code'    => 200,
					'message' => 'OK',
				),
				'body'     => '',
				'headers'  => array(),
			);
		};
		add_filter( 'pre_http_request', $filter, 10, 3 );

		$task_id = $this->make_task( 'db', array( $this->make_db_artifact() ) );
		$task    = $this->drive( $task_id );

		remove_filter( 'pre_http_request', $filter, 10 );

		$this->assertSame( MainWP_Child_Backup_Task::PHASE_COMPLETED, $task->phase );

		$sub_state = $this->spine->read_sub_state( $task, $this->dir );
		$this->assertArrayHasKey( 'sub_site_probes', $sub_state['verification'] );
		$this->assertCount( 1, $sub_state['verification']['sub_site_probes'] );
		$this->assertSame( 2, $sub_state['verification']['sub_site_probes'][0]['blog_id'] );
		$this->assertSame( 200, $sub_state['verification']['sub_site_probes'][0]['status'] );
	}

	/**
	 * Swap in the cloud-pull runner, keeping the pinned dirs and engine.
	 *
	 * @return Cloud_Pull_Test_Restore_Runner
	 */
	protected function use_cloud_pull_runner() {
		$runner                  = new Cloud_Pull_Test_Restore_Runner();
		$runner->test_backup_dir = $this->dir;
		$runner->test_site_root  = $this->site;
		$runner->fake_engine     = $this->runner->fake_engine;
		$this->runner            = $runner;
		return $runner;
	}

	/**
	 * Build the db artifact, capture its bytes, and remove the local
	 * copy so adoption cannot satisfy it - only the cloud has it.
	 *
	 * @return array { artifact, bytes }
	 */
	protected function make_cloud_only_db_artifact() {
		$artifact = $this->make_db_artifact();
		$bytes    = file_get_contents( $this->dir . '/db.zip' );
		unlink( $this->dir . '/db.zip' );
		return array(
			'artifact' => $artifact,
			'bytes'    => $bytes,
		);
	}

	/**
	 * A restore with a whole-file pull manifest downloads the artifact
	 * from the cloud, verifies it, and completes the full pipeline
	 * without any wire transfer.
	 */
	public function test_cloud_pull_whole_artifact_restore_completes() {
		$runner = $this->use_cloud_pull_runner();
		$cloud  = $this->make_cloud_only_db_artifact();
		$url    = 'https://bucket.example/4/42/files/db.zip?X-Amz-Signature=test';

		$runner->pull_responses[ $url ] = $cloud['bytes'];

		$task_id = $this->make_task(
			'db',
			array( $cloud['artifact'] ),
			array(
				'pull_manifest' => array(
					'mode'      => 'whole_files',
					'artifacts' => array(
						array(
							'file'   => 'db.zip',
							'sha256' => $cloud['artifact']['sha256'],
							'size'   => $cloud['artifact']['size'],
							'url'    => $url,
						),
					),
				),
			)
		);
		$task    = $this->drive( $task_id );

		$this->assertSame( MainWP_Child_Backup_Task::PHASE_COMPLETED, $task->phase );
		$this->assertContains( $url, $runner->pulled_urls );
	}

	/**
	 * Pulled bytes that fail the artifact hash land the task terminal
	 * failed with the cloud-pull error code, and the error never echoes
	 * the presigned URL.
	 */
	public function test_cloud_pull_bad_hash_fails_with_pull_code() {
		$runner = $this->use_cloud_pull_runner();
		$cloud  = $this->make_cloud_only_db_artifact();
		$url    = 'https://bucket.example/db.zip?X-Amz-Signature=secret';

		$runner->pull_responses[ $url ] = 'garbage-bytes';

		$task_id = $this->make_task(
			'db',
			array( $cloud['artifact'] ),
			array(
				'pull_manifest' => array(
					'mode'      => 'whole_files',
					'artifacts' => array(
						array(
							'file'   => 'db.zip',
							'sha256' => $cloud['artifact']['sha256'],
							'size'   => $cloud['artifact']['size'],
							'url'    => $url,
						),
					),
				),
			)
		);
		$task    = $this->drive( $task_id );

		$this->assertSame( MainWP_Child_Backup_Task::PHASE_FAILED, $task->phase );

		$payload = $this->spine->status_payload( $task );
		$this->assertSame( MainWP_Backup_Error::ERR_CLOUD_PULL_FAILED, $payload['error_code'] );
		$this->assertStringNotContainsString( 'X-Amz', $payload['error'], 'The presigned URL must never appear in errors.' );
	}

	/**
	 * A failed download lands terminal failed with the cloud-pull error
	 * code so the dashboard falls back to the staged transfer.
	 */
	public function test_cloud_pull_download_failure_fails_with_pull_code() {
		$runner = $this->use_cloud_pull_runner();
		$cloud  = $this->make_cloud_only_db_artifact();
		$url    = 'https://bucket.example/db.zip?X-Amz-Signature=test';

		$runner->pull_responses[ $url ] = false;

		$task_id = $this->make_task(
			'db',
			array( $cloud['artifact'] ),
			array(
				'pull_manifest' => array(
					'mode'      => 'whole_files',
					'artifacts' => array(
						array(
							'file'   => 'db.zip',
							'sha256' => $cloud['artifact']['sha256'],
							'size'   => $cloud['artifact']['size'],
							'url'    => $url,
						),
					),
				),
			)
		);
		$task    = $this->drive( $task_id );

		$this->assertSame( MainWP_Child_Backup_Task::PHASE_FAILED, $task->phase );
		$this->assertSame( MainWP_Backup_Error::ERR_CLOUD_PULL_FAILED, $this->spine->status_payload( $task )['error_code'] );
	}

	/**
	 * A chunked pull manifest downloads every piece, verifies each chunk
	 * hash, reassembles the artifact, verifies the whole, and completes.
	 */
	public function test_cloud_pull_chunked_artifact_reassembles_and_completes() {
		$runner = $this->use_cloud_pull_runner();
		$cloud  = $this->make_cloud_only_db_artifact();

		$split  = (int) ceil( strlen( $cloud['bytes'] ) / 2 );
		$piece1 = substr( $cloud['bytes'], 0, $split );
		$piece2 = substr( $cloud['bytes'], $split );
		$url1   = 'https://bucket.example/chunks/' . hash( 'sha256', $piece1 ) . '.bin?X-Amz-Signature=a';
		$url2   = 'https://bucket.example/chunks/' . hash( 'sha256', $piece2 ) . '.bin?X-Amz-Signature=b';

		$runner->pull_responses[ $url1 ] = $piece1;
		$runner->pull_responses[ $url2 ] = $piece2;

		$task_id = $this->make_task(
			'db',
			array( $cloud['artifact'] ),
			array(
				'pull_manifest' => array(
					'mode'      => 'chunked',
					'artifacts' => array(
						array(
							'file'   => 'db.zip',
							'sha256' => $cloud['artifact']['sha256'],
							'size'   => $cloud['artifact']['size'],
							'chunks' => array(
								array(
									'hash' => hash( 'sha256', $piece1 ),
									'url'  => $url1,
								),
								array(
									'hash' => hash( 'sha256', $piece2 ),
									'url'  => $url2,
								),
							),
						),
					),
				),
			)
		);
		$task    = $this->drive( $task_id );

		$this->assertSame( MainWP_Child_Backup_Task::PHASE_COMPLETED, $task->phase );
		$this->assertSame( array( $url1, $url2 ), $runner->pulled_urls );
	}

	/**
	 * Chunk pieces verify with the algorithm the manifest declares:
	 * production uploads content-address chunks with xxh128 where
	 * available, and the pull must honor that.
	 */
	public function test_cloud_pull_chunked_verifies_with_declared_algo() {
		if ( ! in_array( 'xxh128', hash_algos(), true ) ) {
			$this->markTestSkipped( 'xxh128 is not available on this PHP.' );
		}

		$runner = $this->use_cloud_pull_runner();
		$cloud  = $this->make_cloud_only_db_artifact();

		$split  = (int) ceil( strlen( $cloud['bytes'] ) / 2 );
		$piece1 = substr( $cloud['bytes'], 0, $split );
		$piece2 = substr( $cloud['bytes'], $split );
		$url1   = 'https://bucket.example/chunks/' . hash( 'xxh128', $piece1 ) . '.bin?sig=a';
		$url2   = 'https://bucket.example/chunks/' . hash( 'xxh128', $piece2 ) . '.bin?sig=b';

		$runner->pull_responses[ $url1 ] = $piece1;
		$runner->pull_responses[ $url2 ] = $piece2;

		$task_id = $this->make_task(
			'db',
			array( $cloud['artifact'] ),
			array(
				'pull_manifest' => array(
					'mode'      => 'chunked',
					'artifacts' => array(
						array(
							'file'   => 'db.zip',
							'sha256' => $cloud['artifact']['sha256'],
							'size'   => $cloud['artifact']['size'],
							'chunks' => array(
								array(
									'hash' => hash( 'xxh128', $piece1 ),
									'algo' => 'xxh128',
									'url'  => $url1,
								),
								array(
									'hash' => hash( 'xxh128', $piece2 ),
									'algo' => 'xxh128',
									'url'  => $url2,
								),
							),
						),
					),
				),
			)
		);
		$task    = $this->drive( $task_id );

		$this->assertSame( MainWP_Child_Backup_Task::PHASE_COMPLETED, $task->phase );
	}

	/**
	 * The sanitizer accepts xxh128 chunk hashes with their declared
	 * algorithm and rejects algorithms it does not know.
	 */
	public function test_sanitize_pull_manifest_chunk_algos() {
		$artifacts = array(
			array(
				'file'       => 'big.zip',
				'adopt_only' => false,
			),
		);
		$sha       = str_repeat( 'a', 64 );
		$manifest  = function ( $chunk ) use ( $sha ) {
			return array(
				'artifacts' => array(
					array(
						'file'   => 'big.zip',
						'sha256' => $sha,
						'size'   => 10,
						'chunks' => array( $chunk ),
					),
				),
			);
		};

		if ( in_array( 'xxh128', hash_algos(), true ) ) {
			$clean = MainWP_Child_Restore_Task_Runner::sanitize_pull_manifest(
				$manifest(
					array(
						'hash' => str_repeat( '1', 32 ),
						'algo' => 'xxh128',
						'url'  => 'https://bucket.example/c.bin',
					)
				),
				$artifacts
			);
			$this->assertNotNull( $clean );
			$this->assertSame( 'xxh128', $clean['artifacts'][0]['chunks'][0]['algo'] );
		}

		$this->assertNull(
			MainWP_Child_Restore_Task_Runner::sanitize_pull_manifest(
				$manifest(
					array(
						'hash' => str_repeat( '1', 32 ),
						'algo' => 'md5',
						'url'  => 'https://bucket.example/c.bin',
					)
				),
				$artifacts
			),
			'Unknown chunk algorithms must be rejected.'
		);

		$this->assertNull(
			MainWP_Child_Restore_Task_Runner::sanitize_pull_manifest(
				$manifest(
					array(
						'hash' => str_repeat( '1', 32 ),
						'url'  => 'https://bucket.example/c.bin',
					)
				),
				$artifacts
			),
			'A 32-hex hash without a declared algorithm must be rejected.'
		);
	}

	/**
	 * A pull manifest naming a file the task was not created with is
	 * rejected without downloading anything.
	 */
	public function test_cloud_pull_rejects_unknown_basename() {
		$runner = $this->use_cloud_pull_runner();
		$cloud  = $this->make_cloud_only_db_artifact();

		$task_id = $this->make_task(
			'db',
			array( $cloud['artifact'] ),
			array(
				'pull_manifest' => array(
					'mode'      => 'whole_files',
					'artifacts' => array(
						array(
							'file'   => 'evil.zip',
							'sha256' => $cloud['artifact']['sha256'],
							'size'   => 4,
							'url'    => 'https://bucket.example/evil.zip',
						),
					),
				),
			)
		);
		$task    = $this->drive( $task_id );

		$this->assertSame( MainWP_Child_Backup_Task::PHASE_FAILED, $task->phase );
		$this->assertSame( MainWP_Backup_Error::ERR_CLOUD_PULL_FAILED, $this->spine->status_payload( $task )['error_code'] );
		$this->assertSame( array(), $runner->pulled_urls, 'Nothing may download for a rejected manifest.' );
	}

	/**
	 * The pull-manifest sanitizer accepts well-formed whole-file and
	 * chunked manifests whose names belong to the task's artifact list.
	 */
	public function test_sanitize_pull_manifest_accepts_valid_manifests() {
		$artifacts = array(
			array(
				'file'       => 'db.zip',
				'adopt_only' => false,
			),
			array(
				'file'       => 'big.zip',
				'adopt_only' => false,
			),
		);
		$sha       = str_repeat( 'a', 64 );

		$clean = MainWP_Child_Restore_Task_Runner::sanitize_pull_manifest(
			array(
				'artifacts' => array(
					array(
						'file'   => 'db.zip',
						'sha256' => $sha,
						'size'   => 10,
						'url'    => 'https://bucket.example/db.zip?X-Amz-Signature=x',
					),
					array(
						'file'   => 'big.zip',
						'sha256' => $sha,
						'size'   => 20,
						'chunks' => array(
							array(
								'hash' => $sha,
								'url'  => 'https://bucket.example/chunks/aa.bin?sig=1',
							),
						),
					),
				),
			),
			$artifacts
		);

		$this->assertNotNull( $clean );
		$this->assertCount( 2, $clean['artifacts'] );
		$this->assertSame( 'db.zip', $clean['artifacts'][0]['file'] );
		$this->assertSame( 10, $clean['artifacts'][0]['size'] );
		$this->assertCount( 1, $clean['artifacts'][1]['chunks'] );
	}

	/**
	 * The sanitizer rejects manifests wholesale on any violation: a
	 * non-https URL, an unknown basename, a malformed hash, an
	 * adopt-only target, or a shape that is not a manifest.
	 */
	public function test_sanitize_pull_manifest_rejects_violations() {
		$sha       = str_repeat( 'a', 64 );
		$artifacts = array(
			array(
				'file'       => 'db.zip',
				'adopt_only' => false,
			),
			array(
				'file'       => 'old.zip',
				'adopt_only' => true,
			),
		);
		$entry     = function ( $over = array() ) use ( $sha ) {
			return array(
				'artifacts' => array(
					array_merge(
						array(
							'file'   => 'db.zip',
							'sha256' => $sha,
							'size'   => 10,
							'url'    => 'https://bucket.example/db.zip',
						),
						$over
					),
				),
			);
		};

		$this->assertNull( MainWP_Child_Restore_Task_Runner::sanitize_pull_manifest( 'not-an-array', $artifacts ) );
		$this->assertNull( MainWP_Child_Restore_Task_Runner::sanitize_pull_manifest( array( 'artifacts' => array() ), $artifacts ) );
		$this->assertNull( MainWP_Child_Restore_Task_Runner::sanitize_pull_manifest( $entry( array( 'url' => 'http://bucket.example/db.zip' ) ), $artifacts ), 'Plain http URLs must be rejected.' );
		$this->assertNull( MainWP_Child_Restore_Task_Runner::sanitize_pull_manifest( $entry( array( 'file' => 'evil.zip' ) ), $artifacts ), 'Unknown basenames must be rejected.' );
		$this->assertNull( MainWP_Child_Restore_Task_Runner::sanitize_pull_manifest( $entry( array( 'file' => 'old.zip' ) ), $artifacts ), 'Adopt-only artifacts must never be pull targets.' );
		$this->assertNull( MainWP_Child_Restore_Task_Runner::sanitize_pull_manifest( $entry( array( 'sha256' => 'nothex' ) ), $artifacts ), 'Malformed hashes must be rejected.' );
		$this->assertNull( MainWP_Child_Restore_Task_Runner::sanitize_pull_manifest( $entry( array( 'url' => null ) ), $artifacts ), 'A whole-file entry without a URL must be rejected.' );
		$this->assertNull(
			MainWP_Child_Restore_Task_Runner::sanitize_pull_manifest(
				$entry(
					array(
						'url'    => null,
						'chunks' => array(
							array(
								'hash' => $sha,
								'url'  => 'ftp://bucket.example/aa.bin',
							),
						),
					)
				),
				$artifacts
			),
			'Non-https chunk URLs must be rejected.'
		);
	}

	/**
	 * SSRF: cloud-metadata and link-local pull URLs are rejected
	 * unconditionally, with the private-pull override on or off.
	 */
	public function test_sanitize_pull_manifest_blocks_metadata_and_link_local() {
		$sha       = str_repeat( 'a', 64 );
		$artifacts = array( array( 'file' => 'db.zip', 'adopt_only' => false ) );
		$manifest  = function ( $url ) use ( $sha ) {
			return array(
				'artifacts' => array(
					array(
						'file'   => 'db.zip',
						'sha256' => $sha,
						'size'   => 10,
						'url'    => $url,
					),
				),
			);
		};

		$targets = array(
			'https://169.254.169.254/latest/meta-data/'         => 'AWS/Azure metadata IP',
			'https://metadata.google.internal/computeMetadata/' => 'GCP metadata hostname',
			'https://[::1]/object'                              => 'IPv6 loopback literal',
		);

		foreach ( array( '', '1' ) as $override ) {
			if ( '' === $override ) {
				delete_option( 'mainwp_backup_allow_private_pull' );
			} else {
				update_option( 'mainwp_backup_allow_private_pull', $override );
			}
			foreach ( $targets as $url => $label ) {
				$this->assertNull(
					MainWP_Child_Restore_Task_Runner::sanitize_pull_manifest( $manifest( $url ), $artifacts ),
					$label . ' must be blocked regardless of the private-pull override.'
				);
			}
		}
		delete_option( 'mainwp_backup_allow_private_pull' );
	}

	/**
	 * SSRF: an RFC1918 / loopback pull URL is denied by default and
	 * accepted only when the private-pull override option is set (the
	 * on-prem MinIO / testbed case).
	 */
	public function test_sanitize_pull_manifest_private_denied_unless_overridden() {
		$sha       = str_repeat( 'a', 64 );
		$artifacts = array( array( 'file' => 'db.zip', 'adopt_only' => false ) );
		$manifest  = array(
			'artifacts' => array(
				array(
					'file'   => 'db.zip',
					'sha256' => $sha,
					'size'   => 10,
					'url'    => 'https://127.0.0.1:9443/mainwp-e2e/db.zip',
				),
			),
		);

		delete_option( 'mainwp_backup_allow_private_pull' );
		$this->assertNull(
			MainWP_Child_Restore_Task_Runner::sanitize_pull_manifest( $manifest, $artifacts ),
			'A private/loopback pull URL must be denied by default.'
		);

		update_option( 'mainwp_backup_allow_private_pull', '1' );
		$clean = MainWP_Child_Restore_Task_Runner::sanitize_pull_manifest( $manifest, $artifacts );
		$this->assertNotNull( $clean, 'The override option must allow a configured private endpoint.' );
		$this->assertSame( 'db.zip', $clean['artifacts'][0]['file'] );
		delete_option( 'mainwp_backup_allow_private_pull' );
	}

	/**
	 * SSRF: a public-looking hostname that resolves to the metadata IP is
	 * blocked, while one that does not resolve to a private IP passes.
	 */
	public function test_sanitize_pull_manifest_blocks_hostname_resolving_to_metadata() {
		$sha       = str_repeat( 'a', 64 );
		$artifacts = array( array( 'file' => 'db.zip', 'adopt_only' => false ) );
		$manifest  = array(
			'artifacts' => array(
				array(
					'file'   => 'db.zip',
					'sha256' => $sha,
					'size'   => 10,
					'url'    => 'https://innocent.example.com/db.zip',
				),
			),
		);

		Ssrf_Resolver_Test_Restore_Runner::$resolved = array( 'innocent.example.com' => '169.254.169.254' );
		$this->assertNull(
			Ssrf_Resolver_Test_Restore_Runner::sanitize_pull_manifest( $manifest, $artifacts ),
			'A hostname resolving to the metadata IP must be blocked.'
		);

		Ssrf_Resolver_Test_Restore_Runner::$resolved = array();
		$this->assertNotNull(
			Ssrf_Resolver_Test_Restore_Runner::sanitize_pull_manifest( $manifest, $artifacts ),
			'A hostname that does not resolve to a private IP passes.'
		);
	}

	/**
	 * SSRF: an IPv6-only host that resolves to a private (ULA) or link-local
	 * address is blocked. IPv4-only resolution would have yielded no IP and
	 * let the URL through as "public" while wp_remote_get reaches the IPv6.
	 */
	public function test_sanitize_pull_manifest_blocks_ipv6_only_private_host() {
		$sha       = str_repeat( 'a', 64 );
		$artifacts = array( array( 'file' => 'db.zip', 'adopt_only' => false ) );
		$manifest  = array(
			'artifacts' => array(
				array(
					'file'   => 'db.zip',
					'sha256' => $sha,
					'size'   => 10,
					'url'    => 'https://v6only.example.com/db.zip',
				),
			),
		);

		// ULA (fc00::/7) - caught by the private-range check.
		Ssrf_Resolver_Test_Restore_Runner::$resolved = array( 'v6only.example.com' => array( 'fd00::1' ) );
		$this->assertNull(
			Ssrf_Resolver_Test_Restore_Runner::sanitize_pull_manifest( $manifest, $artifacts ),
			'An IPv6 ULA host must be blocked.'
		);

		// Link-local (fe80::/10) - caught by the metadata/link-local check.
		Ssrf_Resolver_Test_Restore_Runner::$resolved = array( 'v6only.example.com' => array( 'fe80::1' ) );
		$this->assertNull(
			Ssrf_Resolver_Test_Restore_Runner::sanitize_pull_manifest( $manifest, $artifacts ),
			'An IPv6 link-local host must be blocked.'
		);

		// A public IPv6 still passes.
		Ssrf_Resolver_Test_Restore_Runner::$resolved = array( 'v6only.example.com' => array( '2606:4700:4700::1111' ) );
		$this->assertNotNull(
			Ssrf_Resolver_Test_Restore_Runner::sanitize_pull_manifest( $manifest, $artifacts ),
			'A public IPv6 host passes.'
		);

		Ssrf_Resolver_Test_Restore_Runner::$resolved = array();
	}

	/**
	 * The disk preflight fails the task before any download when free
	 * space cannot hold the manifest's artifacts.
	 */
	public function test_cloud_pull_disk_preflight_fails_early() {
		$runner = $this->use_cloud_pull_runner();
		$cloud  = $this->make_cloud_only_db_artifact();

		$runner->fake_free_space = 10.0;

		$task_id = $this->make_task(
			'db',
			array( $cloud['artifact'] ),
			array(
				'pull_manifest' => array(
					'mode'      => 'whole_files',
					'artifacts' => array(
						array(
							'file'   => 'db.zip',
							'sha256' => $cloud['artifact']['sha256'],
							'size'   => $cloud['artifact']['size'],
							'url'    => 'https://bucket.example/db.zip',
						),
					),
				),
			)
		);
		$task    = $this->drive( $task_id );

		$this->assertSame( MainWP_Child_Backup_Task::PHASE_FAILED, $task->phase );
		$this->assertSame( MainWP_Backup_Error::ERR_DISK_FULL, $this->spine->status_payload( $task )['error_code'] );
		$this->assertSame( array(), $runner->pulled_urls, 'Nothing may download when disk space is insufficient.' );
	}
}
