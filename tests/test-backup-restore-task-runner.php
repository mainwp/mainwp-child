<?php
/**
 * Class BackupRestoreTaskRunnerTest
 *
 * @package Mainwp_Child
 */

use MainWP\Child\MainWP_Backup_Db_Restore_Engine;
use MainWP\Child\MainWP_Backup_Error;
use MainWP\Child\MainWP_Child_Backup_Reconciler;
use MainWP\Child\MainWP_Child_Backup_Task;
use MainWP\Child\MainWP_Child_Backup_Mutex;
use MainWP\Child\MainWP_Child_Backup_Task_Runner;
use MainWP\Child\MainWP_Child_Callable;
use MainWP\Child\MainWP_Child_Restore_Task_Runner;
use MainWP\Child\MainWP_Exception;

class Restore_Cancel_Response extends RuntimeException {
	public $payload;

	public function __construct( $payload ) {
		parent::__construct( 'Restore cancel response' );
		$this->payload = $payload;
	}
}

class Restore_Cancel_Response_Helper extends \MainWP\Child\MainWP_Helper {
	public static function instance() {
		return new self();
	}

	public static function write( $value ) {
		throw new Restore_Cancel_Response( $value );
	}
}

/**
 * Scripted DB restore engine: no database side effects, behavior set
 * by the test.
 */
class Fake_Restore_Engine extends MainWP_Backup_Db_Restore_Engine {

	public $on_database_live = null;

	public $verify_real_counts = false;

	public $replay_real_sql = false;

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
	 * Restore id sets passed to prune_aside_tables, newest last.
	 *
	 * @var array<int,string[]>
	 */
	public $pruned_id_sets = array();

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
		if ( $this->replay_real_sql ) {
			return parent::replay_slice( $cursor, $config, $deadline, $unclean );
		}
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
		if ( ! empty( $config['fk_path'] ) && null !== $this->on_database_live ) {
			call_user_func( $this->on_database_live );
		}
		return array(
			'done'       => true,
			'cursor'     => array( 'tables' => $this->tables ),
			'error'      => null,
			'error_code' => null,
		);
	}

	/**
	 * Error the swap should report, or null.
	 *
	 * @var string|null
	 */
	public $swap_error = null;

	public $swap_committed = false;

	public $committed_old_tables = array();

	public $swap_committed_answers = array();

	public function is_swap_committed( $stems, $config, &$old_tables = array() ) {
		$this->calls[] = 'is_swap_committed';
		$old_tables = $this->committed_old_tables;
		return empty( $this->swap_committed_answers ) ? $this->swap_committed : array_shift( $this->swap_committed_answers );
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
		if ( null !== $this->swap_error ) {
			return array(
				'done'       => false,
				'old_tables' => array(),
				'rename_sql' => null,
				'error'      => $this->swap_error,
				'error_code' => MainWP_Backup_Error::ERR_RESTORE_SWAP_FAILED,
			);
		}
		if ( null !== $this->on_database_live ) {
			call_user_func( $this->on_database_live );
		}
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
		if ( $this->verify_real_counts ) {
			global $wpdb;
			$mismatches = array();
			foreach ( $expected as $stem => $rows ) {
				$actual = (int) $wpdb->get_var( 'SELECT COUNT(*) FROM `' . $target_prefix . $stem . '`' );
				if ( '' !== $wpdb->last_error || $actual !== (int) $rows ) {
					$mismatches[ $stem ] = array( 'expected' => $rows, 'actual' => $actual );
				}
			}
			return array( 'ok' => empty( $mismatches ), 'mismatches' => $mismatches );
		}
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
	 * Tracked aside-table prune.
	 *
	 * @param string[] $restore_ids Restore ids.
	 */
	public function prune_aside_tables( array $restore_ids ) {
		$this->calls[]          = 'prune_aside_tables';
		$this->pruned_id_sets[] = $restore_ids;
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
	 * Whether the dump-table split should throw a snapshot-failed error, to
	 * model a live-table enumeration failure.
	 *
	 * @var bool
	 */
	public $list_dump_throw = false;

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
		if ( $this->list_dump_throw ) {
			throw new MainWP_Exception( 'could not enumerate live tables', null, MainWP_Backup_Error::ERR_RESTORE_SNAPSHOT_FAILED ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- test fixture message.
		}
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
 * Terminal cleanup fixtures keep recovery and maintenance effects observable.
 */
class Dump_Sweep_Restore_Engine extends Fake_Restore_Engine {
	public $maintenance_file;
	public $tmp_table;
	public $old_table;
	public $rollback_throw = false;

	public function begin_maintenance() {
		parent::begin_maintenance();
		file_put_contents( $this->maintenance_file, 'offline' );
	}

	public function exit_maintenance() {
		parent::exit_maintenance();
		if ( is_file( $this->maintenance_file ) ) {
			unlink( $this->maintenance_file );
		}
	}

	public function cleanup_tmp_tables( $restore_id ) {
		parent::cleanup_tmp_tables( $restore_id );
		global $wpdb;
		$wpdb->query( 'DROP TABLE IF EXISTS `' . $this->tmp_table . '`' );
	}

	public function drop_old_tables( $restore_id ) {
		parent::drop_old_tables( $restore_id );
		global $wpdb;
		$wpdb->query( 'DROP TABLE IF EXISTS `' . $this->old_table . '`' );
	}

	public function rollback_aside_slice( $cursor, $config, $deadline, $unclean = false ) {
		if ( $this->rollback_throw ) {
			throw new MainWP_Exception( 'rollback exploded', null, MainWP_Backup_Error::ERR_RESTORE_ROLLBACK_FAILED );
		}
		return parent::rollback_aside_slice( $cursor, $config, $deadline, $unclean );
	}
}

/**
 * Keep option gating and result logging real while replacing provider calls.
 */
class Restore_Test_Cache_Purger extends \MainWP\Child\MainWP_Child_Cache_Purge {

	public $calls = array();
	public $provider_calls = 0;
	public $failure = '';
	public $on_purge;
	public $output = '';

	public function __construct() {}

	public function auto_purge_cache( $bulk = '' ) {
		$this->calls[] = func_get_args();
		if ( null !== $this->on_purge ) {
			call_user_func( $this->on_purge );
		}
		if ( 'exception' === $this->failure ) {
			throw new RuntimeException( str_repeat( 'Private /srv/site/file.php token=fixture ', 40 ) );
		}
		if ( 'error' === $this->failure ) {
			throw new TypeError( 'Private /srv/site/file.php token=fixture' );
		}
		return parent::auto_purge_cache( $bulk );
	}

	public function wprocket_auto_cache_purge() {
		++$this->provider_calls;
		echo $this->output;
		// The dispatcher's real WP Rocket outcomes: dispatched on success, provider_missing on failure.
		if ( 'provider' === $this->failure ) {
			return $this->purge_result( 'Private /srv/site/file.php token=fixture', 'ERROR', 'provider_missing' );
		}
		return $this->purge_result( 'Private /srv/site/file.php token=fixture', 'SUCCESS', 'dispatched_unverified' );
	}

	public function cloudflair_auto_purge_cache() {
		return $this->purge_result( 'Private /srv/site/file.php token=fixture', 'ERROR', 'attempt_failed' );
	}
}

/**
 * Restore runner with every environment dependency pinned.
 */
class Testable_Restore_Task_Runner extends MainWP_Child_Restore_Task_Runner {

	public $test_cache_purger;
	public $slice_phases = array();
	public $before_loopback;
	public $test_slice_budget = 40;
	public $late_verify_once = false;
	public $pause_before_phase;
	public $backup_dir_throws = false;
	public $throw_url_rewrite = false;
	public $throw_woo_merge = false;

	protected function cache_purger() {
		return $this->test_cache_purger ? $this->test_cache_purger : parent::cache_purger();
	}

	public function advance_one_slice( $task, $owner, $lease_pre_held = false ) {
		$this->slice_phases = array();
		return parent::advance_one_slice( $task, $owner, $lease_pre_held );
	}

	protected function run_merging_woo( $task, $version, $sub_state, $deadline, $backup_dir ) {
		if ( $this->throw_woo_merge ) {
			throw new MainWP_Exception( 'merge fixture failed', null, MainWP_Backup_Error::ERR_RESTORE_DB_REPLAY );
		}
		return parent::run_merging_woo( $task, $version, $sub_state, $deadline, $backup_dir );
	}

	protected function slice_budget() {
		return $this->test_slice_budget;
	}

	protected function run_phase( $task, $version, $sub_state, $deadline, $backup_dir ) {
		$this->slice_phases[] = $task->phase;
		if ( $this->pause_before_phase === $task->phase ) {
			$this->pause_before_phase = null;
			return $this->slice_result( $version, true );
		}
		if ( $this->late_verify_once && MainWP_Child_Backup_Task::PHASE_POST_RESTORE_VERIFY === $task->phase && count( $this->slice_phases ) > 1 ) {
			$this->late_verify_once = false;
			$deadline = time() - 1;
		}
		return parent::run_phase( $task, $version, $sub_state, $deadline, $backup_dir );
	}

    public $test_reconciler;

	public $test_file_restorer;
	public $mu_plugins_cap = 500;
	public $mu_plugins_checkpoint_crash = false;
	public $legacy_file_checkpoints = false;

	protected function mu_plugins_directory() {
		return $this->test_site_root . '/wp-content/mu-plugins';
	}

	protected function mu_plugins_preserve_limit() {
		return $this->mu_plugins_cap;
	}

	protected function file_restorer() {
		return $this->test_file_restorer ? $this->test_file_restorer : parent::file_restorer();
	}

	protected function checkpoint_sub_state( $task, $version, $sub_state, $backup_dir, $then_yield ) {
		if ( $this->mu_plugins_checkpoint_crash && isset( $sub_state['mu_plugins_preserve'] ) ) {
			$this->mu_plugins_checkpoint_crash = false;
			return $this->slice_result( $version, true );
		}
		if ( $this->legacy_file_checkpoints && ! empty( $sub_state['file_restore']['part_cursor']['entry_index'] ) ) {
			unset( $sub_state['mu_plugins_preserve'] );
		}
		return parent::checkpoint_sub_state( $task, $version, $sub_state, $backup_dir, $then_yield );
	}


    protected function reconciler() {
        return $this->test_reconciler ? $this->test_reconciler : parent::reconciler();
    }


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

	/** @var array|null Content roots override; null uses the site configuration. */
	public $test_content_roots = null;

	protected function content_roots() {
		return null !== $this->test_content_roots ? $this->test_content_roots : parent::content_roots();
	}

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
		if ( $this->backup_dir_throws ) {
			throw new MainWP_Exception( 'Unable to connect to the filesystem.' );
		}
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
		if ( null !== $this->before_loopback ) {
			call_user_func( $this->before_loopback );
		}
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

	public $cache_flushes = 0;

	public $yield_before_url_rewrite = false;

	protected function run_rewriting_urls( $task, $version, $sub_state, $deadline, $backup_dir ) {
		if ( $this->throw_url_rewrite ) {
			throw new MainWP_Exception( 'rewrite fixture failed', null, MainWP_Backup_Error::ERR_RESTORE_DB_REPLAY );
		}
		if ( $this->yield_before_url_rewrite ) {
			$this->yield_before_url_rewrite = false;
			return $this->checkpoint_sub_state( $task, $version, $sub_state, $backup_dir, true );
		}
		return parent::run_rewriting_urls( $task, $version, $sub_state, $deadline, $backup_dir );
	}

	/**
	 * Track cache flushes without flushing the test environment.
	 */
	protected function flush_caches() {
		++$this->cache_flushes;
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
 * Pause real terminal paths before cleanup while another actor runs.
 */
class Dump_Sweep_Restore_Runner extends Testable_Restore_Task_Runner {
	public $before_terminal_cleanup;
	public $before_external_cleanup;

	protected function terminal_cleanup( $task, $sub_state, $backup_dir, $owner = '' ) {
		if ( null !== $this->before_terminal_cleanup ) {
			$pause = $this->before_terminal_cleanup;
			$this->before_terminal_cleanup = null;
			$pause( $task );
		}
		return parent::terminal_cleanup( $task, $sub_state, $backup_dir, $owner );
	}

	public function cleanup_terminal_task( $task ) {
		if ( null !== $this->before_external_cleanup ) {
			$pause = $this->before_external_cleanup;
			$this->before_external_cleanup = null;
			$pause( $task );
		}
		parent::cleanup_terminal_task( $task );
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
/**
 * Captures the config the runner hands the URL rewriter, without running a rewrite.
 */
class Recording_Rewriter_Restore_Task_Runner extends Testable_Restore_Task_Runner {

	/**
	 * Config from the last rewrite_slice() call.
	 *
	 * @var array|null
	 */
	public $last_rewrite_config = null;

	/**
	 * Recording double.
	 *
	 * @return object
	 */
	protected function url_rewriter() {
		$runner = $this;
		return new class( $runner ) {
			/**
			 * Owning runner.
			 *
			 * @var Recording_Rewriter_Restore_Task_Runner
			 */
			private $runner;

			/**
			 * Constructor.
			 *
			 * @param Recording_Rewriter_Restore_Task_Runner $runner Owning runner.
			 */
			public function __construct( $runner ) {
				$this->runner = $runner;
			}

			/**
			 * Record and report the table set as finished.
			 *
			 * @param array $cursor   Resume cursor.
			 * @param array $config   Rewrite config.
			 * @param float $deadline Slice deadline.
			 *
			 * @return array
			 */
			public function rewrite_slice( $cursor, $config, $deadline ) {
				$this->runner->last_rewrite_config = $config;
				return array( 'done' => true, 'cursor' => $cursor, 'failed_updates' => 0, 'last_error' => '' );
			}
		};
	}
}

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

	protected $identity_fixture_tables = array();
	protected $reconcile_fixture_root;

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
		$this->runner->test_content_roots = array( $this->site . '/wp-content', $this->site . '/wp-content/uploads' );
		$this->runner->fake_engine     = new Fake_Restore_Engine();

		$this->spine = MainWP_Child_Backup_Task::instance();
		remove_filter( 'query', array( $this, '_create_temporary_tables' ) );
		remove_filter( 'query', array( $this, '_drop_temporary_tables' ) );
		try {
			delete_option( MainWP_Child_Backup_Task::DB_VERSION_OPTION );
			$this->spine->install();
		} finally {
			add_filter( 'query', array( $this, '_create_temporary_tables' ) );
			add_filter( 'query', array( $this, '_drop_temporary_tables' ) );
		}

		// The pinned target prefix is rt_; the rewrite phase pages this
		// table for real.
		global $wpdb;
		$wpdb->query( 'CREATE TABLE IF NOT EXISTS `rt_items` (id INT NOT NULL, val TEXT, PRIMARY KEY (id))' ); // phpcs:ignore WordPress.DB -- test fixture.
		$wpdb->query( 'CREATE TABLE IF NOT EXISTS `rt_options` (option_id BIGINT NOT NULL AUTO_INCREMENT, option_name VARCHAR(191) NOT NULL, option_value LONGTEXT, autoload VARCHAR(20) DEFAULT \'yes\', PRIMARY KEY (option_id), UNIQUE KEY option_name (option_name))' );
	}

	/**
	 * Cleanup.
	 */
	public function tear_down() {
		global $wpdb;
		$wpdb->query( 'DROP TABLE IF EXISTS `rt_items`' ); // phpcs:ignore WordPress.DB -- test cleanup.
		foreach ( array( 'rt_options', 'rt_users', 'rt_usermeta', 'rt_postmeta', 'rt_wc_orders', 'rt_wc_product_meta_lookup', 'rt_actionscheduler_logs', 'rt_actionscheduler_actions', 'rt_wc_order_product_lookup', 'rt_wc_order_stats', 'rt_wc_order_tax_lookup', 'rt_wc_order_coupon_lookup', 'rt_wc_customer_lookup' ) as $woo_table ) {
			$wpdb->query( 'DROP TABLE IF EXISTS `' . $woo_table . '`' ); // phpcs:ignore WordPress.DB -- test cleanup.
		}
		foreach ( $this->identity_fixture_tables as $table ) {
			$wpdb->query( 'DROP TABLE IF EXISTS `' . $table . '`' );
		}
		$this->rrmdir( $this->dir );
		if ( $this->reconcile_fixture_root ) {
			$this->rrmdir( $this->reconcile_fixture_root );
		}
		parent::tear_down();
		remove_filter( 'query', array( $this, '_create_temporary_tables' ) );
		remove_filter( 'query', array( $this, '_drop_temporary_tables' ) );
		// install() skips schema creation while the version option still says the
		// schema is current, so dropping the table without clearing the option
		// leaves any later test that calls create_task() inserting into nothing.
		delete_option( MainWP_Child_Backup_Task::DB_VERSION_OPTION );
		$wpdb->query( 'DROP TABLE IF EXISTS ' . $this->spine->get_table_name() );
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
				// Unique per task: the spine now keys a replayed create on restore_id.
				'restore_id'       => 'rrun' . substr( md5( uniqid( '', true ) ), 0, 8 ),
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


	protected function prepare_reconcile_fixture() {
		$this->reconcile_fixture_root = $this->dir;
		$this->dir .= '/backups';
		mkdir( $this->dir );
		$this->runner->test_backup_dir = $this->dir;
		mkdir( $this->site . '/wp-content/plugins', 0777, true );
		file_put_contents( $this->site . '/wp-content/plugins/persist.php', 'extra' );
	}

	protected function make_manifest_artifact( $paths ) {
		sort( $paths, SORT_STRING );
		$bytes = '';
		foreach ( $paths as $path ) {
			$bytes .= $path . "\t1\t1\n";
		}
		$path = $this->dir . '/s1-files.list';
		file_put_contents( $path, $bytes );
		return array( 'role' => 'manifest', 'file' => 's1-files.list', 'size' => strlen( $bytes ), 'sha256' => hash_file( 'sha256', $path ), 'adopt_only' => false );
	}

	protected function reconcile_options( $excludes = array() ) {
		return array( 'reconcile' => array( 'manifest' => 's1-files.list', 'walk' => array( 'excludes' => $excludes, 'excludenonwp' => 0, 'excludezip' => 0 ) ) );
	}

	protected function prepare_mu_plugins_fixture() {
		$this->prepare_reconcile_fixture();
		unlink( $this->site . '/wp-content/plugins/persist.php' );
		mkdir( $this->site . '/wp-content/mu-plugins' );
	}

	protected function mu_plugins_chain( $type = 'files', $with_manifest = true, $with_reconcile = null ) {
		$path = 'wp-content/mu-plugins/host.php';
		file_put_contents( $this->site . '/' . $path, 'live host bytes' );
		// stale.php goes first so it is live across later one-entry slices, where a recomputed set would capture it.
		$base = $this->make_named_zip_artifact( 'base.zip', array( 'wp-content/mu-plugins/stale.php' => 'stale base bytes', $path => 'base bytes', 'snapshot.txt' => 'snapshot', 'wp-content/mu-plugins/snapshot.php' => 'base snapshot' ) );
		$delta = $this->make_named_zip_artifact( 'delta.zip', array( 'snapshot.txt' => 'leaf bytes', 'wp-content/mu-plugins/snapshot.php' => 'leaf snapshot' ) );
		$tombstones = $this->make_named_tombstones_artifact( 'leaf-tombstones.list', $path . "\nwp-content/mu-plugins/stale.php\n" );
		$artifacts = array( $base, $tombstones, $delta );
		if ( $with_manifest ) {
			$artifacts[] = $this->make_manifest_artifact( array( 'snapshot.txt', 'wp-content/mu-plugins/snapshot.php' ) );
		}
		if ( 'full' === $type ) {
			$artifacts[] = $this->make_db_artifact();
		}
		return $this->make_task( $type, $artifacts, ( null === $with_reconcile ? $with_manifest : $with_reconcile ) ? $this->reconcile_options() : array() );
	}

	protected function advance_until( $task_id, $predicate ) {
		$task = $this->spine->get_task( $task_id );
		for ( $i = 0; $i < 25; ++$i ) {
			$task = $this->runner->advance_one_slice( $task, 'dashboard' );
			$sub = $this->spine->read_sub_state( $task, $this->dir );
			if ( $predicate( $sub, $task ) ) {
				return array( $task, $sub );
			}
			if ( MainWP_Child_Backup_Task::is_terminal_phase( $task->phase ) ) {
				break;
			}
		}
		$this->fail( 'The requested restore checkpoint was not reached.' );
	}

	protected function slice_one_file_at_a_time() {
		$this->runner->test_file_restorer = new class extends \MainWP\Child\MainWP_Child_Backup_File_Restorer {
			public function restore_slice( $cursor, $config, $deadline ) {
				return parent::restore_slice( $cursor, $config, microtime( true ) - 1 );
			}
		};
	}

	private function file_link( $target, $path ) {
		if ( ! function_exists( 'symlink' ) || ! @symlink( $target, $path ) ) {
			$this->markTestSkipped( 'Filesystem does not support symlinks.' );
		}
	}

	public function test_file_symlink_reconcile_counts_leaves_and_silently_keeps_linked_ancestors() {
		$this->prepare_reconcile_fixture();
		$paths = array();
		for ( $i = 0; $i < 25; ++$i ) {
			$path = sprintf( 'wp-content/plugins/link-%02d.php', $i );
			$this->file_link( $this->dir . '/missing', $this->site . '/' . $path );
			$paths[] = $path;
		}
		mkdir( $this->site . '/real-dir' );
		file_put_contents( $this->site . '/real-dir/leaf.php', 'live bytes' );
		$this->file_link( 'missing', $this->site . '/real-dir/link.php' );
		$this->file_link( 'real-dir', $this->site . '/alias' );
		try {
			$manifest = $this->make_manifest_artifact( array( 'snapshot.txt', 'real-dir/leaf.php', 'real-dir/link.php' ) );
			$this->runner->test_reconciler = new class extends MainWP_Child_Backup_Reconciler {
				private $checks = 0;
				public function reconcile_slice( $cursor, $config, $deadline ) {
					$this->checks = 0;
					return parent::reconcile_slice( $cursor, $config, $deadline );
				}
				protected function budget_exhausted( $deadline ) { return ++$this->checks > 2; }
			};
			$task_id = $this->make_task( 'files', array( $this->make_named_zip_artifact( 'snapshot.zip', array( 'snapshot.txt' => 'snapshot' ) ), $manifest ), $this->reconcile_options() );
			list( $task, $sub ) = $this->advance_until( $task_id, static function ( $sub ) { return ! empty( $sub['reconcile']['kept'] ); } );
			$this->assertGreaterThan( 0, $sub['reconcile']['kept'] );
			$this->runner->test_reconciler = null;
			$task = $this->drive( $task_id );
			$sub = $this->spine->read_sub_state( $task, $this->dir );
			$this->assertSame( MainWP_Child_Backup_Task::PHASE_COMPLETED, $task->phase );
			$this->assertSame( 25, $sub['verification']['reconcile']['kept'] ?? null );
			$this->assertSame( array( 'symlink' => 25 ), $sub['verification']['reconcile']['kept_by_reason'] ?? null );
			$this->assertSame( array_slice( $paths, 0, 20 ), $sub['verification']['reconcile']['kept_paths'] ?? null );
			$this->assertSame( 'live bytes', file_get_contents( $this->site . '/alias/leaf.php' ) );
			foreach ( $paths as $path ) { $this->assertTrue( is_link( $this->site . '/' . $path ) ); }
		} finally {
			unlink( $this->site . '/alias' );
		}
	}

	public static function file_symlink_reconcile_modes() {
		return array( 'reconcile' => array( 'done' ), 'no manifest' => array( 'no_manifest' ), 'withheld' => array( 'unarchived_overflow' ) );
	}

	/** @dataProvider file_symlink_reconcile_modes */
	public function test_file_symlink_kept_reporting_merges_across_slices_and_operations( $mode ) {
		$this->prepare_mu_plugins_fixture();
		file_put_contents( $this->site . '/wp-content/mu-plugins/host.php', 'host mu-plugin' );
		file_put_contents( $this->site . '/wp-content/plugins/unarchived.php', 'unarchived bytes' );
		mkdir( $this->site . '/links' );
		$paths = array();
		$entries = array();
		file_put_contents( $this->dir . '/outside.php', 'host bytes' );
		for ( $i = 0; $i < 23; ++$i ) {
			$path = sprintf( 'links/file-%02d.php', $i );
			$this->file_link( $this->dir . '/outside.php', $this->site . '/' . $path );
			$paths[] = $path;
			$entries[ $path ] = 'archive bytes';
		}
		$this->file_link( 'missing', $this->site . '/links/tombstone-only.php' );
		$entries['snapshot.txt'] = 'snapshot';
		$artifacts = array(
			$this->make_named_zip_artifact( 'base-links.zip', $entries ),
			$this->make_named_tombstones_artifact( 'link-tombstones.list', implode( "\n", array_merge( $paths, array( 'links/tombstone-only.php', $paths[22] ) ) ) . "\n" ),
			$this->make_named_zip_artifact( 'leaf-links.zip', array( './' . $paths[22] => 'leaf bytes', 'snapshot.txt' => 'leaf' ) ),
		);
		$options = array();
		if ( 'no_manifest' !== $mode ) {
			$artifacts[] = $this->make_manifest_artifact( array( 'snapshot.txt' ) );
			$options = $this->reconcile_options();
			if ( 'done' === $mode ) { $options['reconcile']['walk']['unarchived'] = array( 'wp-content/plugins/unarchived.php' ); }
			if ( 'done' !== $mode ) { $options['reconcile']['walk']['unarchived_overflow'] = true; }
		}
		$this->slice_one_file_at_a_time();
		$task_id = $this->make_task( 'files', $artifacts, $options );
		list( $task, $sub ) = $this->advance_until( $task_id, static function ( $sub ) {
			return ! empty( $sub['file_restore']['part_cursor']['kept_symlinks'] );
		} );
		$this->assertGreaterThan( 0, $sub['file_restore']['part_cursor']['kept_symlinks'] );
		$this->assertTrue( is_link( $this->site . '/' . $paths[0] ) );
		list( $task, $sub ) = $this->advance_until( $task_id, static function ( $sub ) {
			return 2 === ( $sub['file_restore']['op_index'] ?? -1 ) && 1 === ( $sub['file_restore']['part_cursor']['entry_index'] ?? -1 );
		} );
		$this->assertSame( 24, $sub['file_restore']['part_cursor']['kept_symlinks'] ?? null );
		$this->assertSame( array_slice( $paths, 0, 20 ), $sub['file_restore']['part_cursor']['kept_symlink_paths'] ?? null );
		$this->runner->test_file_restorer = null;
		$task = $this->drive( $task_id );
		$sub = $this->spine->read_sub_state( $task, $this->dir );
		$this->assertSame( MainWP_Child_Backup_Task::PHASE_COMPLETED, $task->phase );
		$kept = $sub['verification']['reconcile'];
		$this->assertSame( 'done' === $mode ? 'done' : 'skipped', $kept['status'] );
		if ( 'done' !== $mode ) { $this->assertSame( $mode, $kept['reason'] ); }
		$this->assertSame( 'done' === $mode ? 26 : 24, $kept['kept'] ?? null );
		$this->assertEquals( 'done' === $mode ? array( 'mu_plugins' => 1, 'unarchived' => 1, 'symlink' => 24 ) : array( 'symlink' => 24 ), $kept['kept_by_reason'] ?? null );
		$this->assertSame( array_slice( $paths, 0, 20 ), $kept['kept_paths'] ?? null );
		$this->assertSame( 24, $sub['file_restore']['part_cursor']['kept_symlinks'] ?? null );
		$this->assertSame( 'host bytes', file_get_contents( $this->dir . '/outside.php' ) );
		$this->assertSame( 'leaf', file_get_contents( $this->site . '/snapshot.txt' ) );
		$this->assertSame( 'host mu-plugin', file_get_contents( $this->site . '/wp-content/mu-plugins/host.php' ) );
		$this->assertSame( 'unarchived bytes', file_get_contents( $this->site . '/wp-content/plugins/unarchived.php' ) );
		foreach ( array_merge( $paths, array( 'links/tombstone-only.php' ) ) as $path ) {
			$this->assertTrue( is_link( $this->site . '/' . $path ) );
		}
		$payload = $this->spine->status_payload( $task, $this->dir );
		$this->assertSame( $kept, $payload['sub_state']['verification']['reconcile'] );
		$this->assertArrayNotHasKey( 'file_restore', $payload['sub_state'] );
		$this->assertStringNotContainsString( $this->site, wp_json_encode( $kept ) );
		$this->assertStringNotContainsString( $this->dir . '/outside.php', wp_json_encode( $kept ) );
	}

	public function test_file_symlink_prefixed_base_diff_tombstone_keeps_live_link() {
		$this->prepare_reconcile_fixture();
		file_put_contents( $this->site . '/target.php', 'live target' );
		$this->file_link( 'target.php', $this->site . '/link.php' );
		$base = $this->make_named_zip_artifact( 'old-base.zip', array( 'link.php' => 'old target', 'target.php' => 'old target' ) );
		$parent = $this->dir . '/parent.list';
		file_put_contents( $parent, "link.php\t10\t1\ntarget.php\t10\t1\n" );
		$walker = new \MainWP\Child\MainWP_Child_Backup_File_Walker( $this->site );
		$walker->emit_sidecars( $this->dir );
		$diff = new \MainWP\Child\MainWP_Child_Backup_Incremental_Diff();
		$counts = $diff->stream_diff( $this->dir . '/files.list.staged', $diff->load_parent_map( $parent )['map'], $this->dir . '/changes.list', $this->dir . '/diff-tombstones.list' );
		$this->assertSame( 1, $counts['tombstones'] );
		$this->assertSame( "link.php\n", file_get_contents( $this->dir . '/diff-tombstones.list' ) );
		$tomb = $this->make_named_tombstones_artifact( 'leaf-tombstones.list', file_get_contents( $this->dir . '/diff-tombstones.list' ) );
		$leaf = $this->make_named_zip_artifact( 'leaf.zip', array( 'target.php' => 'leaf target' ) );
		$task = $this->drive( $this->make_task( 'files', array( $base, $tomb, $leaf, $this->make_manifest_artifact( array( 'target.php' ) ) ), $this->reconcile_options() ) );
		$sub = $this->spine->read_sub_state( $task, $this->dir );
		$this->assertSame( MainWP_Child_Backup_Task::PHASE_COMPLETED, $task->phase );
		$this->assertTrue( is_link( $this->site . '/link.php' ) );
		$this->assertSame( 'leaf target', file_get_contents( $this->site . '/target.php' ) );
		$this->assertSame( 1, $sub['verification']['reconcile']['kept'] ?? null );
		$this->assertSame( array( 'symlink' => 1 ), $sub['verification']['reconcile']['kept_by_reason'] ?? null );
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_file_symlink_wp_fail2ban_backup_and_full_restore() {
		$this->prepare_mu_plugins_fixture();
		define( 'MainWP\\Child\\ABSPATH', $this->site . '/' );
		$plugin = 'wp-content/plugins/wp-fail2ban/wp-fail2ban.php';
		$constants = 'wp-content/plugins/wp-fail2ban/constants.php';
		$link = 'wp-content/mu-plugins/wp-fail2ban.php';
		mkdir( dirname( $this->site . '/' . $plugin ) );
		$code = "<?php require_once __DIR__ . '/constants.php'; return 'loaded';";
		file_put_contents( $this->site . '/' . $plugin, $code );
		file_put_contents( $this->site . '/' . $constants, '<?php /* plugin constants */' );
		file_put_contents( $this->site . '/wp-content/mu-plugins/host.php', 'host mu-plugin' );
		$this->file_link( '../plugins/wp-fail2ban/wp-fail2ban.php', $this->site . '/' . $link );
		$backup = new class extends \MainWP\Child\MainWP_Backup {
			public function extend_archive_mutex_lease() {}
			public function get_or_create_db_dump_files( $filepath_prefix, &$archiver = null ) { return array(); }
		};
		$this->assertTrue( $backup->create_zip_full_backup( $this->dir . '/fail2ban.zip', array(), false, true, false, false ) );
		$zip = new ZipArchive();
		$this->assertTrue( $zip->open( $this->dir . '/fail2ban.zip' ) );
		$this->assertFalse( $zip->locateName( $link ) );
		$this->assertSame( $code, $zip->getFromName( $plugin ) );
		$zip->close();
		$walker = new \MainWP\Child\MainWP_Child_Backup_File_Walker( $this->site );
		$walker->emit_sidecars( $this->dir );
		$this->assertStringNotContainsString( $link . "\t", file_get_contents( $this->dir . '/files.list.staged' ) );
		$archive = array( 'role' => 'full', 'file' => 'fail2ban.zip', 'size' => filesize( $this->dir . '/fail2ban.zip' ), 'sha256' => hash_file( 'sha256', $this->dir . '/fail2ban.zip' ), 'adopt_only' => false );
		unlink( $this->site . '/' . $constants );
		file_put_contents( $this->site . '/wp-content/mu-plugins/host.php', 'live host mu-plugin' );
		$task = $this->drive( $this->make_task( 'full', array( $archive, $this->make_db_artifact(), $this->make_manifest_artifact( array( $plugin, $constants ) ) ), $this->reconcile_options() ) );
		$sub = $this->spine->read_sub_state( $task, $this->dir );
		$this->assertSame( MainWP_Child_Backup_Task::PHASE_COMPLETED, $task->phase );
		$this->assertTrue( is_link( $this->site . '/' . $link ) );
		$this->assertSame( '<?php /* plugin constants */', file_get_contents( $this->site . '/' . $constants ) );
		$this->assertSame( 'loaded', require $this->site . '/' . $link );
		$this->assertSame( 'live host mu-plugin', file_get_contents( $this->site . '/wp-content/mu-plugins/host.php' ) );
		$this->assertArrayNotHasKey( $link, $sub['mu_plugins_preserve']['paths'] );
		$this->assertSame( array( 'mu_plugins' => 1, 'symlink' => 1 ), $sub['verification']['reconcile']['kept_by_reason'] ?? null );
	}

	public function test_file_symlink_absent_on_different_host_is_not_created() {
		$this->prepare_mu_plugins_fixture();
		$target = 'wp-content/plugins/wp-fail2ban/wp-fail2ban.php';
		$link = 'wp-content/mu-plugins/wp-fail2ban.php';
		$archive = $this->make_named_zip_artifact( 'post-fix.zip', array( $target => 'target bytes' ) );
		$task = $this->drive( $this->make_task( 'files', array( $archive, $this->make_manifest_artifact( array( $target ) ) ), $this->reconcile_options() ) );
		$sub = $this->spine->read_sub_state( $task, $this->dir );
		$this->assertSame( MainWP_Child_Backup_Task::PHASE_COMPLETED, $task->phase );
		$this->assertFileDoesNotExist( $this->site . '/' . $link );
		$this->assertFalse( is_link( $this->site . '/' . $link ) );
		$this->assertSame( 'target bytes', file_get_contents( $this->site . '/' . $target ) );
		$this->assertSame( 0, $sub['verification']['reconcile']['removed'] );
		$this->assertArrayNotHasKey( 'kept', $sub['verification']['reconcile'] );
	}

	public function test_mu_plugins_full_restore_keeps_old_live_file_and_lists_it() {
		$this->prepare_mu_plugins_fixture();
		$path = 'wp-content/mu-plugins/Host..php';
		file_put_contents( $this->site . '/' . $path, 'old live bytes' );
		touch( $this->site . '/' . $path, 1 );
		$task = $this->drive( $this->make_task( 'full', array( $this->make_part_artifact(), $this->make_db_artifact(), $this->make_manifest_artifact( array( 'wp-content/uploads/restored.txt' ) ) ), $this->reconcile_options() ) );
		$sub = $this->spine->read_sub_state( $task, $this->dir );
		$this->assertSame( MainWP_Child_Backup_Task::PHASE_COMPLETED, $task->phase );
		$this->assertFileExists( $this->site . '/' . $path );
		$this->assertSame( 'old live bytes', file_get_contents( $this->site . '/' . $path ) );
		$this->assertSame( 1, $sub['verification']['reconcile']['kept'] );
		$this->assertSame( array( 'mu_plugins' => 1 ), $sub['verification']['reconcile']['kept_by_reason'] );
		$this->assertSame( array( $path ), $sub['verification']['reconcile']['kept_paths'] );
	}

	public static function data_mu_plugins_restore_types() {
		return array( 'files' => array( 'files' ), 'full' => array( 'full' ) );
	}

	/** @dataProvider data_mu_plugins_restore_types */
	public function test_mu_plugins_incremental_recreation_keeps_live_bytes( $type ) {
		$this->prepare_mu_plugins_fixture();
		$task = $this->drive( $this->mu_plugins_chain( $type ) );
		$this->assertSame( MainWP_Child_Backup_Task::PHASE_COMPLETED, $task->phase );
		$this->assertFileExists( $this->site . '/wp-content/mu-plugins/host.php' );
		$this->assertSame( 'live host bytes', file_get_contents( $this->site . '/wp-content/mu-plugins/host.php' ) );
		$this->assertSame( 'leaf bytes', file_get_contents( $this->site . '/snapshot.txt' ) );
		$this->assertSame( 'leaf snapshot', file_get_contents( $this->site . '/wp-content/mu-plugins/snapshot.php' ) );
	}

	public function test_mu_plugins_leaf_manifest_restores_snapshot_and_sibling_is_deleted() {
		$this->prepare_mu_plugins_fixture();
		$path = 'wp-content/mu-plugins/snapshot.php';
		file_put_contents( $this->site . '/' . $path, 'live edit' );
		mkdir( $this->site . '/wp-content/mu-plugins-old' );
		file_put_contents( $this->site . '/wp-content/mu-plugins-old/extra.php', 'delete' );
		$task = $this->drive( $this->make_task( 'files', array( $this->make_named_zip_artifact( 'snapshot.zip', array( $path => 'snapshot bytes' ) ), $this->make_manifest_artifact( array( $path ) ) ), $this->reconcile_options() ) );
		$sub = $this->spine->read_sub_state( $task, $this->dir );
		$this->assertSame( MainWP_Child_Backup_Task::PHASE_COMPLETED, $task->phase );
		$this->assertSame( 'snapshot bytes', file_get_contents( $this->site . '/' . $path ) );
		$this->assertFileDoesNotExist( $this->site . '/wp-content/mu-plugins-old/extra.php' );
		foreach ( array( 'kept', 'kept_by_reason', 'kept_paths' ) as $key ) {
			$this->assertArrayNotHasKey( $key, $sub['verification']['reconcile'] );
		}
	}

	public function test_mu_plugins_directory_containment_and_symlinks() {
		$helper = '\\MainWP\\Child\\MainWP_Child_Backup_Mu_Plugins';
		$this->assertSame( 'custom/mu', $helper::relative_directory( $this->site, $this->site . '/custom/./mu/' ) );
		$this->assertNull( $helper::relative_directory( $this->site, $this->site . '-other/mu' ) );
		$this->assertNull( $helper::relative_directory( $this->site, $this->site . '/../outside' ) );
		$this->assertNull( $helper::relative_directory( $this->site, $this->site . '/./' ) );
		$this->assertFalse( $helper::contains( 'wp-content/plugins/extra.php', '' ) );
		$this->assertSame( $helper::relative_directory( $this->site, defined( 'WPMU_PLUGIN_DIR' ) ? WPMU_PLUGIN_DIR : WP_CONTENT_DIR . '/mu-plugins' ), $helper::relative_directory( $this->site ) );
		mkdir( $this->site . '/custom' );
		file_put_contents( $this->site . '/custom/host.php', 'symlink target' );
		symlink( $this->site . '/custom', $this->site . '/linked-mu' );
		try {
			$this->assertNull( $helper::relative_directory( $this->site, $this->site . '/linked-mu' ) );
			$manifest = $this->make_manifest_artifact( array( 'snapshot.txt' ) );
			symlink( $this->site . '/custom/host.php', $this->site . '/custom/linked.php' );
			symlink( $this->site, $this->site . '/custom/linked-dir' );
			try {
				$policy = new $helper();
				$set = $policy->capture( $this->site, $this->site . '/custom', $this->dir . '/' . $manifest['file'], false, 500 );
				$this->assertSame( array( 'custom/host.php' => true ), $set['paths'] );
				$this->assertTrue( $helper::preserves( 'custom/host.php', $set ) );
				$this->assertFalse( $helper::preserves( 'custom-old/host.php', $set ) );
				$this->assertSame( array(), $policy->capture( $this->site, $this->site . '/linked-mu', $this->dir . '/' . $manifest['file'], false, 500 )['paths'] );
			} finally {
				unlink( $this->site . '/custom/linked.php' );
				unlink( $this->site . '/custom/linked-dir' );
			}
		} finally {
			unlink( $this->site . '/linked-mu' );
		}
	}

	public function test_mu_plugins_cap_preserves_absent_entries_but_restores_leaf_entries() {
		$this->prepare_mu_plugins_fixture();
		$this->runner->mu_plugins_cap = 1;
		$task_id = $this->mu_plugins_chain();
		file_put_contents( $this->site . '/wp-content/mu-plugins/second.php', 'second live' );
		$task = $this->drive( $task_id );
		$sub = $this->spine->read_sub_state( $task, $this->dir );
		$this->assertFileExists( $this->site . '/wp-content/mu-plugins/host.php' );
		$this->assertSame( 'live host bytes', file_get_contents( $this->site . '/wp-content/mu-plugins/host.php' ) );
		$this->assertSame( 'second live', file_get_contents( $this->site . '/wp-content/mu-plugins/second.php' ) );
		$this->assertSame( 'whole_dir', $sub['mu_plugins_preserve']['mode'] );
		$this->assertArrayNotHasKey( 'paths', $sub['mu_plugins_preserve'] );
		$this->assertSame( 'mu_plugins_cap', $sub['verification']['reconcile']['mu_plugins_reason'] );
		$this->assertSame( 2, $sub['verification']['reconcile']['kept'] );
		$this->assertSame( 'leaf snapshot', file_get_contents( $this->site . '/wp-content/mu-plugins/snapshot.php' ) );
	}

	public function test_mu_plugins_uploads_are_not_counted_as_kept() {
		$this->prepare_mu_plugins_fixture();
		mkdir( $this->site . '/wp-content/uploads' );
		file_put_contents( $this->site . '/wp-content/uploads/live.jpg', 'upload' );
		$filter = function ( $uploads ) {
			$uploads['basedir'] = $this->site . '/wp-content/uploads';
			return $uploads;
		};
		add_filter( 'upload_dir', $filter );
		try {
			$task = $this->drive( $this->make_task( 'files', array( $this->make_part_artifact(), $this->make_manifest_artifact( array( 'wp-content/uploads/restored.txt' ) ) ), $this->reconcile_options() ) );
			$sub = $this->spine->read_sub_state( $task, $this->dir );
			$this->assertFileExists( $this->site . '/wp-content/uploads/live.jpg' );
			foreach ( array( 'kept', 'kept_by_reason', 'kept_paths' ) as $key ) {
				$this->assertArrayNotHasKey( $key, $sub['verification']['reconcile'] );
			}
		} finally {
			remove_filter( 'upload_dir', $filter );
		}
	}

	public function test_mu_plugins_preserve_set_is_durable_before_extraction_and_recomputed_after_crash() {
		$this->prepare_mu_plugins_fixture();
		$task_id = $this->mu_plugins_chain();
		$this->runner->mu_plugins_checkpoint_crash = true;
		list( $task, $sub ) = $this->advance_until( $task_id, function () { return ! $this->runner->mu_plugins_checkpoint_crash; } );
		$this->assertArrayNotHasKey( 'mu_plugins_preserve', $sub );
		$this->assertFileDoesNotExist( $this->site . '/snapshot.txt' );
		file_put_contents( $this->site . '/wp-content/mu-plugins/between.php', 'added after crash' );
		$task = $this->runner->advance_one_slice( $task, 'dashboard' );
		$sub = $this->spine->read_sub_state( $task, $this->dir );
		$this->assertArrayHasKey( 'wp-content/mu-plugins/between.php', $sub['mu_plugins_preserve']['paths'] );
		$this->assertFileDoesNotExist( $this->site . '/snapshot.txt' );
		$task = $this->drive( $task_id );
		$this->assertSame( MainWP_Child_Backup_Task::PHASE_COMPLETED, $task->phase );
	}

	public function test_mu_plugins_resume_mid_extraction_reuses_set_and_missing_set_falls_back() {
		$this->prepare_mu_plugins_fixture();
		$this->slice_one_file_at_a_time();
		$this->runner->legacy_file_checkpoints = true;
		$task_id = $this->mu_plugins_chain();
		list( $task, $sub ) = $this->advance_until( $task_id, static function ( $sub ) { return ! empty( $sub['file_restore']['part_cursor']['entry_index'] ); } );
		$this->assertArrayNotHasKey( 'mu_plugins_preserve', $sub );
		$this->assertSame( 'live host bytes', file_get_contents( $this->site . '/wp-content/mu-plugins/host.php' ) );
		$this->runner->legacy_file_checkpoints = false;
		$this->runner->test_file_restorer = null;
		$task = $this->drive( $task_id );
		$sub = $this->spine->read_sub_state( $task, $this->dir );
		$this->assertSame( MainWP_Child_Backup_Task::PHASE_COMPLETED, $task->phase );
		$this->assertFileExists( $this->site . '/wp-content/mu-plugins/host.php' );
		$this->assertSame( 'live host bytes', file_get_contents( $this->site . '/wp-content/mu-plugins/host.php' ) );
		$this->assertSame( 'mu_plugins_set_missing', $sub['verification']['reconcile']['mu_plugins_reason'] );
	}

	public function test_mu_plugins_resume_reuses_set_and_reconcile_counts_without_duplicates() {
		$this->prepare_mu_plugins_fixture();
		$this->slice_one_file_at_a_time();
		$task_id = $this->mu_plugins_chain();
		list( $task, $sub ) = $this->advance_until( $task_id, static function ( $sub ) { return ! empty( $sub['file_restore']['part_cursor']['entry_index'] ); } );
		$preserve = $sub['mu_plugins_preserve'];
		file_put_contents( $this->site . '/wp-content/mu-plugins/late.php', 'created during restore' );
		$this->runner->test_reconciler = new class extends MainWP_Child_Backup_Reconciler {
			public $checks = 0;
			public function reconcile_slice( $cursor, $config, $deadline ) {
				$this->checks = 0;
				return parent::reconcile_slice( $cursor, $config, $deadline );
			}
			protected function budget_exhausted( $deadline ) {
				return ++$this->checks > 3;
			}
		};
		list( $task, $sub ) = $this->advance_until( $task_id, static function ( $sub ) { return isset( $sub['reconcile']['kept'] ) && 1 === $sub['reconcile']['kept']; } );
		$this->assertSame( $preserve, $sub['mu_plugins_preserve'] );
		$this->assertSame( array( 'wp-content/mu-plugins/host.php' ), $sub['reconcile']['kept_paths'] );
		$this->runner->test_reconciler = null;
		$task = $this->drive( $task_id );
		$sub = $this->spine->read_sub_state( $task, $this->dir );
		$this->assertSame( MainWP_Child_Backup_Task::PHASE_COMPLETED, $task->phase );
		$this->assertSame( 2, $sub['verification']['reconcile']['kept'] );
		$this->assertSame( array( 'mu_plugins' => 2 ), $sub['verification']['reconcile']['kept_by_reason'] );
		$this->assertSame( array( 'wp-content/mu-plugins/host.php', 'wp-content/mu-plugins/late.php' ), $sub['verification']['reconcile']['kept_paths'] );
		$scratch = $this->dir . '/comparison/';
		mkdir( $scratch );
		$config = array(
			'manifest' => $this->dir . '/s1-files.list',
			'walk' => $this->reconcile_options()['reconcile']['walk'],
			'root' => $this->site,
			'backup_dir' => $this->dir,
			'scratch' => $scratch,
			'mu_plugins_directory' => $this->site . '/wp-content/mu-plugins',
			'progress_callback' => static function () {},
			'checkpoint_cursor' => static function () { return true; },
		);
		$engine = new MainWP_Child_Backup_Reconciler();
		$walk = $engine->reconcile_slice( array(), $config, time() + 60 );
		$uninterrupted = $engine->reconcile_slice( $walk['cursor'], $config, time() + 60 );
		$this->assertSame( $uninterrupted['outcome'], $sub['verification']['reconcile'] );
		$this->assertSame( 'live host bytes', file_get_contents( $this->site . '/wp-content/mu-plugins/host.php' ) );
		// A set recomputed after base extraction would capture stale.php and keep it past its tombstone.
		$this->assertFileDoesNotExist( $this->site . '/wp-content/mu-plugins/stale.php' );
	}

	public function test_mu_plugins_malformed_leaf_manifest_fails_before_extraction() {
		$this->prepare_mu_plugins_fixture();
		$path = 'wp-content/mu-plugins/host.php';
		file_put_contents( $this->site . '/' . $path, 'live host bytes' );
		$base = $this->make_named_zip_artifact( 'base.zip', array( $path => 'base bytes', 'snapshot.txt' => 'snapshot' ) );
		$tombstones = $this->make_named_tombstones_artifact( 'leaf-tombstones.list', $path . "\n" );
		$task = $this->drive( $this->make_task( 'files', array( $base, $tombstones, $this->make_manifest_artifact( array( 'snapshot.txt', "bad\tentry" ) ) ), $this->reconcile_options() ) );
		$sub = $this->spine->read_sub_state( $task, $this->dir );
		$this->assertSame( MainWP_Child_Backup_Task::PHASE_FAILED, $task->phase );
		$this->assertSame( MainWP_Backup_Error::ERR_RESTORE_RECONCILE, $sub['error']['code'] );
		$this->assertSame( 'live host bytes', file_get_contents( $this->site . '/' . $path ) );
		$this->assertFileDoesNotExist( $this->site . '/snapshot.txt' );
	}

	public static function data_mu_plugins_unverified_manifests() {
		return array( 'hash mismatch' => array( 'mismatch' ), 'missing file' => array( 'missing' ), 'missing digest' => array( 'no_digest' ) );
	}

	/** @dataProvider data_mu_plugins_unverified_manifests */
	public function test_mu_plugins_named_unverified_manifest_fails_before_extraction( $failure ) {
		$this->prepare_mu_plugins_fixture();
		$task_id = $this->mu_plugins_chain();
		$task = $this->spine->get_task( $task_id );
		$sub = $this->spine->read_sub_state( $task, $this->dir );
		$scratch = $this->runner->scratch_dir( $task_id, $this->dir );
		foreach ( $sub['params']['artifacts'] as &$artifact ) {
			copy( $this->dir . '/' . $artifact['file'], $scratch . $artifact['file'] );
			if ( 'manifest' === $artifact['role'] && 'no_digest' === $failure ) {
				$artifact['sha256'] = '';
			}
		}
		unset( $artifact );
		if ( 'missing' === $failure ) {
			unlink( $scratch . 's1-files.list' );
		} elseif ( 'mismatch' === $failure ) {
			file_put_contents( $scratch . 's1-files.list', "changed.php\t1\t1\n" );
		}
		$this->assertNotFalse( $this->spine->checkpoint( $task_id, (int) $task->version, array( 'phase' => MainWP_Child_Backup_Task::PHASE_VERIFYING_ARTIFACTS, 'sub_state' => $sub ) ) );
		$task = $this->drive( $task_id );
		$sub = $this->spine->read_sub_state( $task, $this->dir );
		$this->assertSame( MainWP_Child_Backup_Task::PHASE_FAILED, $task->phase );
		// The verifying slice must refuse on its own; the capture-time check would otherwise mask its absence.
		$this->assertSame( array( MainWP_Child_Backup_Task::PHASE_VERIFYING_ARTIFACTS ), $this->runner->slice_phases );
		$this->assertSame( MainWP_Backup_Error::ERR_RESTORE_RECONCILE, $sub['error']['code'] );
		$this->assertSame( 'mainwp_restore_reconcile', $this->spine->status_payload( $task, $this->dir )['error_code'] );
		$this->assertArrayNotHasKey( 'file_restore', $sub );
		$this->assertSame( 'live host bytes', file_get_contents( $this->site . '/wp-content/mu-plugins/host.php' ) );
		$this->assertFileDoesNotExist( $this->site . '/snapshot.txt' );
		$this->assertDirectoryDoesNotExist( $scratch );
		$this->assertEmpty( $task->active_slot );
	}

	public function test_mu_plugins_named_manifest_changed_before_capture_fails_before_extraction() {
		$this->prepare_mu_plugins_fixture();
		$task_id = $this->mu_plugins_chain();
		$this->runner->mu_plugins_checkpoint_crash = true;
		list( $task, $sub ) = $this->advance_until( $task_id, function () { return ! $this->runner->mu_plugins_checkpoint_crash; } );
		$this->assertArrayNotHasKey( 'mu_plugins_preserve', $sub );
		file_put_contents( $this->runner->scratch_dir( $task_id, $this->dir ) . 's1-files.list', "changed.php\t1\t1\n" );
		$task = $this->drive( $task_id );
		$sub = $this->spine->read_sub_state( $task, $this->dir );
		$this->assertSame( MainWP_Child_Backup_Task::PHASE_FAILED, $task->phase );
		$this->assertSame( MainWP_Backup_Error::ERR_RESTORE_RECONCILE, $sub['error']['code'] );
		$this->assertArrayNotHasKey( 'file_restore', $sub );
		$this->assertSame( 'live host bytes', file_get_contents( $this->site . '/wp-content/mu-plugins/host.php' ) );
		$this->assertFileDoesNotExist( $this->site . '/snapshot.txt' );
		$this->assertDirectoryDoesNotExist( $this->runner->scratch_dir( $task_id, $this->dir ) );
	}

	/** @dataProvider data_mu_plugins_restore_types */
	public function test_mu_plugins_no_manifest_chain_keeps_live_bytes_through_tombstones( $type ) {
		$this->prepare_mu_plugins_fixture();
		$task = $this->drive( $this->mu_plugins_chain( $type, false ) );
		$sub = $this->spine->read_sub_state( $task, $this->dir );
		$this->assertSame( MainWP_Child_Backup_Task::PHASE_COMPLETED, $task->phase );
		$this->assertFileExists( $this->site . '/wp-content/mu-plugins/host.php' );
		$this->assertSame( 'live host bytes', file_get_contents( $this->site . '/wp-content/mu-plugins/host.php' ) );
		$this->assertSame( array( 'status' => 'skipped', 'reason' => 'no_manifest', 'mu_plugins_reason' => 'mu_plugins_no_manifest' ), $sub['verification']['reconcile'] );
	}

	public function test_mu_plugins_withheld_reconcile_with_unverified_manifest_keeps_live_bytes() {
		$this->prepare_mu_plugins_fixture();
		$task_id = $this->mu_plugins_chain( 'files', true, false );
		file_put_contents( $this->site . '/wp-content/mu-plugins/snapshot.php', 'live snapshot bytes' );
		$task = $this->spine->get_task( $task_id );
		$sub = $this->spine->read_sub_state( $task, $this->dir );
		$scratch = $this->runner->scratch_dir( $task_id, $this->dir );
		foreach ( $sub['params']['artifacts'] as $artifact ) {
			copy( $this->dir . '/' . $artifact['file'], $scratch . $artifact['file'] );
		}
		file_put_contents( $scratch . 's1-files.list', "changed.php\t1\t1\n" );
		$this->assertNotFalse( $this->spine->checkpoint( $task_id, (int) $task->version, array( 'phase' => MainWP_Child_Backup_Task::PHASE_VERIFYING_ARTIFACTS, 'sub_state' => $sub ) ) );
		$task = $this->drive( $task_id );
		$sub = $this->spine->read_sub_state( $task, $this->dir );
		$this->assertSame( MainWP_Child_Backup_Task::PHASE_COMPLETED, $task->phase );
		$this->assertSame( 'live host bytes', file_get_contents( $this->site . '/wp-content/mu-plugins/host.php' ) );
		$this->assertSame( 'live snapshot bytes', file_get_contents( $this->site . '/wp-content/mu-plugins/snapshot.php' ) );
		$this->assertSame( 'mu_plugins_no_manifest', $sub['verification']['reconcile']['mu_plugins_reason'] );
	}

	/**
	 * Every input shape the capture policy accepts. A named manifest that fails verification refuses
	 * (data_mu_plugins_unverified_manifests); an unnamed sole manifest that fails falls back to the
	 * no-manifest policy (test_mu_plugins_withheld_reconcile_with_unverified_manifest_keeps_live_bytes).
	 */
	public static function data_mu_plugins_capture_policy() {
		return array(
			'chain, named verified manifest'              => array( 'chain', 'named', 1, 'r1' ),
			'chain, no block, sole verified manifest'     => array( 'chain', 'absent', 1, 'r1' ),
			'chain, empty block, sole verified manifest'  => array( 'chain', 'empty', 1, 'r1' ),
			'chain, no block, no manifest'                => array( 'chain', 'absent', 0, 'no_manifest' ),
			'chain, empty block, no manifest'             => array( 'chain', 'empty', 0, 'no_manifest' ),
			'chain, no block, two manifests'              => array( 'chain', 'absent', 2, 'no_manifest' ),
			'single, named verified manifest'             => array( 'single', 'named', 1, 'r1' ),
			'single, no block, sole verified manifest'    => array( 'single', 'absent', 1, 'overlay' ),
			'single, empty block, sole verified manifest' => array( 'single', 'empty', 1, 'overlay' ),
			'single, no block, no manifest'               => array( 'single', 'absent', 0, 'overlay' ),
			'single, empty block, no manifest'            => array( 'single', 'empty', 0, 'overlay' ),
		);
	}

	/** @dataProvider data_mu_plugins_capture_policy */
	public function test_mu_plugins_capture_policy_matrix( $shape, $block, $manifests, $expected ) {
		$this->prepare_mu_plugins_fixture();
		$mu = 'wp-content/mu-plugins/';
		file_put_contents( $this->site . '/' . $mu . 'host.php', 'live host bytes' );
		file_put_contents( $this->site . '/' . $mu . 'snapshot.php', 'live snapshot bytes' );
		if ( 'chain' === $shape ) {
			$artifacts = array(
				$this->make_named_zip_artifact( 'base.zip', array( $mu . 'host.php' => 'snapshot host', $mu . 'snapshot.php' => 'base snapshot' ) ),
				$this->make_named_tombstones_artifact( 'leaf-tombstones.list', $mu . "host.php\n" ),
				$this->make_named_zip_artifact( 'delta.zip', array( $mu . 'snapshot.php' => 'leaf snapshot' ) ),
			);
		} else {
			$artifacts = array( $this->make_named_zip_artifact( 'snapshot.zip', array( $mu . 'host.php' => 'snapshot host', $mu . 'snapshot.php' => 'leaf snapshot' ), 'full' ) );
		}
		if ( $manifests > 0 ) {
			$artifacts[] = $this->make_manifest_artifact( array( $mu . 'snapshot.php' ) );
		}
		if ( $manifests > 1 ) {
			copy( $this->dir . '/s1-files.list', $this->dir . '/s0-files.list' );
			$artifacts[] = array( 'role' => 'manifest', 'file' => 's0-files.list', 'size' => filesize( $this->dir . '/s0-files.list' ), 'sha256' => hash_file( 'sha256', $this->dir . '/s0-files.list' ), 'adopt_only' => false );
		}
		$blocks = array(
			'named'  => $this->reconcile_options(),
			'absent' => array(),
			'empty'  => array( 'reconcile' => array() ),
		);
		$task = $this->drive( $this->make_task( 'files', $artifacts, $blocks[ $block ] ) );
		$sub = $this->spine->read_sub_state( $task, $this->dir );
		$this->assertSame( MainWP_Child_Backup_Task::PHASE_COMPLETED, $task->phase );
		$policies = array(
			'r1'          => array( 'directory' => 'wp-content/mu-plugins', 'mode' => 'set', 'paths' => array( $mu . 'host.php' => true ) ),
			'no_manifest' => array( 'directory' => 'wp-content/mu-plugins', 'mode' => 'set', 'paths' => array( $mu . 'host.php' => true, $mu . 'snapshot.php' => true ), 'reason' => 'mu_plugins_no_manifest' ),
			'overlay'     => array( 'directory' => null, 'mode' => 'set', 'paths' => array() ),
		);
		$bytes = array(
			'r1'          => array( 'live host bytes', 'leaf snapshot' ),
			'no_manifest' => array( 'live host bytes', 'live snapshot bytes' ),
			'overlay'     => array( 'snapshot host', 'leaf snapshot' ),
		);
		// Directory iteration order decides the order of captured paths, so compare as sets.
		$this->assertEquals( $policies[ $expected ], $sub['mu_plugins_preserve'] );
		$this->assertSame( $bytes[ $expected ], array( file_get_contents( $this->site . '/' . $mu . 'host.php' ), file_get_contents( $this->site . '/' . $mu . 'snapshot.php' ) ) );
		$this->assertSame( 'no_manifest' === $expected, isset( $sub['verification']['reconcile']['mu_plugins_reason'] ) );
	}

	public function test_mu_plugins_no_manifest_non_live_paths_follow_archives_and_tombstones() {
		$this->prepare_mu_plugins_fixture();
		$task_id = $this->mu_plugins_chain( 'files', false );
		unlink( $this->site . '/wp-content/mu-plugins/host.php' );
		$task = $this->drive( $task_id );
		$this->assertSame( MainWP_Child_Backup_Task::PHASE_COMPLETED, $task->phase );
		$this->assertFileDoesNotExist( $this->site . '/wp-content/mu-plugins/host.php' );
		$this->assertFileDoesNotExist( $this->site . '/wp-content/mu-plugins/stale.php' );
		$this->assertSame( 'leaf snapshot', file_get_contents( $this->site . '/wp-content/mu-plugins/snapshot.php' ) );
		$this->assertSame( 'leaf bytes', file_get_contents( $this->site . '/snapshot.txt' ) );
	}

	public function test_mu_plugins_no_manifest_ambiguous_leaf_keeps_live_bytes_from_every_archive() {
		$this->prepare_mu_plugins_fixture();
		$task_id = $this->mu_plugins_chain( 'files', false );
		file_put_contents( $this->site . '/wp-content/mu-plugins/snapshot.php', 'live snapshot bytes' );
		$task = $this->drive( $task_id );
		$sub = $this->spine->read_sub_state( $task, $this->dir );
		$this->assertSame( MainWP_Child_Backup_Task::PHASE_COMPLETED, $task->phase );
		$this->assertSame( 'live snapshot bytes', file_get_contents( $this->site . '/wp-content/mu-plugins/snapshot.php' ) );
		$this->assertSame( 'leaf bytes', file_get_contents( $this->site . '/snapshot.txt' ) );
		$this->assertSame( 'mu_plugins_no_manifest', $sub['verification']['reconcile']['mu_plugins_reason'] );
	}

	/** @dataProvider data_mu_plugins_restore_types */
	public function test_mu_plugins_no_manifest_single_archive_restores_live_files_normally( $type ) {
		$this->prepare_mu_plugins_fixture();
		$path = 'wp-content/mu-plugins/host.php';
		file_put_contents( $this->site . '/' . $path, 'live host bytes' );
		$artifacts = array( $this->make_named_zip_artifact( 'snapshot.zip', array( $path => 'snapshot bytes' ), 'full' ) );
		if ( 'full' === $type ) {
			$artifacts[] = $this->make_db_artifact();
		}
		$task = $this->drive( $this->make_task( $type, $artifacts ) );
		$sub = $this->spine->read_sub_state( $task, $this->dir );
		$this->assertSame( MainWP_Child_Backup_Task::PHASE_COMPLETED, $task->phase );
		$this->assertSame( 'snapshot bytes', file_get_contents( $this->site . '/' . $path ) );
		$this->assertSame( array( 'status' => 'skipped', 'reason' => 'no_manifest' ), $sub['verification']['reconcile'] );
	}

	public function test_mu_plugins_no_manifest_resume_reuses_captured_live_set() {
		$this->prepare_mu_plugins_fixture();
		$this->slice_one_file_at_a_time();
		$task_id = $this->mu_plugins_chain( 'files', false );
		list( $task, $sub ) = $this->advance_until( $task_id, static function ( $sub ) { return ! empty( $sub['file_restore']['part_cursor']['entry_index'] ); } );
		$preserve = $sub['mu_plugins_preserve'];
		$this->assertSame( array( 'wp-content/mu-plugins/host.php' => true ), $preserve['paths'] );
		$this->assertSame( 'stale base bytes', file_get_contents( $this->site . '/wp-content/mu-plugins/stale.php' ) );
		$task = $this->drive( $task_id );
		$sub = $this->spine->read_sub_state( $task, $this->dir );
		$this->assertSame( MainWP_Child_Backup_Task::PHASE_COMPLETED, $task->phase );
		$this->assertSame( $preserve, $sub['mu_plugins_preserve'] );
		$this->assertFileDoesNotExist( $this->site . '/wp-content/mu-plugins/stale.php' );
		$this->assertSame( 'live host bytes', file_get_contents( $this->site . '/wp-content/mu-plugins/host.php' ) );
	}

	public function test_mu_plugins_no_manifest_cap_protects_every_live_file() {
		$this->prepare_mu_plugins_fixture();
		$entries = array();
		for ( $i = 0; $i < 501; ++$i ) {
			$file = sprintf( 'wp-content/mu-plugins/host-%03d.php', $i );
			$entries[ $file ] = 'base bytes';
			file_put_contents( $this->site . '/' . $file, 'live host bytes' );
		}
		$artifacts = array( $this->make_named_zip_artifact( 'base.zip', $entries ), $this->make_named_tombstones_artifact( 'leaf-tombstones.list', implode( "\n", array_keys( $entries ) ) . "\n" ) );
		$task = $this->drive( $this->make_task( 'files', $artifacts ) );
		$sub = $this->spine->read_sub_state( $task, $this->dir );
		$this->assertSame( MainWP_Child_Backup_Task::PHASE_COMPLETED, $task->phase );
		$this->assertSame( 'whole_dir', $sub['mu_plugins_preserve']['mode'] );
		$this->assertArrayNotHasKey( 'paths', $sub['mu_plugins_preserve'] );
		$this->assertSame( 'mu_plugins_cap', $sub['verification']['reconcile']['mu_plugins_reason'] );
		foreach ( array_keys( $entries ) as $file ) {
			$this->assertFileExists( $this->site . '/' . $file );
			$this->assertSame( 'live host bytes', file_get_contents( $this->site . '/' . $file ) );
		}
	}

	public function test_mu_plugins_no_manifest_single_archive_with_tombstones_keeps_live_bytes() {
		$this->prepare_mu_plugins_fixture();
		$path = 'wp-content/mu-plugins/host.php';
		file_put_contents( $this->site . '/' . $path, 'live host bytes' );
		$artifacts = array( $this->make_named_zip_artifact( 'base.zip', array( $path => 'base bytes' ) ), $this->make_named_tombstones_artifact( 'leaf-tombstones.list', $path . "\n" ) );
		$task = $this->drive( $this->make_task( 'files', $artifacts ) );
		$this->assertSame( MainWP_Child_Backup_Task::PHASE_COMPLETED, $task->phase );
		$this->assertFileExists( $this->site . '/' . $path );
		$this->assertSame( 'live host bytes', file_get_contents( $this->site . '/' . $path ) );
	}

	public function test_mu_plugins_rollback_without_manifest_remains_overlay_only() {
		$this->prepare_mu_plugins_fixture();
		$path = 'wp-content/mu-plugins/host.php';
		file_put_contents( $this->site . '/' . $path, 'live bytes' );
		$artifacts = array( $this->make_named_zip_artifact( 'rollback.zip', array( $path => 'rollback bytes' ) ), $this->make_db_artifact() );
		foreach ( $artifacts as &$artifact ) {
			$artifact['adopt_only'] = true;
		}
		unset( $artifact );
		$task = $this->drive( $this->make_task( 'full', $artifacts, array( 'file_rollback' => true ) ) );
		$sub = $this->spine->read_sub_state( $task, $this->dir );
		$this->assertSame( MainWP_Child_Backup_Task::PHASE_COMPLETED, $task->phase );
		$this->assertSame( 'rollback bytes', file_get_contents( $this->site . '/' . $path ) );
		$this->assertSame( array( 'status' => 'skipped', 'reason' => 'no_manifest' ), $sub['verification']['reconcile'] );
	}

	public function test_mu_plugins_kept_sample_is_limited_to_twenty() {
		$this->prepare_mu_plugins_fixture();
		$paths = array();
		for ( $i = 0; $i < 25; ++$i ) {
			$path = sprintf( 'wp-content/mu-plugins/host-%02d.php', $i );
			$paths[] = $path;
			file_put_contents( $this->site . '/' . $path, 'live bytes' );
		}
		$task = $this->drive( $this->make_task( 'files', array( $this->make_part_artifact(), $this->make_manifest_artifact( array( 'wp-content/uploads/restored.txt' ) ) ), $this->reconcile_options() ) );
		$sub = $this->spine->read_sub_state( $task, $this->dir );
		$this->assertSame( 25, $sub['verification']['reconcile']['kept'] );
		$this->assertSame( array_slice( $paths, 0, 20 ), $sub['verification']['reconcile']['kept_paths'] );
	}

	public function test_unarchived_full_restore_keeps_exact_live_file() {
		$this->prepare_reconcile_fixture();
		$path = 'wp-content/plugins/Host File..php';
		file_put_contents( $this->site . '/' . $path, 'live unarchived bytes' );
		file_put_contents( $this->site . '/' . $path . '.extra', 'delete sibling' );
		$options = $this->reconcile_options();
		$options['reconcile']['walk']['unarchived'] = array( $path, $path );
		$options['reconcile'] = MainWP_Child_Backup_Reconciler::sanitize_options( $options['reconcile'] );
		$task = $this->drive( $this->make_task( 'full', array( $this->make_part_artifact(), $this->make_db_artifact(), $this->make_manifest_artifact( array( 'wp-content/uploads/restored.txt' ) ) ), $options ) );
		$sub = $this->spine->read_sub_state( $task, $this->dir );
		$this->assertSame( MainWP_Child_Backup_Task::PHASE_COMPLETED, $task->phase );
		$this->assertFileExists( $this->site . '/' . $path );
		$this->assertSame( 'live unarchived bytes', file_get_contents( $this->site . '/' . $path ) );
		$this->assertFileDoesNotExist( $this->site . '/' . $path . '.extra' );
		$this->assertFileDoesNotExist( $this->site . '/wp-content/plugins/persist.php' );
		$this->assertSame( 2, $sub['verification']['reconcile']['removed'] );
		$this->assertSame( 1, $sub['verification']['reconcile']['kept'] );
		$this->assertSame( array( 'unarchived' => 1 ), $sub['verification']['reconcile']['kept_by_reason'] );
		$this->assertSame( array( $path ), $sub['verification']['reconcile']['kept_paths'] );
	}

	public function test_unarchived_sanitization_preserves_valid_list_and_is_idempotent() {
		$raw = $this->reconcile_options()['reconcile'];
		$raw['walk']['unarchived'] = array( 'wp-content/Host File..php', '.htaccess', 'wp-content/Host File..php' );
		$raw['walk']['unarchived_overflow'] = false;
		$clean = MainWP_Child_Backup_Reconciler::sanitize_options( $raw );
		$this->assertSame( $raw, $clean );
		$this->assertSame( $clean, MainWP_Child_Backup_Reconciler::sanitize_options( $clean ) );
	}

	public function test_unarchived_empty_object_on_wire_is_invalid() {
		$raw = json_decode( '{"manifest":"s1-files.list","walk":{"excludes":[],"excludenonwp":0,"excludezip":0,"unarchived":{}}}', true );
		$clean = MainWP_Child_Backup_Reconciler::sanitize_options( $raw );
		$this->assertSame( 'unarchived_invalid', $clean['skip_reason'] ?? null );
	}

	public static function data_unarchived_invalid_metadata() {
		$invalid = array(
			'parent segment' => array( 'a/../b' ),
			'dot segment' => array( 'wp-content/plugins/./persist.php' ),
			'empty segment' => array( 'wp-content//plugins/persist.php' ),
			'trailing slash' => array( 'wp-content/plugins/persist.php/' ),
			'absolute' => array( '/absolute.php' ),
			'windows drive' => array( 'C:/file.php' ),
			'backslash' => array( 'a\\b' ),
			'NUL' => array( "a\0b" ),
			'non-string entry' => array( 123 ),
			'empty entry' => array( '' ),
			'empty list' => array(),
			'trimmed entry' => array( ' path.php ' ),
			'HTML entry' => array( '<b>path.php</b>' ),
			'percent entry' => array( 'path%20name.php' ),
			'newline' => array( "a\nb" ),
			'object' => (object) array( 'file' => 'path.php' ),
			'map' => array( 'file' => 'path.php' ),
			'sparse list' => array( 1 => 'path.php' ),
			'scalar' => 'path.php',
			'null' => null,
		);
		$cases = array();
		foreach ( $invalid as $name => $value ) {
			$cases[ $name ] = array( array( 'unarchived' => $value ), 'unarchived_invalid' );
		}
		foreach ( array( 1, 0, 'true', 'false', null, array() ) as $i => $value ) {
			$cases[ 'invalid overflow ' . $i ] = array( array( 'unarchived_overflow' => $value ), 'unarchived_invalid' );
		}
		$cases['overflow'] = array( array( 'unarchived_overflow' => true ), 'unarchived_overflow' );
		$cases['overflow with list'] = array( array( 'unarchived_overflow' => true, 'unarchived' => array( 'wp-content/plugins/persist.php' ) ), 'unarchived_overflow' );
		$cases['invalid after valid'] = array( array( 'unarchived' => array( 'wp-content/plugins/persist.php', '../invalid' ) ), 'unarchived_invalid' );
		return $cases;
	}

	/** @dataProvider data_unarchived_invalid_metadata */
	public function test_unarchived_invalid_metadata_skips_without_deleting( $metadata, $reason ) {
		$this->prepare_reconcile_fixture();
		$options = $this->reconcile_options();
		$options['reconcile']['walk'] = array_merge( $options['reconcile']['walk'], $metadata );
		$clean = MainWP_Child_Backup_Reconciler::sanitize_options( $options['reconcile'] );
		$this->assertSame( $clean, MainWP_Child_Backup_Reconciler::sanitize_options( $clean ) );
		$options['reconcile'] = $clean;
		$task = $this->drive( $this->make_task( 'files', array( $this->make_part_artifact(), $this->make_manifest_artifact( array( 'wp-content/uploads/restored.txt' ) ) ), $options ) );
		$sub = $this->spine->read_sub_state( $task, $this->dir );
		$this->assertSame( MainWP_Child_Backup_Task::PHASE_COMPLETED, $task->phase );
		$this->assertSame( array( 'status' => 'skipped', 'reason' => $reason ), $sub['verification']['reconcile'] );
		$this->assertSame( 'extra', file_get_contents( $this->site . '/wp-content/plugins/persist.php' ) );
	}

	public function test_unarchived_resume_kept_counts_and_sample_are_not_duplicated() {
		$this->prepare_mu_plugins_fixture();
		$paths = array();
		for ( $i = 0; $i < 25; ++$i ) {
			$path = sprintf( 'wp-content/plugins/host-%02d.php', $i );
			$paths[] = $path;
			file_put_contents( $this->site . '/' . $path, 'live bytes' );
		}
		$mu_path = 'wp-content/mu-plugins/host.php';
		file_put_contents( $this->site . '/' . $mu_path, 'host live bytes' );
		$options = $this->reconcile_options();
		$options['reconcile']['walk']['unarchived'] = array_merge( array( $mu_path ), $paths );
		$this->runner->test_reconciler = new class extends MainWP_Child_Backup_Reconciler {
			public $checks = 0;
			public function reconcile_slice( $cursor, $config, $deadline ) {
				$this->checks = 0;
				return parent::reconcile_slice( $cursor, $config, $deadline );
			}
			protected function budget_exhausted( $deadline ) {
				return ++$this->checks > 2;
			}
		};
		$task_id = $this->make_task( 'files', array( $this->make_part_artifact(), $this->make_manifest_artifact( array( 'wp-content/uploads/restored.txt' ) ) ), $options );
		list( $task, $sub ) = $this->advance_until( $task_id, static function ( $sub ) { return ! empty( $sub['reconcile']['kept_by_reason']['unarchived'] ); } );
		$this->assertSame( 2, $sub['reconcile']['kept'] );
		$this->assertSame( array( $mu_path, $paths[0] ), $sub['reconcile']['kept_paths'] );
		$this->runner->test_reconciler = null;
		$task = $this->drive( $task_id );
		$sub = $this->spine->read_sub_state( $task, $this->dir );
		$this->assertSame( MainWP_Child_Backup_Task::PHASE_COMPLETED, $task->phase );
		$this->assertSame( 26, $sub['verification']['reconcile']['kept'] );
		$this->assertSame( array( 'mu_plugins' => 1, 'unarchived' => 25 ), $sub['verification']['reconcile']['kept_by_reason'] );
		$this->assertSame( array_slice( array_merge( array( $mu_path ), $paths ), 0, 20 ), $sub['verification']['reconcile']['kept_paths'] );
		foreach ( array_merge( array( $mu_path ), $paths ) as $path ) {
			$this->assertFileExists( $this->site . '/' . $path );
		}
	}

	public static function data_reconcile_safe_relpaths() {
		return array(
			'embedded dots' => array( 'a..b.php', true ),
			'live plugin' => array( 'wp-content/plugins/persist..php', true ),
			'directory dots' => array( 'dir..x/file.php', true ),
			'three leading dots' => array( '...hidden', true ),
			'segment starts with dots' => array( 'x/..y', true ),
			'segment ends with dots' => array( 'x/y..', true ),
			'htaccess' => array( '.htaccess', true ),
			'spaces' => array( 'wp-content/uploads/2026/09/a b.jpg', true ),
			'single dot segment' => array( 'x/./y', true ),
			'empty segment' => array( 'x//y', true ),
			'empty' => array( '', false ),
			'parent only' => array( '..', false ),
			'leading parent' => array( '../x', false ),
			'trailing parent' => array( 'x/..', false ),
			'embedded parent' => array( 'x/../y', false ),
			'parent after empty segment' => array( 'x//../y', false ),
			'absolute' => array( '/abs', false ),
			'drive relative' => array( 'C:x', false ),
			'drive absolute' => array( 'c:/x', false ),
			'backslash' => array( 'x\y', false ),
			'NUL' => array( "x\0y", false ),
			'newline' => array( "x\ny", false ),
			'DEL' => array( "x\x7fy", false ),
			'tab' => array( "x\ty", false ),
			'carriage return' => array( "x\ry", false ),
		);
	}

	/** @dataProvider data_reconcile_safe_relpaths */
	public function test_reconcile_safe_relpath_matrix( $path, $expected ) {
		$engine = new \MainWP\Child\MainWP_Child_Backup_Reconciler();
		$method = new ReflectionMethod( $engine, 'safe_relpath' );
		$method->setAccessible( true );
		$this->assertSame( $expected, $method->invoke( $engine, $path ) );
	}

	public function test_reconcile_removes_post_snapshot_file_and_keeps_manifest_file() {
		$this->prepare_reconcile_fixture();
		$task = $this->drive( $this->make_task( 'files', array( $this->make_part_artifact(), $this->make_manifest_artifact( array( 'wp-content/uploads/restored.txt' ) ) ), $this->reconcile_options() ) );
		$this->assertSame( MainWP_Child_Backup_Task::PHASE_COMPLETED, $task->phase );
		$this->assertFileDoesNotExist( $this->site . '/wp-content/plugins/persist.php' );
		$this->assertFileExists( $this->site . '/wp-content/uploads/restored.txt' );
		$sub = $this->spine->read_sub_state( $task, $this->dir );
		$this->assertSame( array( 'status' => 'done', 'removed' => 1, 'examined' => 2 ), $sub['verification']['reconcile'] );
	}


	public function test_full_restore_retains_reconcile_verification_after_database_phases() {
		$this->prepare_reconcile_fixture();
		$task = $this->drive( $this->make_task( 'full', array( $this->make_part_artifact(), $this->make_db_artifact(), $this->make_manifest_artifact( array( 'wp-content/uploads/restored.txt' ) ) ), $this->reconcile_options() ) );
		$sub = $this->spine->read_sub_state( $task, $this->dir );
		$this->assertSame( MainWP_Child_Backup_Task::PHASE_COMPLETED, $task->phase );
		$this->assertSame( 1, $sub['verification']['reconcile']['removed'] );
		$this->assertSame( 'done', $sub['verification']['reconcile']['status'] );
		$this->assertFileDoesNotExist( $this->site . '/wp-content/plugins/persist.php' );
	}

	protected function configure_restore_cache_purger() {
		update_option( 'mainwp_child_auto_purge_cache', '1' );
		update_option( 'mainwp_cache_control_cache_solution', 'WP Rocket' );
		update_option( 'mainwp_child_cloud_flair_enabled', '0' );
		delete_option( 'mainwp_cache_control_log' );
		$this->runner->test_cache_purger = new Restore_Test_Cache_Purger();
		return $this->runner->test_cache_purger;
	}

	public static function restore_page_cache_types() {
		return array( 'full' => array( 'full' ), 'files' => array( 'files' ), 'embedded' => array( 'embedded' ), 'foreign keys' => array( 'fk' ) );
	}

	/** @dataProvider restore_page_cache_types */
	public function test_mwp1908_page_cache_purge_once_after_reconcile_before_probe( $type ) {
		$this->prepare_reconcile_fixture();
		$purger = $this->configure_restore_cache_purger();
		$artifacts = array( 'embedded' === $type ? $this->make_full_archive_with_embedded_dump() : $this->make_part_artifact(), $this->make_manifest_artifact( array( 'wp-content/uploads/restored.txt' ) ) );
		if ( in_array( $type, array( 'full', 'fk' ), true ) ) {
			$artifacts[] = $this->make_db_artifact( 'fk' === $type );
		}
		// The purge's Throwable catch would swallow an assertion here; capture and assert afterwards.
		$files_at_purge = null;
		$purger->on_purge = function () use ( &$files_at_purge ) {
			$files_at_purge = array( file_exists( $this->site . '/wp-content/uploads/restored.txt' ), file_exists( $this->site . '/wp-content/plugins/persist.php' ) );
		};
		$probe_counts = array();
		$this->runner->before_loopback = function () use ( $purger, &$probe_counts ) {
			$probe_counts[] = count( $purger->calls );
		};
		$options = $this->reconcile_options();
		if ( 'fk' === $type ) {
			$options['source_prefix'] = 'rt_';
		}
		$task = $this->drive( $this->make_task( 'files' === $type ? 'files' : 'full', $artifacts, $options ) );
		$sub = $this->spine->read_sub_state( $task, $this->dir );
		$this->assertSame( MainWP_Child_Backup_Task::PHASE_COMPLETED, $task->phase, wp_json_encode( $sub['error'] ?? array() ) );
		$this->assertSame( array( array() ), $purger->calls, 'Call with zero arguments, including no force flag.' );
		$this->assertSame( array( 1 ), $probe_counts, 'Purge must precede the loopback.' );
		$this->assertSame( array( true, false ), $files_at_purge, 'Purge must follow the file phase and reconcile.' );
		$this->assertSame( array( 'attempted' => true, 'solution' => 'WP Rocket' ), $sub['verification']['cache_purge'] );
		$this->assertSame( 1, $sub['verification']['reconcile']['removed'] );
	}

	public function test_mwp1908_page_cache_db_only_never_calls_purger() {
		$purger = $this->configure_restore_cache_purger();
		$task = $this->drive( $this->make_task( 'db', array( $this->make_db_artifact() ) ) );
		$sub = $this->spine->read_sub_state( $task, $this->dir );
		$this->assertSame( MainWP_Child_Backup_Task::PHASE_COMPLETED, $task->phase );
		$this->assertSame( array(), $purger->calls );
		$this->assertArrayNotHasKey( 'cache_purge', $sub['verification'] );
	}

	public static function restore_page_cache_failures() {
		return array( 'exception' => array( 'exception' ), 'throwable' => array( 'error' ), 'provider result' => array( 'provider' ), 'cloudflare result' => array( 'cloudflare' ) );
	}

	/** @dataProvider restore_page_cache_failures */
	public function test_mwp1908_page_cache_failure_is_advisory( $failure ) {
		$purger = $this->configure_restore_cache_purger();
		$purger->failure = $failure;
		if ( 'cloudflare' === $failure ) {
			update_option( 'mainwp_child_cloud_flair_enabled', '1' );
		}
		$task = $this->drive( $this->make_task( 'full', array( $this->make_part_artifact(), $this->make_db_artifact() ) ) );
		$sub = $this->spine->read_sub_state( $task, $this->dir );
		$this->assertSame( MainWP_Child_Backup_Task::PHASE_COMPLETED, $task->phase );
		$this->assertCount( 1, $purger->calls );
		$this->assertTrue( $sub['verification']['cache_purge']['attempted'] );
		$this->assertNotEmpty( $sub['verification']['cache_purge']['error'] );
		$this->assertLessThanOrEqual( 200, strlen( $sub['verification']['cache_purge']['error'] ) );
		$this->assertStringNotContainsString( '/srv/', wp_json_encode( $sub['verification']['cache_purge'] ) );
		$this->assertStringNotContainsString( 'token=', wp_json_encode( $sub['verification']['cache_purge'] ) );
	}

	public function test_mwp1908_page_cache_disabled_uses_provider_option_gate() {
		$purger = $this->configure_restore_cache_purger();
		update_option( 'mainwp_child_auto_purge_cache', '0' );
		$task = $this->drive( $this->make_task( 'files', array( $this->make_files_archive_artifact() ) ) );
		$sub = $this->spine->read_sub_state( $task, $this->dir );
		$this->assertSame( MainWP_Child_Backup_Task::PHASE_COMPLETED, $task->phase );
		$this->assertSame( array( array() ), $purger->calls );
		$this->assertSame( 0, $purger->provider_calls );
		$this->assertArrayHasKey( 'cache_purge', $sub['verification'] );
		$this->assertSame( array( 'attempted' => false ), $sub['verification']['cache_purge'] );
	}

	public function test_mwp1908_page_cache_missing_provider_is_not_attempted() {
		$purger = $this->configure_restore_cache_purger();
		update_option( 'mainwp_cache_control_cache_solution', 'Plugin Not Found' );
		$task = $this->drive( $this->make_task( 'files', array( $this->make_files_archive_artifact() ) ) );
		$sub = $this->spine->read_sub_state( $task, $this->dir );
		$this->assertSame( MainWP_Child_Backup_Task::PHASE_COMPLETED, $task->phase );
		$this->assertSame( 0, $purger->provider_calls );
		$this->assertArrayHasKey( 'cache_purge', $sub['verification'] );
		$this->assertSame( array( 'attempted' => false ), $sub['verification']['cache_purge'] );
	}

	public function test_mwp1908_page_cache_record_survives_resume_without_repurging() {
		$purger = $this->configure_restore_cache_purger();
		$task_id = $this->make_task( 'files', array( $this->make_files_archive_artifact() ) );
		list( $task, $sub ) = $this->advance_until( $task_id, function ( $sub ) { return isset( $sub['cache_purge'] ); } );
		$this->assertSame( MainWP_Child_Backup_Task::PHASE_POST_RESTORE_VERIFY, $task->phase );
		$this->assertSame( $sub['cache_purge'], $sub['verification']['cache_purge'] );
		$replacement = new Testable_Restore_Task_Runner();
		$replacement->test_backup_dir = $this->dir;
		$replacement->test_site_root = $this->site;
		$replacement->test_content_roots = $this->runner->test_content_roots;
		$replacement->fake_engine = $this->runner->fake_engine;
		$replacement->test_cache_purger = $purger;
		$this->runner = $replacement;
		$task = $this->drive( $task_id );
		$this->assertSame( MainWP_Child_Backup_Task::PHASE_COMPLETED, $task->phase );
		$this->assertCount( 1, $purger->calls );
	}

	public function test_mwp1908_page_cache_slow_provider_has_its_own_slice() {
		$purger = $this->configure_restore_cache_purger();
		$this->runner->late_verify_once = true;
		$task_id = $this->make_task( 'files', array( $this->make_files_archive_artifact() ) );
		list( $task ) = $this->advance_until( $task_id, function ( $sub ) { return isset( $sub['verify_strict'] ); } );
		$this->assertFalse( $this->runner->late_verify_once, 'The first verification entry followed other work near its deadline.' );
		$this->assertSame( array(), $purger->calls );
		$this->runner->fake_engine->calls = array();
		$phases_at_purge = null;
		$purger->on_purge = function () use ( &$phases_at_purge ) {
			$phases_at_purge = $this->runner->slice_phases;
			usleep( 1100000 );
		};
		$this->runner->test_slice_budget = 1;
		$task = $this->runner->advance_one_slice( $task, 'dashboard' );
		$sub = $this->spine->read_sub_state( $task, $this->dir );
		$this->assertSame( MainWP_Child_Backup_Task::PHASE_POST_RESTORE_VERIFY, $task->phase );
		$this->assertSame( array( 'attempted' => true, 'solution' => 'WP Rocket' ), $sub['verification']['cache_purge'] );
		$this->assertSame( array( MainWP_Child_Backup_Task::PHASE_POST_RESTORE_VERIFY ), $phases_at_purge );
		$this->assertNotContains( 'verify_row_counts', $this->runner->fake_engine->calls );
		$this->assertSame( MainWP_Child_Backup_Task::PHASE_COMPLETED, $this->drive( $task_id )->phase );
		$this->assertCount( 1, $purger->calls );
	}

	public function test_mwp1908_page_cache_provider_output_is_discarded() {
		$purger = $this->configure_restore_cache_purger();
		$purger->output = 'provider debug output';
		ob_start();
		try {
			$task = $this->drive( $this->make_task( 'files', array( $this->make_files_archive_artifact() ) ) );
			$output = ob_get_contents();
		} finally {
			ob_end_clean();
		}
		$this->assertSame( MainWP_Child_Backup_Task::PHASE_COMPLETED, $task->phase );
		$this->assertSame( array( array() ), $purger->calls );
		$this->assertSame( '', $output );
	}

	public static function restore_page_cache_option_changes() {
		return array( 'provider rows added' => array( false ), 'restored row missing' => array( true ) );
	}

	/** @dataProvider restore_page_cache_option_changes */
	public function test_mwp1908_page_cache_option_writes_preserve_strict_verification( $remove_restored ) {
		global $wpdb;
		$purger = $this->configure_restore_cache_purger();
		$this->assertSame( 1, $wpdb->insert( 'rt_options', array( 'option_name' => 'active_plugins', 'option_value' => 'a:0:{}' ) ) );
		if ( $remove_restored ) {
			$this->assertSame( 1, $wpdb->delete( 'rt_options', array( 'option_name' => 'active_plugins' ) ) );
		}
		// WP Rocket's purge sets a transient (two rows) besides the dispatcher's own log row.
		$engine_calls_at_purge = null;
		$inserted = array();
		$purger->on_purge = function () use ( $wpdb, &$engine_calls_at_purge, &$inserted ) {
			$engine_calls_at_purge = $this->runner->fake_engine->calls;
			foreach ( array( 'mainwp_cache_control_log', '_transient_timeout_rocket_clear_cache', '_transient_rocket_clear_cache' ) as $name ) {
				$inserted[] = $wpdb->insert( 'rt_options', array( 'option_name' => $name, 'option_value' => '1' ) );
			}
		};
		$task_id = $this->make_task( 'full', array( $this->make_part_artifact(), $this->make_db_artifact() ) );
		$task = $this->spine->get_task( $task_id );
		$sub = $this->spine->read_sub_state( $task, $this->dir );
		$sub['db_replay']['cursor']['tables'] = array( 'options' => array( 'status' => 'ok', 'rows' => 1 ) );
		$this->spine->checkpoint( $task_id, (int) $task->version, array( 'phase' => MainWP_Child_Backup_Task::PHASE_POST_RESTORE_VERIFY, 'sub_state' => $sub ) );
		$this->runner->fake_engine->verify_real_counts = true;
		$task = $this->drive( $task_id );
		$this->assertSame( $remove_restored ? MainWP_Child_Backup_Task::PHASE_FAILED : MainWP_Child_Backup_Task::PHASE_COMPLETED, $task->phase );
		$this->assertSame( 1, $this->runner->fake_engine->last_verify_expected['options'] );
		$this->assertCount( $remove_restored ? 0 : 1, $purger->calls );
		if ( ! $remove_restored ) {
			$this->assertContains( 'verify_row_counts', $engine_calls_at_purge, 'Strict checks must run before provider code.' );
			$this->assertSame( array( 1, 1, 1 ), $inserted );
			$sub = $this->spine->read_sub_state( $task, $this->dir );
			$this->assertSame( array( 'attempted' => true, 'solution' => 'WP Rocket' ), $sub['verification']['cache_purge'] );
		}
	}

	public function test_mwp1908_page_cache_start_is_recorded_before_provider_code() {
		$purger = $this->configure_restore_cache_purger();
		$task_id = $this->make_task( 'files', array( $this->make_files_archive_artifact() ) );
		$persisted = null;
		$purger->on_purge = function () use ( $task_id, &$persisted ) {
			$persisted = $this->spine->read_sub_state( $this->spine->get_task( $task_id ), $this->dir );
		};
		$task = $this->drive( $task_id );
		$sub = $this->spine->read_sub_state( $task, $this->dir );
		$this->assertSame( MainWP_Child_Backup_Task::PHASE_COMPLETED, $task->phase );
		$this->assertTrue( $persisted['cache_purge_started'] ?? false );
		$this->assertArrayNotHasKey( 'cache_purge_started', $sub );
		$this->assertSame( array( 'attempted' => true, 'solution' => 'WP Rocket' ), $sub['verification']['cache_purge'] );
	}

	public function test_mwp1908_page_cache_interrupted_purge_is_not_repeated() {
		$purger = $this->configure_restore_cache_purger();
		$task_id = $this->make_task( 'files', array( $this->make_files_archive_artifact() ) );
		list( $task, $sub ) = $this->advance_until( $task_id, function ( $sub ) { return isset( $sub['verify_strict'] ); } );
		// Models a purge request killed by a time or memory limit after the start marker landed.
		$sub['cache_purge_started'] = true;
		$this->spine->checkpoint( $task_id, (int) $task->version, array( 'sub_state' => $sub ) );
		$task = $this->drive( $task_id );
		$sub = $this->spine->read_sub_state( $task, $this->dir );
		$this->assertSame( MainWP_Child_Backup_Task::PHASE_COMPLETED, $task->phase );
		$this->assertSame( array(), $purger->calls );
		$this->assertSame( array( 'attempted' => true, 'error' => 'Cache purge did not complete.' ), $sub['verification']['cache_purge'] );
	}

	public static function restore_page_cache_rollback_cases() {
		return array( 'success' => array( '' ), 'exception' => array( 'exception' ), 'throwable' => array( 'error' ), 'provider result' => array( 'provider' ) );
	}

	/** @dataProvider restore_page_cache_rollback_cases */
	public function test_mwp1908_page_cache_rollback_purges_and_keeps_failures_advisory( $failure ) {
		$purger = $this->configure_restore_cache_purger();
		$purger->failure = $failure;
		$artifact = $this->make_full_archive_with_embedded_dump();
		$artifact['role'] = 'full';
		$artifact['adopt_only'] = true;
		$artifact['sha256'] = '';
		$task_id = $this->make_task( 'full', array( $artifact ), array( 'source_prefix' => '', 'has_foreign_keys' => false ) );
		$task = $this->drive( $task_id );
		$sub = $this->spine->read_sub_state( $task, $this->dir );
		$this->assertSame( MainWP_Child_Backup_Task::PHASE_COMPLETED, $task->phase );
		$this->assertSame( 'restored-content', file_get_contents( $this->site . '/wp-content/uploads/restored.txt' ) );
		$this->assertSame( array( array() ), $purger->calls );
		$this->assertTrue( $sub['verification']['cache_purge']['attempted'] );
		$this->assertSame( 'no_manifest', $sub['verification']['reconcile']['reason'] );
		if ( '' !== $purger->failure ) {
			$this->assertNotEmpty( $sub['verification']['cache_purge']['error'] );
		}
	}

	public static function data_reconcile_upload_exemptions() {
		return array(
			'files default uploads' => array( 'files', 'wp-content/uploads', true ),
			'files custom uploads' => array( 'files', 'media', true ),
			'files external uploads' => array( 'files', null, false ),
			'full uploads' => array( 'full', 'wp-content/uploads', false ),
		);
	}

	/** @dataProvider data_reconcile_upload_exemptions */
	public function test_reconcile_upload_exemption_depends_on_task_type_and_basedir( $type, $relative, $keep ) {
		$this->prepare_reconcile_fixture();
		$upload_dir = $this->site . '/' . ( null === $relative ? 'wp-content/uploads' : $relative );
		wp_mkdir_p( $upload_dir );
		file_put_contents( $upload_dir . '/post-backup.jpg', 'current upload' );
		wp_mkdir_p( $upload_dir . '-extra' );
		file_put_contents( $upload_dir . '-extra/post-backup.jpg', 'outside uploads' );
		$external = $this->site . '-external';
		if ( null === $relative ) {
			// MainWP_Helper::get_mainwp_dir() creates mainwp/ under the basedir and exits when it cannot.
			wp_mkdir_p( $external . '/uploads' );
		}
		$filter = function ( $uploads ) use ( $relative, $upload_dir, $external ) {
			$uploads['basedir'] = null === $relative ? $external . '/uploads' : $upload_dir;
			return $uploads;
		};
		add_filter( 'upload_dir', $filter );
		try {
			$artifacts = array( $this->make_part_artifact(), $this->make_manifest_artifact( array( 'wp-content/uploads/restored.txt' ) ) );
			if ( 'full' === $type ) {
				$artifacts[] = $this->make_db_artifact();
			}
			$task = $this->drive( $this->make_task( $type, $artifacts, $this->reconcile_options() ) );
			$this->assertSame( MainWP_Child_Backup_Task::PHASE_COMPLETED, $task->phase );
			$this->assertSame( $keep, file_exists( $upload_dir . '/post-backup.jpg' ) );
			$this->assertFileDoesNotExist( $upload_dir . '-extra/post-backup.jpg' );
			$this->assertFileDoesNotExist( $this->site . '/wp-content/plugins/persist.php' );
		} finally {
			remove_filter( 'upload_dir', $filter );
			if ( is_dir( $external ) ) {
				$this->rrmdir( $external );
			}
		}
	}

	public function test_reconcile_failed_unlink_is_counted_without_response_warning() {
		$directory = $this->site . '/locked';
		mkdir( $directory );
		$target = $directory . '/live.php';
		file_put_contents( $target, 'keep on failure' );
		chmod( $directory, 0555 );
		try {
			clearstatcache( true, $directory );
			if ( is_writable( $directory ) ) {
				$this->markTestSkipped( 'The test user can delete from a read-only directory.' );
			}
			$engine = new \MainWP\Child\MainWP_Child_Backup_Reconciler();
			$method = new ReflectionMethod( $engine, 'remove_candidate' );
			$method->setAccessible( true );
			$cursor = array( 'removed' => 0, 'failed' => 0 );
			$warnings = array();
			set_error_handler( static function ( $severity, $message ) use ( &$warnings ) {
				if ( error_reporting() & $severity ) {
					$warnings[] = $message;
				}
				return true;
			} );
			try {
				$method->invokeArgs( $engine, array( 'locked/live.php', $this->site, &$cursor ) );
			} finally {
				restore_error_handler();
			}
			$this->assertSame( array(), $warnings );
			$this->assertSame( array( 'removed' => 0, 'failed' => 1 ), $cursor );
			$this->assertFileExists( $target );
		} finally {
			chmod( $directory, 0755 );
		}
	}

	public function test_reconcile_changed_manifest_skips_without_deletion() {
		$this->prepare_reconcile_fixture();
		$task_id = $this->make_task( 'files', array( $this->make_part_artifact(), $this->make_manifest_artifact( array( 'wp-content/uploads/restored.txt' ) ) ), $this->reconcile_options() );
		$task = $this->spine->get_task( $task_id );
		for ( $i = 0; $i < 20; ++$i ) {
			$task = $this->runner->advance_one_slice( $task, 'dashboard' );
			$sub = $this->spine->read_sub_state( $task, $this->dir );
			if ( ! empty( $sub['reconcile']['walk_done'] ) ) {
				break;
			}
		}
		$this->assertTrue( $sub['reconcile']['walk_done'] );
		file_put_contents( $this->runner->scratch_dir( $task_id, $this->dir ) . 's1-files.list', "changed.php\t1\t1\n" );
		$task = $this->drive( $task_id );
		$sub = $this->spine->read_sub_state( $task, $this->dir );
		$this->assertSame( array( 'status' => 'skipped', 'reason' => 'manifest_unverified' ), $sub['verification']['reconcile'] );
		$this->assertFileExists( $this->site . '/wp-content/plugins/persist.php' );
	}

	/**
	 * An empty user exclude list reaches the child as one empty entry, so the
	 * recorded walk block carries it and the live drill reported no_walk_params
	 * because the sanitizer voided the whole block on it. Empty entries are
	 * dropped, never fatal.
	 */
	public function test_reconcile_options_drop_empty_exclude_entries() {
		$clean = MainWP_Child_Backup_Reconciler::sanitize_options(
			array(
				'manifest' => 's1-files.list',
				'walk'     => array(
					'excludes'     => array( '', 'wp-content/uploads/mainwp', '' ),
					'excludenonwp' => 0,
					'excludezip'   => '1',
				),
			)
		);

		$this->assertSame( array( 'wp-content/uploads/mainwp' ), $clean['walk']['excludes'] );
		$this->assertSame( 1, $clean['walk']['excludezip'] );
	}

	public function test_reconcile_invalid_walk_options_do_not_expand_scope() {
		$options = $this->reconcile_options();
		$options['reconcile']['walk']['excludes'] = array( array( 'invalid' ) );
		$this->prepare_reconcile_fixture();
		$task = $this->drive( $this->make_task( 'files', array( $this->make_part_artifact(), $this->make_manifest_artifact( array( 'wp-content/uploads/restored.txt' ) ) ), $options ) );
		$sub = $this->spine->read_sub_state( $task, $this->dir );
		$this->assertSame( array( 'status' => 'skipped', 'reason' => 'no_walk_params' ), $sub['verification']['reconcile'] );
		$this->assertFileExists( $this->site . '/wp-content/plugins/persist.php' );
	}

	public function test_reconcile_keeps_recorded_user_exclude() {
		$this->prepare_reconcile_fixture();
		$task = $this->drive( $this->make_task( 'files', array( $this->make_part_artifact(), $this->make_manifest_artifact( array( 'wp-content/uploads/restored.txt' ) ) ), $this->reconcile_options( array( 'wp-content/plugins' ) ) ) );
		$sub = $this->spine->read_sub_state( $task, $this->dir );
		$this->assertSame( MainWP_Child_Backup_Task::PHASE_COMPLETED, $task->phase );
		$this->assertSame( 0, $sub['verification']['reconcile']['removed'] );
		$this->assertFileExists( $this->site . '/wp-content/plugins/persist.php' );
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_reconcile_keeps_hard_exemptions() {
		define( 'MainWP\\Child\\MAINWP_CHILD_PLUGIN_DIR', ABSPATH . 'wp-content/plugins/renamed-child/' );
		$this->prepare_reconcile_fixture();
		$paths = array(
			'wp-config.php',
			'wp-content/object-cache.php',
			'wp-content/uploads/mainwp/keep.php',
			trim( str_replace( ABSPATH, '', \MainWP\Child\MainWP_Helper::get_mainwp_dir()[0] ), '/' ) . '/keep.php',
			'wp-content/plugins/renamed-child/keep.php',
			'wp-content/.mainwp-restore-0123456789abcdef.tmp',
		);
		foreach ( $paths as $path ) {
			wp_mkdir_p( dirname( $this->site . '/' . $path ) );
			file_put_contents( $this->site . '/' . $path, 'keep' );
		}
		$task_id = $this->make_task( 'files', array( $this->make_part_artifact(), $this->make_manifest_artifact( array( 'wp-content/uploads/restored.txt' ) ) ), $this->reconcile_options() );
		$task = $this->spine->get_task( $task_id );
		for ( $i = 0; $i < 20; ++$i ) {
			$task = $this->runner->advance_one_slice( $task, 'dashboard' );
			$sub = $this->spine->read_sub_state( $task, $this->dir );
			if ( ! empty( $sub['reconcile']['walk_done'] ) ) {
				break;
			}
		}
		$this->assertTrue( $sub['reconcile']['walk_done'] );
		// Extraction deliberately sweeps dump litter; isolate reconciliation's exemption.
		file_put_contents( $this->site . '/wp-content/dbBackup-keep.sql.php', 'keep' );
		$paths[] = 'wp-content/dbBackup-keep.sql.php';
		$task = $this->drive( $task_id );
		$this->assertSame( MainWP_Child_Backup_Task::PHASE_COMPLETED, $task->phase );
		foreach ( $paths as $path ) {
			$this->assertFileExists( $this->site . '/' . $path, $path );
		}
	}

	public function test_reconcile_without_options_skips_and_keeps_extra_file() {
		$this->prepare_reconcile_fixture();
		$task = $this->drive( $this->make_task( 'files', array( $this->make_part_artifact() ) ) );
		$sub = $this->spine->read_sub_state( $task, $this->dir );
		$this->assertSame( array( 'status' => 'skipped', 'reason' => 'no_manifest' ), $sub['verification']['reconcile'] );
		$this->assertFileExists( $this->site . '/wp-content/plugins/persist.php' );
	}

	public function test_reconcile_empty_manifest_skips_and_keeps_extra_file() {
		$this->prepare_reconcile_fixture();
		$task = $this->drive( $this->make_task( 'files', array( $this->make_part_artifact(), $this->make_manifest_artifact( array() ) ), $this->reconcile_options() ) );
		$sub = $this->spine->read_sub_state( $task, $this->dir );
		$this->assertSame( array( 'status' => 'skipped', 'reason' => 'empty_manifest' ), $sub['verification']['reconcile'] );
		$this->assertFileExists( $this->site . '/wp-content/plugins/persist.php' );
	}

	public function test_reconcile_malformed_manifest_aborts_before_deletion() {
		$this->prepare_reconcile_fixture();
		$task = $this->drive( $this->make_task( 'files', array( $this->make_part_artifact(), $this->make_manifest_artifact( array( "invalid\trow", 'wp-content/uploads/restored.txt' ) ) ), $this->reconcile_options() ) );
		$sub = $this->spine->read_sub_state( $task, $this->dir );
		$this->assertSame( MainWP_Child_Backup_Task::PHASE_FAILED, $task->phase );
		$this->assertSame( MainWP_Backup_Error::ERR_RESTORE_RECONCILE, $sub['error']['code'] );
		// The preserve-set capture reads the same manifest and stops the restore before extraction.
		$this->assertArrayNotHasKey( 'reconcile', isset( $sub['verification'] ) ? $sub['verification'] : array() );
		$this->assertFileDoesNotExist( $this->site . '/wp-content/uploads/restored.txt' );
		$this->assertFileExists( $this->site . '/wp-content/plugins/persist.php' );
	}

	public function test_restore_db_never_reconciles() {
		$this->prepare_reconcile_fixture();
		$task = $this->drive( $this->make_task( 'db', array( $this->make_db_artifact(), $this->make_manifest_artifact( array( '../x' ) ) ), $this->reconcile_options() ) );
		$sub = $this->spine->read_sub_state( $task, $this->dir );
		$this->assertSame( MainWP_Child_Backup_Task::PHASE_COMPLETED, $task->phase );
		$this->assertArrayNotHasKey( 'reconcile', $sub['verification'] );
		$this->assertArrayNotHasKey( 'mu_plugins_preserve', $sub );
		$this->assertFileExists( $this->site . '/wp-content/plugins/persist.php' );
	}

	public function test_reconcile_resumes_with_total_removed_count() {
		$this->prepare_reconcile_fixture();
		file_put_contents( $this->site . '/wp-content/plugins/second.php', 'extra' );
		$this->runner->test_reconciler = new class extends \MainWP\Child\MainWP_Child_Backup_Reconciler {
			public $checks = 0;
			protected function budget_exhausted( $deadline ) {
				return ++$this->checks % 2 === 0;
			}
		};
		$task_id = $this->make_task( 'files', array( $this->make_part_artifact(), $this->make_manifest_artifact( array( 'wp-content/uploads/restored.txt' ) ) ), $this->reconcile_options() );
		$task = $this->spine->get_task( $task_id );
		for ( $i = 0; $i < 20; ++$i ) {
			$task = $this->runner->advance_one_slice( $task, 'dashboard' );
			$sub = $this->spine->read_sub_state( $task, $this->dir );
			if ( isset( $sub['reconcile']['removed'] ) && 1 === $sub['reconcile']['removed'] ) {
				break;
			}
		}
		$this->assertSame( 1, $sub['reconcile']['removed'] );
		$this->assertSame( 'wp-content/plugins/persist.php', $sub['reconcile']['last'] );
		$this->runner->test_reconciler = null;
		$task = $this->drive( $task_id );
		$sub = $this->spine->read_sub_state( $task, $this->dir );
		$this->assertSame( MainWP_Child_Backup_Task::PHASE_COMPLETED, $task->phase );
		$this->assertSame( 2, $sub['verification']['reconcile']['removed'] );
		$this->assertSame( 'done', $sub['verification']['reconcile']['status'] );
	}

	public function test_reconcile_batches_checkpoints_and_resumes_examined_total() {
		$paths = array();
		for ( $i = 0; $i < 1200; ++$i ) {
			$path = sprintf( 'file-%04d.txt', $i );
			$paths[] = $path;
			file_put_contents( $this->site . '/' . $path, 'keep' );
		}
		$this->make_manifest_artifact( $paths );
		$scratch = $this->dir . '/reconcile/';
		mkdir( $scratch );
		$checkpoints = array();
		$last_checkpoint = null;
		$config = array(
			'manifest' => $this->dir . '/s1-files.list',
			'walk' => $this->reconcile_options()['reconcile']['walk'],
			'root' => $this->site,
			'backup_dir' => $this->dir . '/backups',
			'scratch' => $scratch,
			'progress_callback' => static function () {},
			'checkpoint_cursor' => static function ( $cursor ) use ( &$checkpoints, &$last_checkpoint ) {
				$checkpoints[] = $cursor['examined'];
				$last_checkpoint = $cursor;
				return true;
			},
		);
		$engine = new \MainWP\Child\MainWP_Child_Backup_Reconciler();
		$out = $engine->reconcile_slice( array(), $config, time() + 60 );
		$this->assertTrue( $out['cursor']['walk_done'] );
		$limited = new class extends \MainWP\Child\MainWP_Child_Backup_Reconciler {
			public $checks = 0;
			protected function budget_exhausted( $deadline ) {
				return ++$this->checks > 1500;
			}
		};
		$out = $limited->reconcile_slice( $out['cursor'], $config, time() + 60 );
		$this->assertFalse( $out['done'] );
		$this->assertFalse( $out['abandon'] );
		$this->assertGreaterThan( 500, $out['cursor']['examined'] );
		$this->assertLessThan( 1200, $out['cursor']['examined'] );
		$this->assertSame( array( 500 ), $checkpoints );
		$out = $engine->reconcile_slice( $last_checkpoint, $config, time() + 60 );
		$this->assertTrue( $out['done'] );
		$this->assertSame( array( 'status' => 'done', 'removed' => 0, 'examined' => 1200 ), $out['outcome'] );
		$this->assertSame( array( 500, 1000 ), $checkpoints );
		$config['checkpoint_cursor'] = static function () { return false; };
		$out = $engine->reconcile_slice( array( 'validated' => true, 'walk_done' => true ), $config, time() + 60 );
		$this->assertTrue( $out['abandon'] );
		$this->assertFalse( $out['done'] );
		$this->assertSame( 500, $out['cursor']['examined'] );
	}

	public function test_reconcile_batches_deletion_checkpoints_and_resumes() {
		$paths = array();
		for ( $i = 0; $i < 250; ++$i ) {
			$paths[] = sprintf( 'extra-%04d.txt', $i );
		}
		foreach ( $paths as $path ) {
			file_put_contents( $this->site . '/' . $path, 'extra' );
		}
		$this->make_manifest_artifact( array( 'kept.txt' ) );
		$scratch = $this->dir . '/reconcile/';
		mkdir( $scratch );
		$checkpoints = array();
		$config = array(
			'manifest' => $this->dir . '/s1-files.list',
			'walk' => $this->reconcile_options()['reconcile']['walk'],
			'root' => $this->site,
			'backup_dir' => $this->dir . '/backups',
			'scratch' => $scratch,
			'progress_callback' => static function () {},
			'checkpoint_cursor' => static function ( $cursor ) use ( &$checkpoints ) {
				$checkpoints[] = $cursor;
				return true;
			},
		);
		$engine = new MainWP_Child_Backup_Reconciler();
		$walk = $engine->reconcile_slice( array(), $config, time() + 60 );
		$this->assertTrue( $walk['cursor']['walk_done'] );
		$out = $engine->reconcile_slice( $walk['cursor'], $config, time() + 60 );
		$this->assertSame( array( 'status' => 'done', 'removed' => 250, 'examined' => 250 ), $out['outcome'] );
		$this->assertLessThanOrEqual( 3, count( $checkpoints ) );
		$this->assertSame( array( 100, 200 ), array_column( $checkpoints, 'removed' ) );
		foreach ( $paths as $path ) {
			$this->assertFileDoesNotExist( $this->site . '/' . $path );
		}

		// Resume with only the last checkpoint durable and its remaining files still live.
		foreach ( array_slice( $paths, 200 ) as $path ) {
			file_put_contents( $this->site . '/' . $path, 'extra' );
		}
		$resumed = new MainWP_Child_Backup_Reconciler();
		$out = $resumed->reconcile_slice( end( $checkpoints ), $config, time() + 60 );
		$this->assertSame( array( 'status' => 'done', 'removed' => 250, 'examined' => 250 ), $out['outcome'] );
		foreach ( $paths as $path ) {
			$this->assertFileDoesNotExist( $this->site . '/' . $path );
		}
	}

	public function test_reconcile_dot_names_and_malformed_manifest() {
		$this->prepare_reconcile_fixture();
		$path = 'wp-content/plugins/persist..php';
		$manifest_path = 'wp-content/plugins/jquery.min..js';
		file_put_contents( $this->site . '/' . $path, 'live-only file' );
		file_put_contents( $this->site . '/' . $manifest_path, 'keep manifest file' );
		$task = $this->drive( $this->make_task( 'files', array( $this->make_part_artifact(), $this->make_manifest_artifact( array( $manifest_path, 'wp-content/uploads/restored.txt' ) ) ), $this->reconcile_options() ) );
		$sub = $this->spine->read_sub_state( $task, $this->dir );
		$this->assertSame( MainWP_Child_Backup_Task::PHASE_COMPLETED, $task->phase );
		$this->assertSame( array( 'status' => 'done', 'removed' => 2, 'examined' => 4 ), $sub['verification']['reconcile'] );
		$this->assertFileDoesNotExist( $this->site . '/' . $path );
		$this->assertSame( 'keep manifest file', file_get_contents( $this->site . '/' . $manifest_path ) );
		$this->assertFileDoesNotExist( $this->site . '/wp-content/plugins/persist.php' );
		file_put_contents( $this->site . '/' . $path, 'keep after malformed manifest' );
		file_put_contents( $this->dir . '/s1-files.list', "unparseable manifest line\n" );
		$engine = new \MainWP\Child\MainWP_Child_Backup_Reconciler();
		$out = $engine->reconcile_slice( array(), array( 'manifest' => $this->dir . '/s1-files.list' ), time() + 60 );
		$this->assertTrue( $out['done'] );
		$this->assertSame( 'aborted', $out['outcome']['status'] );
		$this->assertSame( 0, $out['cursor']['examined'] );
		$this->assertSame( 'keep after malformed manifest', file_get_contents( $this->site . '/' . $path ) );
		$this->assertSame( 'keep manifest file', file_get_contents( $this->site . '/' . $manifest_path ) );
	}

	public function test_reconcile_missing_named_manifest_fails_before_extraction() {
		$this->prepare_reconcile_fixture();
		$task = $this->drive( $this->make_task( 'files', array( $this->make_part_artifact() ), $this->reconcile_options() ) );
		$sub = $this->spine->read_sub_state( $task, $this->dir );
		$this->assertSame( MainWP_Child_Backup_Task::PHASE_FAILED, $task->phase );
		$this->assertSame( MainWP_Backup_Error::ERR_RESTORE_RECONCILE, $sub['error']['code'] );
		$this->assertArrayNotHasKey( 'file_restore', $sub );
		$this->assertFileDoesNotExist( $this->site . '/wp-content/uploads/restored.txt' );
		$this->assertFileExists( $this->site . '/wp-content/plugins/persist.php' );
	}

	public function test_restore_create_mutex_insert_failure_with_holder_has_write_failed_error_code() {
		global $wpdb;
		$this->assertTrue( MainWP_Child_Backup_Mutex::acquire( 'legacy', 'holder', 300 ) );
		$filter = static function ( $query ) {
			if ( 0 === strpos( $query, 'INSERT IGNORE INTO' ) && false !== strpos( $query, MainWP_Child_Backup_Mutex::OPTION_NAME ) ) {
				return 'SELECT mainwp_mutex_forced_failure';
			}
			return $query;
		};
		$suppress = $wpdb->suppress_errors();
		add_filter( 'query', $filter );
		try {
			$this->make_task( 'db', array() );
			$this->fail( 'A mutex INSERT failure must refuse creation.' );
		} catch ( MainWP_Exception $e ) {
			$this->assertSame( MainWP_Backup_Error::ERR_MUTEX_WRITE_FAILED, $e->get_message_error_code() );
			$this->assertSame( esc_html( MainWP_Backup_Error::message( MainWP_Backup_Error::ERR_MUTEX_WRITE_FAILED ) ), $e->getMessage() );
			$this->assertSame( 'holder', MainWP_Child_Backup_Mutex::holder()['owner'] );
			$this->assertEmpty( MainWP_Child_Backup_Task::instance()->get_active_tasks() );
		} finally {
			remove_filter( 'query', $filter );
			$wpdb->suppress_errors( $suppress );
			MainWP_Child_Backup_Mutex::release( 'holder' );
		}
	}

	public function test_restore_create_distinguishes_mutex_write_failure_from_unreadable_holder() {
		global $wpdb;
		foreach ( array( 'SELECT mainwp_mutex_forced_failure' => MainWP_Backup_Error::ERR_MUTEX_WRITE_FAILED, 'SELECT 1 WHERE 1 = 0' => MainWP_Backup_Error::ERR_ALREADY_RUNNING ) as $replacement => $expected_code ) {
			$inserts = 0;
			$filter = static function ( $query ) use ( $replacement, &$inserts ) {
				if ( 0 === strpos( $query, 'INSERT IGNORE INTO' ) && false !== strpos( $query, MainWP_Child_Backup_Mutex::OPTION_NAME ) ) {
					++$inserts;
					return $replacement;
				}
				return $query;
			};
			$this->assertNull( MainWP_Child_Backup_Mutex::holder() );
			$suppress = $wpdb->suppress_errors();
			add_filter( 'query', $filter );
			try {
				$this->make_task( 'db', array() );
				$this->fail( 'Creation must refuse a mutex write failure or unreadable holder.' );
			} catch ( MainWP_Exception $e ) {
				$this->assertSame( $expected_code, $e->get_message_error_code() );
				$this->assertSame( MainWP_Backup_Error::ERR_MUTEX_WRITE_FAILED === $expected_code ? 1 : 2, $inserts );
				$this->assertSame( rtrim( $e->getMessage() ), $e->getMessage() );
				$this->assertEmpty( MainWP_Child_Backup_Task::instance()->get_active_tasks() );
			} finally {
				remove_filter( 'query', $filter );
				$wpdb->suppress_errors( $suppress );
			}
		}
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

	public function test_successful_database_paths_clear_restored_mutex_once() {
		foreach ( array( false, true ) as $fk ) {
			$engine = new class() extends Fake_Restore_Engine {
				public $on_live;

				public function replay_slice( $cursor, $config, $deadline, $unclean = false ) {
					$outcome = parent::replay_slice( $cursor, $config, $deadline, $unclean );
					if ( $outcome['done'] && ! empty( $config['fk_path'] ) ) {
						call_user_func( $this->on_live );
					}
					return $outcome;
				}

				public function swap_tables( $stems, $config ) {
					$outcome = parent::swap_tables( $stems, $config );
					if ( $outcome['done'] ) {
						call_user_func( $this->on_live );
					}
					return $outcome;
				}
			};
			$seeded = 0;
			$engine->on_live = function () use ( &$seeded ) {
				++$seeded;
				$value = 'native|completed-backup|' . ( time() + 3600 );
				$this->assertTrue( add_option( MainWP_Child_Backup_Mutex::OPTION_NAME, $value, '', false ) );
				wp_cache_set( MainWP_Child_Backup_Mutex::OPTION_NAME, $value, 'options' );
			};
			$this->runner->fake_engine = $engine;
			$task_id = $this->make_task( 'db', array( $this->make_db_artifact( $fk ) ), $fk ? array( 'source_prefix' => 'rt_' ) : array() );
			$deletes  = 0;
			$verified = 0;
			$observe  = static function ( $query ) use ( &$deletes, &$verified, $engine ) {
				if ( 0 === strpos( $query, 'DELETE FROM' ) && false !== strpos( $query, MainWP_Child_Backup_Mutex::OPTION_NAME ) && false !== strpos( $query, 'BINARY option_value =' ) ) {
					++$deletes;
					// The row-count check counts the replayed options table; the clear must follow it.
					$verified += in_array( 'verify_row_counts', $engine->calls, true ) ? 1 : 0;
				}
				return $query;
			};
			add_filter( 'query', $observe );
			try {
				$task = $this->drive( $task_id );
				$this->assertSame( MainWP_Child_Backup_Task::PHASE_COMPLETED, $task->phase );
				$sub_state = $this->spine->read_sub_state( $task, $this->dir );
				$this->assertStringStartsWith( 'native|completed-backup|', $sub_state['restored_mutex'] );
				$this->assertSame( 1, $seeded );
				$this->assertSame( 1, $deletes );
				$this->assertSame( 1, $verified );
				$this->assertNull( MainWP_Child_Backup_Mutex::holder() );
				$this->assertFalse( wp_cache_get( MainWP_Child_Backup_Mutex::OPTION_NAME, 'options' ) );
				$this->assertFalse( get_option( MainWP_Child_Backup_Mutex::OPTION_NAME ) );
				$this->assertSame( $fk, ! empty( $engine->last_replay_config['fk_path'] ) );
				$this->assertSame( $fk ? 0 : 1, count( preg_grep( '/^swap_tables:/', $engine->calls ) ) );
			} finally {
				remove_filter( 'query', $observe );
				delete_option( MainWP_Child_Backup_Mutex::OPTION_NAME );
			}
		}
	}

	public static function identity_restore_cases() {
		return array(
			'swap inserts exceed deletes' => array( false, 1 ),
			'swap deletes exceed inserts' => array( false, -1 ),
			'FK inserts exceed deletes'   => array( true, 1 ),
			'FK deletes exceed inserts'   => array( true, -1 ),
		);
	}

	/**
	 * @dataProvider identity_restore_cases
	 */
	public function test_database_paths_preserve_connection_identity( $fk, $delta ) {
		global $wpdb;
		$this->seed_woo_tables();
		$this->runner->yield_before_url_rewrite = true;
		$live = array(
			'mainwp_child_pubkey'                 => array( 'live-src_public-key', 'yes' ),
			'mainwp_child_uniqueId'               => array( 'live-unique-id', 'no' ),
			'mainwp_child_connected_admin'        => array( '', 'auto-off' ),
			'mainwp_child_server'                 => array( 'ciphertext:/+=\'"\\opaque', 'on' ),
			'mainwp_child_openssl_sign_algo'      => array( '0', 'off' ),
			'mainwp_child_backup_task_db_version' => array( MainWP_Child_Backup_Task::DB_VERSION, 'yes' ),
		);
		$dump = array(
			'mainwp_child_pubkey'                 => array( 'dump-public-key', 'no' ),
			'mainwp_child_nonce'                  => array( 'dump-only-nonce', 'yes' ),
			'mainwp_child_connected_admin'        => array( 'dump-admin', 'yes' ),
			'mainwp_child_server'                 => array( 'dump-ciphertext', 'no' ),
			'mainwp_child_openssl_sign_algo'      => array( '1', 'yes' ),
			'mainwp_child_backup_task_db_version' => array( 'dump-stale-db-version', 'no' ),
		);
		foreach ( array( 'mainwp_child_request_id_', 'mainwp_child_blocked_request_id_', 'mainwp_child_advanced_request_id_' ) as $prefix ) {
			$live[ $prefix . hash( 'sha256', 'live-request' ) ] = array( '1750000000', 'off' );
			$dump[ $prefix . hash( 'sha256', 'dump-request' ) ] = array( '1700000000', 'off' );
		}
		if ( 1 === $delta ) {
			$live['mainwp_child_auth'] = array( 'live-auth', 'auto-on' );
		} else {
			$dump['mainwp_child_auth'] = array( 'dump-only-auth', 'yes' );
		}
		foreach ( $live as $name => $row ) {
			$this->assertSame( 1, $wpdb->insert( 'rt_options', array( 'option_name' => $name, 'option_value' => $row[0], 'autoload' => $row[1] ) ) );
		}
		$names = array_unique( array_merge( array_keys( $live ), array_keys( $dump ) ) );
		$engine = $this->runner->fake_engine;
		$engine->tables = array( 'options' => array( 'status' => 'ok', 'rows' => count( $dump ) + 1 ) );
		$engine->verify_real_counts = true;
		$became_live = 0;
		$task_id = null;
		$engine->on_database_live = function () use ( $wpdb, $dump, $names, $engine, $fk, &$task_id, &$became_live ) {
			++$became_live;
			if ( ! $fk ) {
				$checkpoint = $this->spine->read_sub_state( $this->spine->get_task( $task_id ), $this->dir );
				$this->assertSame( count( $dump ), $checkpoint['identity_dump_count'], 'The tmp-table baseline must be checkpointed before the swap.' );
			}
			$this->assertContains( 'begin_maintenance', $engine->calls );
			$this->assertNotContains( 'exit_maintenance', $engine->calls );
			$this->assertNotFalse( $wpdb->query( 'DELETE FROM `rt_options`' ) );
			foreach ( $dump as $name => $row ) {
				$this->assertSame( 1, $wpdb->insert( 'rt_options', array( 'option_name' => $name, 'option_value' => $row[0], 'autoload' => $row[1] ) ) );
			}
			$this->assertSame( 1, $wpdb->insert( 'rt_options', array( 'option_name' => 'active_plugins', 'option_value' => 'dump-plugins', 'autoload' => 'yes' ) ) );
			foreach ( $names as $name ) {
				wp_cache_set( $name, 'stale-dump-cache', 'options' );
			}
			wp_cache_set( 'alloptions', array( 'mainwp_child_pubkey' => 'stale-key' ), 'options' );
			wp_cache_set( 'notoptions', array( 'mainwp_child_uniqueId' => true ), 'options' );
		};
		$task_id = $this->make_task( 'db', array( $this->make_db_artifact( $fk ) ), $fk ? array( 'source_prefix' => 'rt_' ) : array() );
		if ( ! $fk ) {
			$initial = $this->spine->read_sub_state( $this->spine->get_task( $task_id ), $this->dir );
			$tmp_table = $engine->tmp_prefix( $initial['params']['restore_id'] ) . 'options';
			$this->identity_fixture_tables[] = $tmp_table;
			$this->assertNotFalse( $wpdb->query( 'CREATE TABLE `' . $tmp_table . '` LIKE `rt_options`' ) );
			foreach ( $dump as $name => $row ) {
				$this->assertSame( 1, $wpdb->insert( $tmp_table, array( 'option_name' => $name, 'option_value' => $row[0], 'autoload' => $row[1] ) ) );
			}
		}
		// Inspect the first slice after the restored options become live, before verification.
		$task = $this->spine->get_task( $task_id );
		for ( $slice = 0; $slice < 30 && 0 === $became_live && ! MainWP_Child_Backup_Task::is_terminal_phase( $task->phase ); ++$slice ) {
			$task = $this->runner->advance_one_slice( $task, MainWP_Child_Backup_Task_Runner::LEASE_OWNER_DASHBOARD );
		}
		$this->assertSame( 1, $became_live );
		$this->assertSame( MainWP_Child_Backup_Task::PHASE_REWRITING_URLS, $task->phase );
		$state = $this->spine->read_sub_state( $task, $this->dir );
		$this->assertSame( $delta + 3, $state['identity_preserve_delta'] );
		$captured = array_column( $state['identity_preserve'], null, 'option_name' );
		foreach ( $names as $name ) {
			$row = $wpdb->get_row( $wpdb->prepare( 'SELECT option_name, option_value, autoload FROM `rt_options` WHERE option_name = %s', $name ), ARRAY_A );
			if ( isset( $live[ $name ] ) ) {
				$expected = array( 'option_name' => $name, 'option_value' => $live[ $name ][0], 'autoload' => $live[ $name ][1] );
				$this->assertSame( $expected, $row, $name );
				$this->assertSame( $expected, $captured[ $name ], 'Capture must retain the pre-restore row.' );
			} else {
				if ( in_array( $name, MainWP_Child_Restore_Task_Runner::IDENTITY_OPTIONS, true ) ) {
					$this->assertNull( $row, $name );
				} else {
					$this->assertSame( array( 'option_name' => $name, 'option_value' => $dump[ $name ][0], 'autoload' => $dump[ $name ][1] ), $row );
				}
				$this->assertArrayNotHasKey( $name, $captured );
			}
			if ( isset( $live[ $name ] ) || in_array( $name, MainWP_Child_Restore_Task_Runner::IDENTITY_OPTIONS, true ) ) {
				$this->assertFalse( wp_cache_get( $name, 'options' ), $name );
			}
		}
		$this->assertFalse( wp_cache_get( 'alloptions', 'options' ) );
		$this->assertFalse( wp_cache_get( 'notoptions', 'options' ) );
		$this->assertGreaterThan( 0, $this->runner->cache_flushes );
		$this->assertSame( 'dump-plugins', $wpdb->get_var( "SELECT option_value FROM `rt_options` WHERE option_name = 'active_plugins'" ) );
		$task = $this->drive( $task_id );
		$this->assertSame( MainWP_Child_Backup_Task::PHASE_COMPLETED, $task->phase );
		foreach ( $live as $name => $row ) {
			$this->assertSame( array( 'option_value' => $row[0], 'autoload' => $row[1] ), $wpdb->get_row( $wpdb->prepare( 'SELECT option_value, autoload FROM `rt_options` WHERE option_name = %s', $name ), ARRAY_A ), 'URL/prefix rewriting must preserve ' . $name );
		}
		$this->assertSame( count( $dump ) + 1 + $delta + 3, $engine->last_verify_expected['options'] );
		$this->assertTrue( $this->spine->read_sub_state( $task, $this->dir )['verification']['row_counts_ok'] );
	}

	public function test_cross_prefix_rewrite_preserves_live_and_dump_only_ledgers() {
		global $wpdb;
		$engine = $this->runner->fake_engine;
		$engine->tables = array( 'options' => array( 'status' => 'ok', 'rows' => 6 ) );
		$engine->verify_real_counts = true;
		$artifact = $this->make_db_artifact();
		$zip = new ZipArchive();
		$this->assertTrue( $zip->open( $this->dir . '/db.zip' ) );
		$sql = $zip->getFromName( 'dbBackup-src_items.sql.php' );
		$this->assertTrue( $zip->addFromString( 'dbBackup-src_items.sql.php', str_replace( '"site_url":"' . site_url() . '"', '"site_url":"https://old.example"', $sql ) ) );
		$this->assertTrue( $zip->close() );
		clearstatcache( true, $this->dir . '/db.zip' );
		$artifact['size'] = filesize( $this->dir . '/db.zip' );
		$artifact['sha256'] = hash_file( 'sha256', $this->dir . '/db.zip' );
		$task_id = $this->make_task( 'db', array( $artifact ), array( 'source_prefix' => 'wp_' ) );
		$initial = $this->spine->read_sub_state( $this->spine->get_task( $task_id ), $this->dir );
		$tmp_table = $engine->tmp_prefix( $initial['params']['restore_id'] ) . 'options';
		$expected = array();
		try {
			$this->assertNotFalse( $wpdb->query( 'CREATE TABLE `' . $tmp_table . '` LIKE `rt_options`' ) );
			foreach ( array( 'mainwp_child_request_id_', 'mainwp_child_blocked_request_id_', 'mainwp_child_advanced_request_id_' ) as $prefix ) {
				foreach ( array( 'live', 'dump' ) as $origin ) {
					$name = $prefix . hash( 'sha256', $origin );
					$row = array( 'option_name' => $name, 'option_value' => 'wp_' . $origin, 'autoload' => 'off' );
					$expected[ $name ] = $row;
					$this->assertSame( 1, $wpdb->insert( 'live' === $origin ? 'rt_options' : $tmp_table, $row ) );
				}
			}
			$this->assertSame( 1, $wpdb->insert( $tmp_table, array( 'option_name' => 'rewrite_control', 'option_value' => 'wp_control https://old.example/path', 'autoload' => 'no' ) ) );
			foreach ( array( 'wp_user_roles' => 'source-roles', 'rt_user_roles' => 'stale-roles' ) as $name => $value ) {
				$this->assertSame( 1, $wpdb->insert( $tmp_table, array( 'option_name' => $name, 'option_value' => $value, 'autoload' => 'no' ) ) );
			}
			$engine->on_database_live = function () use ( $wpdb, $tmp_table ) {
				$this->assertNotFalse( $wpdb->query( 'DELETE FROM `rt_options`' ) );
				$this->assertSame( 6, $wpdb->query( 'INSERT INTO `rt_options` SELECT * FROM `' . $tmp_table . '`' ) );
			};
			$task = $this->drive( $task_id );
			$this->assertSame( MainWP_Child_Backup_Task::PHASE_COMPLETED, $task->phase );
			$this->assertSame( 'wp_', $engine->last_replay_config['source_prefix'] );
			$this->assertSame( 'source-roles', $wpdb->get_var( "SELECT option_value FROM `rt_options` WHERE option_name = 'rt_user_roles'" ) );
			$this->assertNull( $wpdb->get_var( "SELECT option_value FROM `rt_options` WHERE option_name = 'wp_user_roles'" ) );
			$this->assertSame( array( 'rt_options' => 1 ), $this->spine->read_sub_state( $task, $this->dir )['verification']['url_rewrite_renamed_over'] );
			$this->assertSame( 'wp_control ' . site_url() . '/path', $wpdb->get_var( $wpdb->prepare( 'SELECT option_value FROM `rt_options` WHERE option_name = %s', 'rewrite_control' ) ) );
			foreach ( $expected as $name => $row ) {
				$this->assertSame( $row, $wpdb->get_row( $wpdb->prepare( 'SELECT option_name, option_value, autoload FROM `rt_options` WHERE option_name = %s', $name ), ARRAY_A ), $name );
			}
		} finally {
			$wpdb->query( 'DROP TABLE IF EXISTS `' . $tmp_table . '`' );
			$wpdb->query( 'DELETE FROM `rt_options`' );
			$wpdb->delete( $this->spine->get_table_name(), array( 'task_id' => $task_id ) );
			$this->commit_transaction();
		}
	}

	/**
	 * Prefix replacement must not alter plugin lookup keys.
	 */
	public function test_cross_prefix_rewrite_preserves_option_names_and_rewrites_values() {
		global $wpdb;
		$names = array(
			'mainwp_child_pubkey',
			'mainwp_child_settings',
			'mainwp_child_request_id_' . hash( 'sha256', 'request' ),
			'mainwp_child_blocked_request_id_' . hash( 'sha256', 'request' ),
			'mainwp_child_advanced_request_id_' . hash( 'sha256', 'request' ),
			'wp_mail_smtp',
		);
		foreach ( $names as $name ) {
			$this->assertSame( 1, $wpdb->insert( 'rt_options', array( 'option_name' => $name, 'option_value' => 'wp_value https://old.example/path', 'autoload' => 'no' ) ) );
		}
		$before = $wpdb->get_results( 'SELECT option_id, option_name FROM `rt_options` ORDER BY option_id', ARRAY_A );
		$roles  = serialize( array( 'administrator' => array( 'capabilities' => array( 'manage_options' => true ) ) ) );
		$this->assertSame(
			1,
			$wpdb->insert(
				'rt_options',
				array(
					'option_name'  => 'wp_user_roles',
					'option_value' => $roles,
				)
			)
		);
		$rewriter = new \MainWP\Child\MainWP_Backup_Url_Rewriter();
		$outcome  = $rewriter->rewrite_slice(
			array(),
			array(
				'tables'              => array( 'rt_options' ),
				'pairs'               => array(
					array( 'from' => 'https://old.example', 'to' => 'https://new.example' ),
				),
				'exclude'             => array(),
				'rename_option_names' => array( 'rt_options' => array( 'wp_user_roles' => 'rt_user_roles' ) ),
			),
			microtime( true ) + 10
		);
		$this->assertTrue( $outcome['done'] );
		$this->assertSame( 0, $outcome['failed_updates'] );
		$this->assertSame( $before, $wpdb->get_results( "SELECT option_id, option_name FROM `rt_options` WHERE option_name NOT IN ('wp_user_roles', 'rt_user_roles') ORDER BY option_id", ARRAY_A ) );
		$this->assertNull( $wpdb->get_var( "SELECT option_value FROM `rt_options` WHERE option_name = 'wp_user_roles'" ) );
		$this->assertSame( $roles, $wpdb->get_var( "SELECT option_value FROM `rt_options` WHERE option_name = 'rt_user_roles'" ) );
		foreach ( $names as $name ) {
			$this->assertSame( 'wp_value https://new.example/path', $wpdb->get_var( $wpdb->prepare( 'SELECT option_value FROM `rt_options` WHERE option_name = %s', $name ) ), $name );
		}
	}

	/**
	 * Core roles must remain addressable after the runner rewrites the database, so
	 * the runner has to hand the rewriter an exact rename for the dump's role key -
	 * and only when the two prefixes actually differ.
	 *
	 * @param string $source_prefix Dump table prefix.
	 * @param array  $expected      Rename map the runner must build.
	 * @param array  $stems         Loaded table stems.
	 * @dataProvider role_prefix_cases
	 */
	public function test_restore_runner_renames_only_source_roles( $source_prefix, $expected, $stems = array( 'options' ) ) {
		if ( 'RT_' === $source_prefix ) {
			global $wpdb;
			remove_filter( 'query', array( $this, '_create_temporary_tables' ) );
			remove_filter( 'query', array( $this, '_drop_temporary_tables' ) );
			$this->assertNotFalse( $wpdb->query( 'DROP TABLE IF EXISTS `rt_options`' ) );
			$this->assertNotFalse( $wpdb->query( 'CREATE TABLE `rt_options` (option_id BIGINT NOT NULL AUTO_INCREMENT, option_name VARCHAR(191) NOT NULL, option_value LONGTEXT, autoload VARCHAR(20) DEFAULT \'yes\', PRIMARY KEY (option_id), UNIQUE KEY (option_name))' ) );
			$engine = $this->runner->fake_engine;
			$engine->tables = array( 'options' => array( 'status' => 'ok', 'rows' => 1 ) );
			$engine->verify_real_counts = true;
			$roles = serialize( array( 'administrator' => array( 'capabilities' => array( 'manage_options' => true ) ) ) );
			$task_id = $this->make_task( 'db', array( $this->make_db_artifact() ), array( 'source_prefix' => $source_prefix ) );
			$initial = $this->spine->read_sub_state( $this->spine->get_task( $task_id ), $this->dir );
			$tmp_table = $engine->tmp_prefix( $initial['params']['restore_id'] ) . 'options';
			$this->identity_fixture_tables[] = $tmp_table;
			$this->assertNotFalse( $wpdb->query( 'DROP TABLE IF EXISTS `' . $tmp_table . '`' ) );
			$this->assertNotFalse( $wpdb->query( 'CREATE TABLE `' . $tmp_table . '` LIKE `rt_options`' ) );
			$this->assertSame( 1, $wpdb->insert( $tmp_table, array( 'option_name' => $source_prefix . 'user_roles', 'option_value' => $roles ) ) );
			$engine->on_database_live = function () use ( $wpdb, $tmp_table ) {
				$this->assertNotFalse( $wpdb->query( 'DELETE FROM `rt_options`' ) );
				$this->assertSame( 1, $wpdb->query( 'INSERT INTO `rt_options` SELECT * FROM `' . $tmp_table . '`' ) );
			};
			$task = $this->drive( $task_id );
			$this->assertSame( MainWP_Child_Backup_Task::PHASE_COMPLETED, $task->phase );
			$this->assertSame( array( array( 'option_name' => 'rt_user_roles', 'option_value' => $roles ) ), $wpdb->get_results( "SELECT option_name, option_value FROM `rt_options` WHERE option_name = 'rt_user_roles'", ARRAY_A ) );
			$state = $this->spine->read_sub_state( $task, $this->dir );
			$this->assertSame( 0, $state['url_rewrite_renamed_over']['rt_options'] ?? 0 );
			$this->assertSame( 0, $state['verification']['url_rewrite_renamed_over']['rt_options'] ?? 0 );
			$this->assertSame( (int) $wpdb->get_var( 'SELECT COUNT(*) FROM `rt_options`' ), $engine->last_verify_expected['options'] );
			$this->assertTrue( $state['verification']['row_counts_ok'] );
			return;
		}

		$runner = new Recording_Rewriter_Restore_Task_Runner();
		$runner->test_backup_dir = $this->dir;
		$runner->test_site_root  = $this->dir;
		$runner->fake_engine     = new Fake_Restore_Engine();

		$sub_state = array(
			'params'     => array( 'restore_id' => 'r1' ),
			// A URL pair keeps the same-prefix case reaching the rewriter at all: with no
			// pairs the runner skips the rewrite entirely and proves nothing about renames.
			'db_replay'  => array(
				'source_prefix' => $source_prefix,
				'banner_info'   => array( 'site_url' => 'https://old.example' ),
				'cursor'        => array( 'tables' => array_fill_keys( $stems, array( 'status' => 'ok' ) ) ),
			),
			'receiving'  => array(),
			'url_rewrite' => array(),
		);

		$method = new ReflectionMethod( MainWP_Child_Restore_Task_Runner::class, 'run_rewriting_urls' );
		$method->setAccessible( true );
		$method->invoke( $runner, $this->spine->get_task( $this->make_task( 'db', array( $this->make_db_artifact() ), array( 'source_prefix' => $source_prefix ) ) ), 1, $sub_state, microtime( true ) + 10, $this->dir );

		$this->assertNotNull( $runner->last_rewrite_config, 'the runner must reach the rewriter' );
		$this->assertSame( $expected, $runner->last_rewrite_config['rename_option_names'] );
	}

	/**
	 * Equal prefixes must not trigger a role-key rename.
	 *
	 * @return array
	 */
	public static function role_prefix_cases() {
		return array(
			'cross prefix' => array( 'wp_', array( 'rt_options' => array( 'wp_user_roles' => 'rt_user_roles' ) ) ),
			'case-only prefix' => array( 'RT_', array( 'rt_options' => array( 'RT_user_roles' => 'rt_user_roles' ) ) ),
			'same prefix'  => array( 'rt_', array() ),
			'multisite'    => array(
				'wp_',
				array(
					'rt_options'    => array( 'wp_user_roles' => 'rt_user_roles' ),
					'rt_2_options'  => array( 'wp_2_user_roles' => 'rt_2_user_roles' ),
					'rt_10_options' => array( 'wp_10_user_roles' => 'rt_10_user_roles' ),
				),
				array( 'options', '2_options', '10_options', 'plugin_options', '2_posts' ),
			),
		);
	}

	/**
	 * @dataProvider content_prefix_url_cases
	 */
	public function test_cross_prefix_rewrite_preserves_content_filenames_and_renames_keys( $change_url ) {
		global $wpdb;
		remove_filter( 'query', array( $this, '_create_temporary_tables' ) );
		remove_filter( 'query', array( $this, '_drop_temporary_tables' ) );
		foreach ( array( 'rt_items', 'rt_options', 'rt_usermeta' ) as $table ) {
			$this->assertNotFalse( $wpdb->query( 'DROP TABLE IF EXISTS `' . $table . '`' ) );
		}
		$this->assertNotFalse( $wpdb->query( 'CREATE TABLE `rt_items` (id INT NOT NULL, post_content LONGTEXT, PRIMARY KEY (id))' ) );
		$this->assertNotFalse( $wpdb->query( 'CREATE TABLE `rt_options` (option_id BIGINT NOT NULL AUTO_INCREMENT, option_name VARCHAR(191) NOT NULL, option_value LONGTEXT, autoload VARCHAR(20) DEFAULT \'yes\', PRIMARY KEY (option_id), UNIQUE KEY (option_name))' ) );
		$this->assertNotFalse( $wpdb->query( 'CREATE TABLE `rt_usermeta` (umeta_id BIGINT NOT NULL AUTO_INCREMENT, user_id BIGINT, meta_key VARCHAR(255), meta_value LONGTEXT, PRIMARY KEY (umeta_id))' ) );
		$old_url = $change_url ? 'https://old.example' : site_url();
		$content = 'wp_config.php my_wp_logo.png ' . $old_url . '/page';
		$roles = serialize( array( 'administrator' => array( 'capabilities' => array( 'manage_options' => true ) ) ) );
		$this->assertSame( 1, $wpdb->insert( 'rt_items', array( 'id' => 1, 'post_content' => $content ) ) );
		$this->assertSame( 1, $wpdb->insert( 'rt_options', array( 'option_name' => 'wp_user_roles', 'option_value' => $roles ) ) );
		$this->assertSame( 1, $wpdb->insert( 'rt_usermeta', array( 'user_id' => 1, 'meta_key' => 'wp_capabilities', 'meta_value' => serialize( array( 'administrator' => true ) ) ) ) );
		$sub_state = array(
			'params' => array( 'restore_id' => 'r1' ),
			'db_replay' => array(
				'source_prefix' => 'wp_',
				'banner_info' => array( 'site_url' => $old_url, 'home_url' => $change_url ? $old_url : home_url() ),
				'cursor' => array( 'tables' => array_fill_keys( array( 'items', 'options', 'usermeta' ), array( 'status' => 'ok', 'rows' => 1 ) ) ),
			),
			'receiving' => array(),
		);
		$method = new ReflectionMethod( MainWP_Child_Restore_Task_Runner::class, 'run_rewriting_urls' );
		$method->setAccessible( true );
		$method->invoke( $this->runner, $this->spine->get_task( $this->make_task( 'db', array( $this->make_db_artifact() ), array( 'source_prefix' => 'wp_' ) ) ), 1, $sub_state, microtime( true ) + 10, $this->dir );
		$this->assertSame( 'wp_config.php my_wp_logo.png ' . site_url() . '/page', $wpdb->get_var( 'SELECT post_content FROM `rt_items` WHERE id = 1' ) );
		$this->assertSame( array( array( 'option_name' => 'rt_user_roles', 'option_value' => $roles ) ), $wpdb->get_results( 'SELECT option_name, option_value FROM `rt_options`', ARRAY_A ) );
		$this->assertSame( array( 'rt_capabilities' ), $wpdb->get_col( 'SELECT meta_key FROM `rt_usermeta`' ) );
		$this->assertSame( serialize( array( 'administrator' => true ) ), $wpdb->get_var( 'SELECT meta_value FROM `rt_usermeta`' ) );
	}

	public static function content_prefix_url_cases() {
		return array(
			'changed URL' => array( true ),
			'same URL' => array( false ),
		);
	}

	/**
	 * Core meta keys are lookup names; only blog-derived user keys follow the prefix.
	 *
	 * @dataProvider meta_prefix_cases
	 */
	public function test_restore_runner_preserves_meta_keys_and_renames_user_keys( $stem, $source_prefix ) {
		global $wpdb;
		$table = 'rt_' . $stem;
		$this->identity_fixture_tables[] = $table;
		$this->assertNotFalse( $wpdb->query( 'CREATE TABLE `' . $table . '` (meta_id BIGINT NOT NULL AUTO_INCREMENT, meta_key VARCHAR(255), meta_value LONGTEXT, PRIMARY KEY (meta_id))' ) );
		$keys = array( '_wp_attached_file', '_wp_attachment_metadata', '_wp_page_template', '_wp_old_slug', '_menu_item_type', 'plugin_wp_key', 'wp_capabilities_extra', 'wp_user-settings' );
		foreach ( array( '', '2_', '10_' ) as $blog_suffix ) {
			foreach ( array( 'capabilities', 'user_level', 'persisted_preferences' ) as $key ) {
				$keys[] = $source_prefix . $blog_suffix . $key;
			}
		}
		$value = serialize( array( 'url' => 'https://old.example/wp_path' ) );
		foreach ( $keys as $key ) {
			$this->assertSame( 1, $wpdb->insert( $table, array( 'meta_key' => $key, 'meta_value' => $value ) ) );
		}
		$runner = new Recording_Rewriter_Restore_Task_Runner();
		$runner->test_backup_dir = $this->dir;
		$runner->test_site_root  = $this->dir;
		$runner->fake_engine     = new Fake_Restore_Engine();
		$stems = array( 'options', '2_options', '10_options', $stem );
		$sub_state = array(
			'params'    => array( 'restore_id' => 'r1' ),
			'db_replay' => array(
				'source_prefix' => $source_prefix,
				'banner_info'   => array( 'site_url' => 'https://old.example' ),
				'cursor'        => array( 'tables' => array_fill_keys( $stems, array( 'status' => 'ok' ) ) ),
			),
			'receiving' => array(),
		);
		$sub_state['db_replay']['cursor']['tables']['99_options'] = array( 'status' => 'failed' );
		$method = new ReflectionMethod( MainWP_Child_Restore_Task_Runner::class, 'run_rewriting_urls' );
		$method->setAccessible( true );
		$method->invoke( $runner, $this->spine->get_task( $this->make_task( 'db', array( $this->make_db_artifact() ), array( 'source_prefix' => $source_prefix ) ) ), 1, $sub_state, microtime( true ) + 10, $this->dir );

		$config = $runner->last_rewrite_config;
		$this->assertNotNull( $config );
		$this->assertContains( $table, $config['tables'] );
		$this->assertNotContains( 'rt_99_options', $config['tables'] );
		$config['tables'] = array( $table );
		$rewriter = new \MainWP\Child\MainWP_Backup_Url_Rewriter();
		$outcome = $rewriter->rewrite_slice( array(), $config, microtime( true ) + 10 );
		$this->assertTrue( $outcome['done'] );
		$this->assertSame( 0, $outcome['failed_updates'] );
		$expected_keys = $keys;
		if ( 'usermeta' === substr( $stem, -8 ) && 'wp_' === $source_prefix ) {
			foreach ( $expected_keys as &$key ) {
				if ( 0 === strpos( $key, 'wp_' ) ) {
					$key = 'rt_' . substr( $key, 3 );
				}
			}
			unset( $key );
		}
		$this->assertSame( $expected_keys, $wpdb->get_col( 'SELECT meta_key FROM `' . $table . '` ORDER BY meta_id' ) );
		$expected_value = serialize( array( 'url' => site_url() . '/wp_path' ) );
		$this->assertSame( array_fill( 0, count( $keys ), $expected_value ), $wpdb->get_col( 'SELECT meta_value FROM `' . $table . '` ORDER BY meta_id' ) );
	}

	public function test_restore_runner_preserves_attachment_filename_and_rewrites_url() {
		global $wpdb;
		$table = 'rt_postmeta';
		$this->identity_fixture_tables[] = $table;
		$this->assertNotFalse( $wpdb->query( 'CREATE TABLE `' . $table . '` (meta_id BIGINT NOT NULL AUTO_INCREMENT, meta_key VARCHAR(255), meta_value LONGTEXT, PRIMARY KEY (meta_id))' ) );
		$value = array( 'file' => 'my_wp_logo.png', 'url' => 'https://old.example/my_wp_logo.png' );
		$this->assertSame( 1, $wpdb->insert( $table, array( 'meta_key' => '_wp_attachment_metadata', 'meta_value' => serialize( $value ) ) ) );
		$runner = new Recording_Rewriter_Restore_Task_Runner();
		$runner->test_backup_dir = $this->dir;
		$runner->test_site_root = $this->dir;
		$runner->fake_engine = new Fake_Restore_Engine();
		$sub_state = array(
			'params' => array( 'restore_id' => 'r1' ),
			'db_replay' => array(
				'source_prefix' => 'wp_',
				'banner_info' => array( 'site_url' => 'https://old.example' ),
				'cursor' => array( 'tables' => array( 'postmeta' => array( 'status' => 'ok' ) ) ),
			),
			'receiving' => array(),
		);
		$method = new ReflectionMethod( MainWP_Child_Restore_Task_Runner::class, 'run_rewriting_urls' );
		$method->setAccessible( true );
		$method->invoke( $runner, $this->spine->get_task( $this->make_task( 'db', array( $this->make_db_artifact() ), array( 'source_prefix' => 'wp_' ) ) ), 1, $sub_state, microtime( true ) + 10, $this->dir );
		$rewriter = new \MainWP\Child\MainWP_Backup_Url_Rewriter();
		$outcome = $rewriter->rewrite_slice( array(), $runner->last_rewrite_config, microtime( true ) + 10 );
		$this->assertTrue( $outcome['done'] );
		$this->assertSame( 0, $outcome['failed_updates'] );
		$value['url'] = site_url() . '/my_wp_logo.png';
		$this->assertSame( serialize( $value ), $wpdb->get_var( "SELECT meta_value FROM `$table` WHERE meta_key = '_wp_attachment_metadata'" ) );
	}

	public static function meta_prefix_cases() {
		return array(
			'postmeta'        => array( 'postmeta', 'wp_' ),
			'blog postmeta'   => array( '2_postmeta', 'wp_' ),
			'termmeta'        => array( 'termmeta', 'wp_' ),
			'blog termmeta'   => array( '2_termmeta', 'wp_' ),
			'commentmeta'     => array( 'commentmeta', 'wp_' ),
			'blog commentmeta'=> array( '2_commentmeta', 'wp_' ),
			'sitemeta'        => array( 'sitemeta', 'wp_' ),
			'plugin meta'     => array( 'pluginmeta', 'wp_' ),
			'usermeta'        => array( 'usermeta', 'wp_' ),
			'blog usermeta'   => array( '2_usermeta', 'wp_' ),
			'same postmeta'   => array( 'postmeta', 'rt_' ),
			'same usermeta'   => array( 'usermeta', 'rt_' ),
		);
	}

	public function test_connection_identity_capture_is_not_replaced_on_reentry() {
		global $wpdb;
		$capture = new ReflectionMethod( MainWP_Child_Restore_Task_Runner::class, 'capture_connection_identity' );
		$capture->setAccessible( true );
		foreach ( array( false, true ) as $present ) {
			$this->assertNotFalse( $wpdb->query( 'DELETE FROM `rt_options`' ) );
			if ( $present ) {
				$this->assertSame( 1, $wpdb->insert( 'rt_options', array( 'option_name' => 'mainwp_child_pubkey', 'option_value' => 'live-key', 'autoload' => 'no' ) ) );
			}
			$state = array();
			$capture->invokeArgs( $this->runner, array( &$state ) );
			$original = $state['identity_preserve'];
			$this->assertCount( $present ? 1 : 0, $original );
			$this->assertNotFalse( $wpdb->query( 'DELETE FROM `rt_options`' ) );
			$this->assertSame( 1, $wpdb->insert( 'rt_options', array( 'option_name' => 'mainwp_child_pubkey', 'option_value' => 'dump-key', 'autoload' => 'yes' ) ) );
			$capture->invokeArgs( $this->runner, array( &$state ) );
			$this->assertSame( $original, $state['identity_preserve'] );
		}
	}

	public static function missing_identity_capture_cases() {
		return array(
			'absent capture'    => array( array() ),
			'non-array capture' => array( array( 'identity_preserve' => 'invalid' ) ),
			'scalar entry'      => array( array( 'identity_preserve' => array( 'invalid' ) ) ),
			'unnamed entry'     => array( array( 'identity_preserve' => array( array( 'x' => 1 ) ) ) ),
		);
	}

	/**
	 * @dataProvider missing_identity_capture_cases
	 */
	public function test_missing_identity_capture_leaves_live_rows_untouched( $state ) {
		global $wpdb;
		$names = array(
			'mainwp_child_pubkey',
			'mainwp_child_uniqueId',
			'mainwp_child_nonce',
			'mainwp_child_connected_admin',
			'mainwp_child_server',
			'mainwp_child_openssl_sign_algo',
			'mainwp_child_auth',
		);
		foreach ( $names as $name ) {
			$this->assertSame( 1, $wpdb->insert( 'rt_options', array( 'option_name' => $name, 'option_value' => 'live-' . $name, 'autoload' => 'no' ) ) );
		}
		$before = $wpdb->get_results( 'SELECT option_name, option_value, autoload FROM `rt_options` ORDER BY option_name', ARRAY_A );
		$original = $state;
		$restore = new ReflectionMethod( MainWP_Child_Restore_Task_Runner::class, 'restore_connection_identity' );
		$restore->setAccessible( true );
		$restore->invokeArgs( $this->runner, array( &$state ) );
		$this->assertSame( $before, $wpdb->get_results( 'SELECT option_name, option_value, autoload FROM `rt_options` ORDER BY option_name', ARRAY_A ) );
		$this->assertArrayNotHasKey( 'identity_preserve_delta', $state );
		$this->assertSame( $original, $state );
	}

	public function test_empty_identity_capture_deletes_dump_rows() {
		global $wpdb;
		$names = array(
			'mainwp_child_pubkey',
			'mainwp_child_uniqueId',
			'mainwp_child_nonce',
			'mainwp_child_connected_admin',
			'mainwp_child_server',
			'mainwp_child_openssl_sign_algo',
			'mainwp_child_auth',
		);
		foreach ( $names as $name ) {
			$this->assertSame( 1, $wpdb->insert( 'rt_options', array( 'option_name' => $name, 'option_value' => 'dump-' . $name, 'autoload' => 'yes' ) ) );
		}
		$state = array( 'identity_preserve' => array() );
		$restore = new ReflectionMethod( MainWP_Child_Restore_Task_Runner::class, 'restore_connection_identity' );
		$restore->setAccessible( true );
		$restore->invokeArgs( $this->runner, array( &$state ) );
		foreach ( $names as $name ) {
			$this->assertNull( $wpdb->get_var( $wpdb->prepare( 'SELECT option_value FROM `rt_options` WHERE option_name = %s', $name ) ), $name );
		}
		$this->assertSame( -7, $state['identity_preserve_delta'] );
	}

	public static function committed_swap_answers() {
		return array( 'committed' => array( array( true ) ), 'transient read failure' => array( array( null, true ) ) );
	}

	/**
	 * @dataProvider committed_swap_answers
	 */
	public function test_swapping_resume_skips_committed_swap_and_preserves_delta( $answers ) {
		global $wpdb;
		$this->runner->fake_engine->replay_yield_once = true;
		$task_id = $this->make_task( 'db', array( $this->make_db_artifact() ) );
		$task = $this->spine->get_task( $task_id );
		for ( $slice = 0; $slice < 30 && MainWP_Child_Backup_Task::PHASE_REPLAYING_DB !== $task->phase; ++$slice ) {
			$task = $this->runner->advance_one_slice( $task, MainWP_Child_Backup_Task_Runner::LEASE_OWNER_DASHBOARD );
		}
		$this->assertSame( MainWP_Child_Backup_Task::PHASE_REPLAYING_DB, $task->phase );
		$state = $this->spine->read_sub_state( $task, $this->dir );
		$state['db_replay']['cursor']['tables'] = $this->runner->fake_engine->tables;
		$state['identity_preserve'] = array();
		foreach ( array( 'mainwp_child_pubkey', 'mainwp_child_uniqueId', 'mainwp_child_nonce' ) as $name ) {
			$state['identity_preserve'][] = array( 'option_name' => $name, 'option_value' => 'live-' . $name, 'autoload' => 'no' );
		}
		$state['identity_dump_count'] = 2;
		// The previous slice inserted the first row before it stopped; the dump had only two.
		foreach ( array( 'mainwp_child_pubkey', 'mainwp_child_nonce', 'mainwp_child_auth' ) as $name ) {
			$this->assertSame( 1, $wpdb->insert( 'rt_options', array( 'option_name' => $name, 'option_value' => 'partial-' . $name, 'autoload' => 'yes' ) ) );
		}
		$this->assertNotFalse( $this->spine->checkpoint( $task_id, (int) $task->version, array( 'phase' => MainWP_Child_Backup_Task::PHASE_SWAPPING_DB, 'sub_state' => $state ) ) );
		$task = $this->spine->get_task( $task_id );
		$this->assertSame( MainWP_Child_Backup_Task::PHASE_SWAPPING_DB, $task->phase );
		$state = $this->spine->read_sub_state( $task, $this->dir );
		$engine = $this->runner->fake_engine;
		$engine->swap_committed = true;
		$engine->swap_committed_answers = $answers;
		$engine->swap_error = 'A committed swap must never run again';
		$engine->committed_old_tables = array( $engine->old_prefix( $state['params']['restore_id'] ) . 'options' );
		$swap = new ReflectionMethod( MainWP_Child_Restore_Task_Runner::class, 'run_swapping_db' );
		$swap->setAccessible( true );
		$swap->invokeArgs( $this->runner, array( $task, (int) $task->version, &$state, $this->dir ) );
		$task = $this->spine->get_task( $task_id );
		$this->assertSame( MainWP_Child_Backup_Task::PHASE_REWRITING_URLS, $task->phase );
		$state = $this->spine->read_sub_state( $task, $this->dir );
		$this->assertSame( 1, $state['identity_preserve_delta'] );
		$this->assertSame( $engine->committed_old_tables, $state['old_tables'] );
		$this->assertCount( count( $answers ), array_keys( $engine->calls, 'is_swap_committed', true ) );
		$this->assertCount( 0, preg_grep( '/^swap_tables:/', $engine->calls ) );
		$this->assertContains( 'exit_maintenance', $engine->calls );
		$this->assertGreaterThan( 0, $this->runner->cache_flushes );
		foreach ( array( 'mainwp_child_pubkey', 'mainwp_child_uniqueId', 'mainwp_child_nonce' ) as $name ) {
			$this->assertSame( array( 'option_value' => 'live-' . $name, 'autoload' => 'no' ), $wpdb->get_row( $wpdb->prepare( 'SELECT option_value, autoload FROM `rt_options` WHERE option_name = %s', $name ), ARRAY_A ) );
		}
		$this->assertNull( $wpdb->get_var( "SELECT option_value FROM `rt_options` WHERE option_name = 'mainwp_child_auth'" ) );
	}

	public function test_swap_committed_predicate_uses_targets_and_tmp_tables() {
		global $wpdb;
		$engine = new class() extends MainWP_Backup_Db_Restore_Engine {
			protected function connect() {
				return new stdClass();
			}

			protected function close_connection( $conn ) {}

			protected function query_rows( $conn, $sql ) {
				global $wpdb;
				$rows = $wpdb->get_results( $sql, ARRAY_A );
				return '' === $wpdb->last_error ? $rows : null;
			}
		};
		$config = array( 'restore_id' => 'identity_predicate', 'target_prefix' => 'rt_identity_predicate_' );
		$stems = array( 'items', 'options' );
		$tables = array( 'rt_identity_predicate_items', 'rt_identity_predicate_options', 'tmpidentity_predicate_options', 'oldidentity_predicate_items' );
		// SHOW TABLES cannot see WP_UnitTestCase's temporary fixtures.
		remove_filter( 'query', array( $this, '_create_temporary_tables' ) );
		remove_filter( 'query', array( $this, '_drop_temporary_tables' ) );
		try {
			foreach ( array_slice( $tables, 0, 2 ) as $table ) {
				$this->assertNotFalse( $wpdb->query( 'CREATE TABLE `' . $table . '` (id INT)' ) );
			}
			$old_tables = array( 'stale' );
			$this->assertTrue( $engine->is_swap_committed( $stems, $config, $old_tables ), 'A fresh target has no old tables.' );
			$this->assertSame( array(), $old_tables );
			$this->assertNotFalse( $wpdb->query( 'CREATE TABLE `oldidentity_predicate_items` (id INT)' ) );
			$this->assertTrue( $engine->is_swap_committed( $stems, $config, $old_tables ) );
			$this->assertSame( array( 'oldidentity_predicate_items' ), $old_tables );
			$this->assertNotFalse( $wpdb->query( 'CREATE TABLE `tmpidentity_predicate_options` (id INT)' ) );
			$this->assertFalse( $engine->is_swap_committed( $stems, $config, $old_tables ) );
			$this->assertNotFalse( $wpdb->query( 'DROP TABLE `tmpidentity_predicate_options`' ) );
			$this->assertNotFalse( $wpdb->query( 'DROP TABLE `rt_identity_predicate_options`' ) );
			$this->assertFalse( $engine->is_swap_committed( $stems, $config, $old_tables ), 'Every target stem must exist.' );
			$this->assertFalse( $engine->is_swap_committed( array(), $config, $old_tables ) );
			$fail_show = static function ( $sql ) {
				return 'SHOW TABLES' === $sql ? 'SELECT mainwp_identity_forced_show_failure' : $sql;
			};
			$suppress = $wpdb->suppress_errors( true );
			add_filter( 'query', $fail_show );
			try {
				$this->assertNull( $engine->is_swap_committed( $stems, $config, $old_tables ) );
			} finally {
				remove_filter( 'query', $fail_show );
				$wpdb->suppress_errors( $suppress );
			}
		} finally {
			foreach ( $tables as $table ) {
				$wpdb->query( 'DROP TABLE IF EXISTS `' . $table . '`' );
			}
			add_filter( 'query', array( $this, '_create_temporary_tables' ) );
			add_filter( 'query', array( $this, '_drop_temporary_tables' ) );
		}
	}

	public function test_swapping_resume_without_committed_swap_runs_normal_swap() {
		global $wpdb;
		foreach ( array( false, null ) as $committed ) {
			$this->runner->fake_engine = new Fake_Restore_Engine();
			$this->runner->fake_engine->replay_yield_once = true;
			$task_id = $this->make_task( 'db', array( $this->make_db_artifact() ) );
			$task = $this->spine->get_task( $task_id );
			for ( $slice = 0; $slice < 30 && MainWP_Child_Backup_Task::PHASE_REPLAYING_DB !== $task->phase; ++$slice ) {
				$task = $this->runner->advance_one_slice( $task, MainWP_Child_Backup_Task_Runner::LEASE_OWNER_DASHBOARD );
			}
			$this->assertSame( MainWP_Child_Backup_Task::PHASE_REPLAYING_DB, $task->phase );
			$state = $this->spine->read_sub_state( $task, $this->dir );
			$state['db_replay']['cursor']['tables'] = $this->runner->fake_engine->tables;
			$state['identity_preserve'] = array();
			if ( null === $committed ) {
				foreach ( array( 'mainwp_child_pubkey', 'mainwp_child_nonce', 'mainwp_child_server' ) as $name ) {
					$this->assertSame( 1, $wpdb->insert( 'rt_options', array( 'option_name' => $name, 'option_value' => 'dump', 'autoload' => 'yes' ) ) );
					$state['identity_preserve'][] = array( 'option_name' => $name, 'option_value' => 'live-' . $name, 'autoload' => 'no' );
				}
			}
			$this->assertNotFalse( $this->spine->checkpoint( $task_id, (int) $task->version, array( 'phase' => MainWP_Child_Backup_Task::PHASE_SWAPPING_DB, 'sub_state' => $state ) ) );
			$this->assertSame( MainWP_Child_Backup_Task::PHASE_SWAPPING_DB, $this->spine->get_task( $task_id )->phase );
			$this->runner->fake_engine->swap_committed = $committed;
			if ( null === $committed ) {
				$this->runner->fake_engine->swap_error = 'Enumeration and swap failed';
			}
			$task = $this->drive( $task_id );
			$this->assertSame( null === $committed ? MainWP_Child_Backup_Task::PHASE_FAILED : MainWP_Child_Backup_Task::PHASE_COMPLETED, $task->phase );
			$this->assertContains( 'is_swap_committed', $this->runner->fake_engine->calls );
			if ( null === $committed ) {
				$this->assertNotContains( 'swap_tables:items', $this->runner->fake_engine->calls );
				// Three resume probes, then one more for the failure's outcome report.
				$this->assertCount( 4, array_keys( $this->runner->fake_engine->calls, 'is_swap_committed', true ) );
				foreach ( $state['identity_preserve'] as $row ) {
					$this->assertSame( $row, $wpdb->get_row( $wpdb->prepare( 'SELECT option_name, option_value, autoload FROM `rt_options` WHERE option_name = %s', $row['option_name'] ), ARRAY_A ) );
				}
				$this->assertSame( MainWP_Backup_Error::ERR_RESTORE_DB_REPLAY, $this->spine->status_payload( $task )['error_code'] );
			} else {
				$this->assertContains( 'swap_tables:items', $this->runner->fake_engine->calls );
			}
		}
	}

	public static function identity_retry_operations() {
		return array( 'write' => array( 'UPDATE' ), 'existence read' => array( 'SELECT option_name' ), 'baseline read' => array( 'SELECT COUNT(*)' ) );
	}

	/**
	 * @dataProvider identity_retry_operations
	 */
	public function test_identity_retry_after_third_row_matches_clean_delta( $operation ) {
		global $wpdb;
		$restore = new ReflectionMethod( MainWP_Child_Restore_Task_Runner::class, 'restore_connection_identity' );
		$restore->setAccessible( true );
		$saved = array();
		foreach ( array( 'mainwp_child_pubkey', 'mainwp_child_uniqueId', 'mainwp_child_nonce' ) as $name ) {
			$saved[] = array( 'option_name' => $name, 'option_value' => 'live-' . $name, 'autoload' => 'no' );
		}
		$clean_rows = null;
		$clean_delta = null;
		foreach ( array( false, true ) as $fail_once ) {
			$this->assertNotFalse( $wpdb->query( 'DELETE FROM `rt_options`' ) );
			foreach ( array( 'mainwp_child_nonce', 'mainwp_child_auth' ) as $name ) {
				$this->assertSame( 1, $wpdb->insert( 'rt_options', array( 'option_name' => $name, 'option_value' => 'dump', 'autoload' => 'yes' ) ) );
			}
			$attempts = 0;
			$fail_write = static function ( $sql ) use ( $fail_once, $operation, &$attempts ) {
				if ( 0 === strpos( $sql, $operation ) && false !== strpos( $sql, '`rt_options`' ) && false !== strpos( $sql, 'mainwp_child_nonce' ) ) {
					++$attempts;
					if ( $fail_once && 1 === $attempts ) {
						return 'SELECT mainwp_identity_forced_write_failure';
					}
				}
				return $sql;
			};
			$state = array( 'identity_preserve' => $saved );
			add_filter( 'query', $fail_write );
			try {
				$restore->invokeArgs( $this->runner, array( &$state ) );
			} finally {
				remove_filter( 'query', $fail_write );
			}
			$rows = $wpdb->get_results( 'SELECT option_name, option_value, autoload FROM `rt_options` ORDER BY option_name', ARRAY_A );
			$this->assertSame( ( $fail_once ? 2 : 1 ) + ( 'SELECT COUNT(*)' === $operation ? 1 : 0 ), $attempts );
			$this->assertSame( 1, $state['identity_preserve_delta'] );
			if ( ! $fail_once ) {
				$clean_rows = $rows;
				$clean_delta = $state['identity_preserve_delta'];
			} else {
				$this->assertSame( $clean_rows, $rows );
				$this->assertSame( $clean_delta, $state['identity_preserve_delta'] );
			}
			$this->assertCount( 3, $rows );
			foreach ( $rows as $row ) {
				$this->assertSame( 'live-' . $row['option_name'], $row['option_value'] );
				$this->assertSame( 'no', $row['autoload'] );
			}
		}
		// A second call with the persisted capture cannot turn the delta into zero.
		$restore->invokeArgs( $this->runner, array( &$state ) );
		$this->assertSame( 1, $state['identity_preserve_delta'] );
	}

	public static function identity_persistent_failure_operations() {
		return array_merge( self::identity_retry_operations(), array( 'read then write' => array( 'read then write' ) ) );
	}

	/**
	 * @dataProvider identity_persistent_failure_operations
	 */
	public function test_identity_persistent_failure_preserves_error_message( $operation ) {
		$restore = new ReflectionMethod( MainWP_Child_Restore_Task_Runner::class, 'restore_connection_identity' );
		$restore->setAccessible( true );
		$state = array( 'identity_preserve' => array( array( 'option_name' => 'mainwp_child_pubkey', 'option_value' => 'live-key', 'autoload' => 'no' ) ) );
		global $wpdb;
		$this->assertSame( 1, $wpdb->insert( 'rt_options', array( 'option_name' => 'mainwp_child_pubkey', 'option_value' => 'dump-key', 'autoload' => 'no' ) ) );
		$attempts = 0;
		$fail = static function ( $sql ) use ( $operation, &$attempts ) {
			$failed_operation = $operation;
			if ( 'read then write' === $operation ) {
				$failed_operation = 0 === $attempts ? 'SELECT option_name' : 'UPDATE';
			}
			if ( 0 === strpos( $sql, $failed_operation ) && false !== strpos( $sql, '`rt_options`' ) ) {
				++$attempts;
				return 'SELECT mainwp_identity_forced_read_or_write_failure';
			}
			return $sql;
		};
		add_filter( 'query', $fail );
		try {
			try {
				$restore->invokeArgs( $this->runner, array( &$state ) );
				$this->fail( 'Persistent identity failure must exhaust the retries.' );
			} catch ( MainWP_Exception $e ) {
				$this->assertSame( in_array( $operation, array( 'UPDATE', 'read then write' ), true ) ? 'Could not restore the live Child connection identity.' : 'Could not read the restored Child connection identity.', $e->getMessage() );
				$this->assertSame( MainWP_Backup_Error::ERR_RESTORE_DB_REPLAY, $e->get_message_error_code() );
				$this->assertSame( 3, $attempts );
				$this->assertArrayNotHasKey( 'identity_preserve_delta', $state );
			}
		} finally {
			remove_filter( 'query', $fail );
		}
	}

	public function test_restore_keeps_previously_recorded_request_rejected() {
		global $wpdb;
		$name = 'mainwp_child_advanced_request_id_' . hash( 'sha256', 'already-authenticated' );
		$this->assertSame( 1, $wpdb->insert( 'rt_options', array( 'option_name' => $name, 'option_value' => (string) time(), 'autoload' => 'off' ) ) );
		$this->runner->fake_engine->on_database_live = function () use ( $wpdb ) {
			$this->assertNotFalse( $wpdb->query( 'DELETE FROM `rt_options`' ) );
		};
		$task_id = $this->make_task( 'db', array( $this->make_db_artifact() ) );
		$this->assertSame( MainWP_Child_Backup_Task::PHASE_COMPLETED, $this->drive( $task_id )->phase );
		// add_option() targets WordPress's prefix; the unique-name collision is identical on rt_options.
		$suppress = $wpdb->suppress_errors( true );
		try {
			$this->assertFalse( $wpdb->insert( 'rt_options', array( 'option_name' => $name, 'option_value' => (string) time(), 'autoload' => 'off' ) ) );
			$this->assertStringContainsString( 'Duplicate entry', $wpdb->last_error );
		} finally {
			$wpdb->suppress_errors( $suppress );
		}
	}

	/**
	 * Terminal scrubbing must resolve sidecars in the runner's directory.
	 */
	public function test_oversized_restore_cursor_completes_without_resume_secrets() {
		$task_id = $this->make_task( 'files', array( $this->make_part_artifact() ) );
		$task    = $this->spine->get_task( $task_id );
		$cursor  = $this->spine->read_sub_state( $task, $this->dir );
		$cursor['params']['pull_manifest'] = array( 'artifacts' => array( array( 'url' => 'https://example.test/part.zip?signature=private123' ) ) );
		$cursor['identity_preserve'] = array( array( 'option_name' => 'mainwp_child_pubkey', 'option_value' => 'private-connection-value', 'autoload' => 'yes' ) );
		$cursor['padding'] = str_repeat( 'x', MainWP_Child_Backup_Task::SUB_STATE_INLINE_MAX + 1 );
		$serialized = $this->spine->write_sub_state( $task_id, $cursor, $this->dir, (int) $task->version );
		$this->assertNotFalse( $serialized );
		$this->assertTrue( json_decode( $serialized, true )['_sidecar'] );
		$this->assertNotFalse( $this->spine->checkpoint( $task_id, (int) $task->version, array( 'phase' => MainWP_Child_Backup_Task::PHASE_POST_RESTORE_VERIFY, 'sub_state' => $serialized ) ) );

		$task = $this->drive( $task_id );
		$this->assertSame( MainWP_Child_Backup_Task::PHASE_COMPLETED, $task->phase );
		$stored = json_decode( $task->sub_state, true );
		$this->assertArrayNotHasKey( '_sidecar', $stored );
		$this->assertArrayNotHasKey( 'pull_manifest', $stored['params'] );
		$this->assertArrayNotHasKey( 'identity_preserve', $stored );
		$this->assertTrue( $stored['identity_preserved'] );
		$this->assertTrue( $stored['verification']['sentinel_ok'] );
		$this->assertStringNotContainsString( 'signature=private123', $task->sub_state );
		$this->assertStringNotContainsString( 'private-connection-value', $task->sub_state );
	}

	public function test_swap_preserves_dump_ledger_beyond_live_capture_cap() {
		global $wpdb;
		$engine = $this->runner->fake_engine;
		$engine->tables = array( 'options' => array( 'status' => 'ok', 'rows' => 2001 ) );
		$engine->verify_real_counts = true;
		$task_id = $this->make_task( 'db', array( $this->make_db_artifact() ) );
		$initial = $this->spine->read_sub_state( $this->spine->get_task( $task_id ), $this->dir );
		$tmp_table = $engine->tmp_prefix( $initial['params']['restore_id'] ) . 'options';
		$overflow = 'mainwp_child_blocked_request_id_' . hash( 'sha256', '0' );
		try {
			$this->assertNotFalse( $wpdb->query( 'CREATE TABLE `' . $tmp_table . '` LIKE `rt_options`' ) );
			$values = array();
			// Exceed both the former cap and the reduced cap so the original deletion fails this test.
			for ( $i = 0; $i <= 2000; ++$i ) {
				$values[] = $wpdb->prepare( '(%s, %s, %s)', 'mainwp_child_blocked_request_id_' . hash( 'sha256', (string) $i ), 'blocked', 'off' );
			}
			$this->assertSame( 2001, $wpdb->query( 'INSERT INTO `rt_options` (option_name, option_value, autoload) VALUES ' . implode( ',', $values ) ) );
			$this->assertSame( 2001, $wpdb->query( 'INSERT INTO `' . $tmp_table . '` SELECT * FROM `rt_options`' ) );
			$engine->on_database_live = function () use ( $wpdb, $tmp_table ) {
				$this->assertNotFalse( $wpdb->query( 'DELETE FROM `rt_options`' ) );
				$this->assertSame( 2001, $wpdb->query( 'INSERT INTO `rt_options` SELECT * FROM `' . $tmp_table . '`' ) );
			};
			$task = $this->drive( $task_id );
			$this->assertSame( MainWP_Child_Backup_Task::PHASE_COMPLETED, $task->phase );
			$this->assertContains( 'swap_tables:options', $engine->calls );
			$this->assertNotContains( 'is_swap_committed', $engine->calls );
			$this->assertSame( 'blocked', $wpdb->get_var( $wpdb->prepare( 'SELECT option_value FROM `rt_options` WHERE option_name = %s', $overflow ) ) );
			$this->assertSame( 2001, $engine->last_verify_expected['options'] );
		} finally {
			$wpdb->query( 'DROP TABLE IF EXISTS `' . $tmp_table . '`' );
			$wpdb->query( 'DELETE FROM `rt_options`' );
			$wpdb->delete( $this->spine->get_table_name(), array( 'task_id' => $task_id ) );
			$this->commit_transaction();
		}
	}

	public function test_ledger_batches_retry_with_exact_identity_first_and_stable_delta() {
		global $wpdb;
		$saved = array( array( 'option_name' => 'mainwp_child_pubkey', 'option_value' => 'live-key', 'autoload' => 'no' ) );
		for ( $i = 0; $i < 501; ++$i ) {
			$saved[] = array( 'option_name' => 'mainwp_child_request_id_' . $i, 'option_value' => "opaque'\\value", 'autoload' => 'off' );
		}
		$this->assertSame( 1, $wpdb->insert( 'rt_options', array( 'option_name' => 'mainwp_child_blocked_request_id_dump', 'option_value' => 'blocked', 'autoload' => 'off' ) ) );
		$this->assertSame( 1, $wpdb->insert( 'rt_options', array( 'option_name' => 'mainwp_child_request_id_0', 'option_value' => 'dump', 'autoload' => 'yes' ) ) );
		$state = array( 'identity_preserve' => $saved );
		$batches = 0;
		$identity_written = false;
		$fail_batch = function ( $sql ) use ( &$batches, &$identity_written ) {
			if ( 0 === strpos( $sql, 'INSERT INTO `rt_options`' ) || 0 === strpos( $sql, 'UPDATE `rt_options`' ) ) {
				if ( false !== strpos( $sql, 'mainwp_child_pubkey' ) ) {
					$identity_written = true;
				}
				if ( false !== strpos( $sql, 'mainwp_child_request_id_' ) ) {
					$this->assertTrue( $identity_written );
					++$batches;
					if ( 2 === $batches ) {
						return 'SELECT mainwp_identity_forced_batch_failure';
					}
				}
			}
			return $sql;
		};
		$restore = new ReflectionMethod( MainWP_Child_Restore_Task_Runner::class, 'restore_connection_identity' );
		$restore->setAccessible( true );
		add_filter( 'query', $fail_batch );
		try {
			$restore->invokeArgs( $this->runner, array( &$state ) );
		} finally {
			remove_filter( 'query', $fail_batch );
		}
		$this->assertSame( 5, $batches );
		$this->assertSame( 2, $state['identity_dump_count'] );
		$this->assertSame( 501, $state['identity_preserve_delta'] );
		$this->assertSame( '503', $wpdb->get_var( 'SELECT COUNT(*) FROM `rt_options`' ) );
		$this->assertSame( '1', $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM `rt_options` WHERE option_name = %s', 'mainwp_child_request_id_0' ) ) );
		$this->assertSame( 'blocked', $wpdb->get_var( $wpdb->prepare( 'SELECT option_value FROM `rt_options` WHERE option_name = %s', 'mainwp_child_blocked_request_id_dump' ) ) );
		foreach ( $saved as $row ) {
			$this->assertSame( $row, $wpdb->get_row( $wpdb->prepare( 'SELECT option_name, option_value, autoload FROM `rt_options` WHERE option_name = %s', $row['option_name'] ), ARRAY_A ) );
		}
		$restore->invokeArgs( $this->runner, array( &$state ) );
		$this->assertSame( 501, $state['identity_preserve_delta'] );
		$this->assertSame( '503', $wpdb->get_var( 'SELECT COUNT(*) FROM `rt_options`' ) );
		$this->assertSame( '1', $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM `rt_options` WHERE option_name = %s', 'mainwp_child_request_id_0' ) ) );
	}

	public function test_ledger_batches_retry_without_unique_option_name_key() {
		global $wpdb;
		try {
			$this->assertNotFalse( $wpdb->query( 'ALTER TABLE `rt_options` DROP INDEX option_name' ) );
			$this->test_ledger_batches_retry_with_exact_identity_first_and_stable_delta();
		} finally {
			$wpdb->query( 'DELETE FROM `rt_options`' );
			$wpdb->query( 'ALTER TABLE `rt_options` ADD UNIQUE KEY option_name (option_name)' );
			$this->commit_transaction();
		}
	}

	public function test_identity_capture_caps_each_ledger_by_newest_option_id() {
		global $wpdb;
		$limit = MainWP_Child_Restore_Task_Runner::IDENTITY_LEDGER_CAPTURE_LIMIT;
		$this->assertSame( 500, $limit );
		$prefixes = array( 'mainwp_child_request_id_', 'mainwp_child_blocked_request_id_', 'mainwp_child_advanced_request_id_' );
		foreach ( $prefixes as $prefix ) {
			$values = array();
			for ( $i = 0; $i <= $limit; ++$i ) {
				$values[] = $wpdb->prepare( '(%s, %s, %s)', $prefix . hash( 'sha256', (string) $i ), (string) ( 1750000000 - $i ), 'off' );
			}
			$this->assertSame( $limit + 1, $wpdb->query( 'INSERT INTO `rt_options` (option_name, option_value, autoload) VALUES ' . implode( ',', $values ) ) );
		}
		$unrelated = 'mainwpXchildXrequestXidXunrelated';
		$this->assertSame( 1, $wpdb->insert( 'rt_options', array( 'option_name' => $unrelated, 'option_value' => 'keep', 'autoload' => 'yes' ) ) );
		$capture = new ReflectionMethod( MainWP_Child_Restore_Task_Runner::class, 'capture_connection_identity' );
		$capture->setAccessible( true );
		$state = array();
		$capture->invokeArgs( $this->runner, array( &$state ) );
		$saved = array_column( $state['identity_preserve'], null, 'option_name' );
		$this->assertCount( 3 * $limit, $saved );
		$this->assertArrayNotHasKey( $unrelated, $saved );
		foreach ( $prefixes as $prefix ) {
			$this->assertArrayNotHasKey( $prefix . hash( 'sha256', '0' ), $saved );
			$this->assertArrayHasKey( $prefix . hash( 'sha256', '1' ), $saved );
			$this->assertArrayHasKey( $prefix . hash( 'sha256', (string) $limit ), $saved );
		}
	}

	/**
	 * Ledger retention must survive either checkpoint representation.
	 */
	public function test_identity_capture_at_cap_round_trips() {
		global $wpdb;
		$limit = MainWP_Child_Restore_Task_Runner::IDENTITY_LEDGER_CAPTURE_LIMIT;
		$this->assertSame( 500, $limit );
		foreach ( MainWP_Child_Restore_Task_Runner::IDENTITY_PREFIXES as $prefix ) {
			$values = array();
			for ( $i = 0; $i < $limit; ++$i ) {
				$values[] = $wpdb->prepare( '(%s, %s, %s)', $prefix . hash( 'sha256', (string) $i ), (string) ( 1790000000 - $i ), 'off' );
			}
			$this->assertSame( $limit, $wpdb->query( 'INSERT INTO `rt_options` (option_name, option_value, autoload) VALUES ' . implode( ',', $values ) ) );
		}
		$identity = array(
			'mainwp_child_pubkey'                 => str_repeat( 'k', 800 ),
			'mainwp_child_uniqueId'               => str_repeat( 'u', 32 ),
			'mainwp_child_nonce'                  => str_repeat( 'n', 64 ),
			'mainwp_child_connected_admin'        => 'administrator',
			'mainwp_child_server'                 => 'https://dashboard.example/wp-admin/',
			'mainwp_child_auth'                   => str_repeat( 'a', 128 ),
			'mainwp_child_openssl_sign_algo'       => '7',
			'mainwp_child_backup_task_db_version' => '1',
		);
		$this->assertSame( MainWP_Child_Restore_Task_Runner::IDENTITY_OPTIONS, array_keys( $identity ) );
		foreach ( $identity as $name => $value ) {
			$this->assertSame( 1, $wpdb->insert( 'rt_options', array( 'option_name' => $name, 'option_value' => $value, 'autoload' => 'no' ) ) );
		}
		$expected = $wpdb->get_results( 'SELECT option_name, option_value, autoload FROM `rt_options` ORDER BY option_name', ARRAY_A );

		$this->runner->fake_engine->replay_yield_once = true;
		$task_id = $this->make_task( 'db', array( $this->make_db_artifact( true ) ), array( 'source_prefix' => 'rt_' ) );
		$task = $this->runner->advance_one_slice( $this->spine->get_task( $task_id ), MainWP_Child_Backup_Task_Runner::LEASE_OWNER_DASHBOARD );
		$this->assertSame( MainWP_Child_Backup_Task::PHASE_REPLAYING_DB, $task->phase );
		$state = $this->spine->read_sub_state( $task, $this->dir );
		$this->assertIsArray( $state );
		$this->assertCount( 3 * $limit + 8, $state['identity_preserve'] );
		$this->assertTrue( $state['db_replay']['snapshot_done'] );
		$this->assertNotEmpty( $state['fk_snapshot']['files'] );
		$this->assertArrayHasKey( 'params', $state );
		$this->assertTrue( $state['receiving']['adoption_done'] );
		$inline_task            = clone $task;
		$inline_task->sub_state = wp_json_encode( $state );
		$this->assertSame( $state, $this->spine->read_sub_state( $inline_task, $this->dir ) );
		$sidecar_state            = $state;
		$sidecar_state['padding'] = str_repeat( 'x', MainWP_Child_Backup_Task::SUB_STATE_INLINE_MAX );
		$sidecar_task             = clone $task;
		$sidecar_task->sub_state  = $this->spine->write_sub_state( $task_id, $sidecar_state, $this->dir, 999 );
		$this->assertTrue( json_decode( $sidecar_task->sub_state, true )['_sidecar'] );
		$restored = $this->spine->read_sub_state( $sidecar_task, $this->dir );
		$captured = $restored['identity_preserve'];
		usort(
			$captured,
			static function ( $left, $right ) {
				return strcmp( $left['option_name'], $right['option_name'] );
			}
		);
		$this->assertSame( $expected, $captured );
		$this->assertSame( $state['identity_preserve'], $restored['identity_preserve'] );
		$this->assertSame( $sidecar_state, $restored );
	}

	public function test_empty_identity_capture_keeps_dump_ledgers() {
		global $wpdb;
		foreach ( array( 'mainwp_child_request_id_', 'mainwp_child_blocked_request_id_', 'mainwp_child_advanced_request_id_' ) as $prefix ) {
			$this->assertSame( 1, $wpdb->insert( 'rt_options', array( 'option_name' => $prefix . hash( 'sha256', 'dump-only' ), 'option_value' => '1750000000', 'autoload' => 'off' ) ) );
		}
		$this->assertSame( 1, $wpdb->insert( 'rt_options', array( 'option_name' => 'mainwp_child_pubkey', 'option_value' => 'dump-key', 'autoload' => 'yes' ) ) );
		$unrelated = 'mainwpXchildXrequestXidXunrelated';
		$this->assertSame( 1, $wpdb->insert( 'rt_options', array( 'option_name' => $unrelated, 'option_value' => 'keep', 'autoload' => 'yes' ) ) );
		$state = array( 'identity_preserve' => array() );
		$restore = new ReflectionMethod( MainWP_Child_Restore_Task_Runner::class, 'restore_connection_identity' );
		$restore->setAccessible( true );
		$restore->invokeArgs( $this->runner, array( &$state ) );
		$this->assertSame( -1, $state['identity_preserve_delta'] );
		$this->assertNull( $wpdb->get_var( "SELECT option_name FROM `rt_options` WHERE option_name = 'mainwp_child_pubkey'" ) );
		$this->assertCount( 4, $wpdb->get_col( 'SELECT option_name FROM `rt_options`' ) );
		foreach ( MainWP_Child_Restore_Task_Runner::IDENTITY_PREFIXES as $prefix ) {
			$this->assertSame( '1750000000', $wpdb->get_var( $wpdb->prepare( 'SELECT option_value FROM `rt_options` WHERE option_name = %s', $prefix . hash( 'sha256', 'dump-only' ) ) ) );
		}
	}

	public static function identity_write_failure_cases() {
		return array(
			'swap update' => array( false, 'UPDATE' ),
			'swap insert' => array( false, 'INSERT' ),
			'swap delete' => array( false, 'DELETE' ),
			'FK update'   => array( true, 'UPDATE' ),
			'FK insert'   => array( true, 'INSERT' ),
			'FK delete'   => array( true, 'DELETE' ),
		);
	}

	/**
	 * @dataProvider identity_write_failure_cases
	 */
	public function test_identity_write_failure_fails_restore( $fk, $operation ) {
		global $wpdb;
		$this->seed_woo_tables();
		if ( 'DELETE' !== $operation ) {
			$this->assertSame( 1, $wpdb->insert( 'rt_options', array( 'option_name' => 'mainwp_child_pubkey', 'option_value' => 'live-key', 'autoload' => 'yes' ) ) );
		}
		$engine = $this->runner->fake_engine;
		$armed = false;
		$failed = 0;
		$engine->on_database_live = function () use ( $wpdb, $operation, $fk, &$armed ) {
			if ( ! $fk ) {
				$value = 'native|completed-backup|' . ( time() + 3600 );
				$this->assertTrue( add_option( MainWP_Child_Backup_Mutex::OPTION_NAME, $value, '', false ) );
			}
			$this->assertNotFalse( $wpdb->delete( 'rt_options', array( 'option_name' => 'mainwp_child_pubkey' ) ) );
			if ( 'INSERT' !== $operation ) {
				$this->assertSame( 1, $wpdb->insert( 'rt_options', array( 'option_name' => 'mainwp_child_pubkey', 'option_value' => 'dump-key', 'autoload' => 'no' ) ) );
			}
			$armed = true;
		};
		$fail_write = static function ( $sql ) use ( &$armed, &$failed, $operation ) {
			if ( $armed && 0 === strpos( $sql, $operation . ' ' ) && false !== strpos( $sql, '`rt_options`' ) && false !== strpos( $sql, 'mainwp_child_pubkey' ) ) {
				++$failed;
				return 'SELECT mainwp_identity_forced_write_failure';
			}
			return $sql;
		};
		$task_id = $this->make_task( 'db', array( $this->make_db_artifact( $fk ) ), $fk ? array( 'source_prefix' => 'rt_' ) : array() );
		add_filter( 'query', $fail_write );
		try {
			$task = $this->drive( $task_id );
		} finally {
			remove_filter( 'query', $fail_write );
		}
		$this->assertSame( 3, $failed, 'Each failed write must exhaust exactly three attempts.' );
		$this->assertSame( MainWP_Child_Backup_Task::PHASE_FAILED, $task->phase );
		$state = $this->spine->read_sub_state( $task, $this->dir );
		if ( $fk ) {
			$this->assertSame( MainWP_Backup_Error::ERR_RESTORE_DB_REPLAY, $state['fk_rollback']['original_error_code'] );
			$this->assertContains( 'rollback_aside_slice', $engine->calls );
		} else {
			$this->assertSame( MainWP_Backup_Error::ERR_RESTORE_DB_REPLAY, $this->spine->status_payload( $task )['error_code'] );
			$this->assertSame( '', MainWP_Child_Backup_Mutex::raw_value() );
			$this->assertFalse( get_option( MainWP_Child_Backup_Mutex::OPTION_NAME ) );
		}
		$this->assertNotContains( 'drop_old_tables', $engine->calls );
		$this->assertNotContains( 'verify_row_counts', $engine->calls );
	}

	public function test_terminal_failure_after_database_live_clears_captured_mutex() {
		foreach ( array( false, true ) as $fk ) {
			$engine = new class() extends Fake_Restore_Engine {
				public $on_live;

				public function verify_row_counts( $expected, $target_prefix ) {
					if ( ! empty( $this->last_replay_config['fk_path'] ) ) {
						throw new MainWP_Exception( 'Forced verification failure', null, MainWP_Backup_Error::ERR_RESTORE_DB_REPLAY );
					}
					return parent::verify_row_counts( $expected, $target_prefix );
				}

				public function replay_slice( $cursor, $config, $deadline, $unclean = false ) {
					$outcome = parent::replay_slice( $cursor, $config, $deadline, $unclean );
					if ( $outcome['done'] && ! empty( $config['fk_path'] ) ) {
						call_user_func( $this->on_live );
					}
					return $outcome;
				}

				public function swap_tables( $stems, $config ) {
					$outcome = parent::swap_tables( $stems, $config );
					if ( $outcome['done'] ) {
						call_user_func( $this->on_live );
					}
					return $outcome;
				}
			};
			$seeded = 0;
			$engine->on_live = function () use ( &$seeded ) {
				++$seeded;
				$value = 'native|completed-backup|' . ( time() + 3600 );
				$this->assertTrue( add_option( MainWP_Child_Backup_Mutex::OPTION_NAME, $value, '', false ) );
				wp_cache_set( MainWP_Child_Backup_Mutex::OPTION_NAME, $value, 'options' );
			};
			$engine->verify_ok = false;
			$this->runner->fake_engine = $engine;
			$task_id = $this->make_task( 'db', array( $this->make_db_artifact( $fk ) ), $fk ? array( 'source_prefix' => 'rt_' ) : array() );
			try {
				$task = $this->drive( $task_id );
				$this->assertSame( 1, $seeded );
				$this->assertSame( MainWP_Child_Backup_Task::PHASE_FAILED, $task->phase );
				$this->assertSame( MainWP_Backup_Error::ERR_RESTORE_DB_REPLAY, $this->spine->status_payload( $task )['error_code'] );
				$this->assertNull( MainWP_Child_Backup_Mutex::holder() );
			} finally {
				delete_option( MainWP_Child_Backup_Mutex::OPTION_NAME );
			}
		}
	}

	public function test_failed_database_paths_preserve_live_mutex() {
		foreach ( array( false, true ) as $fk ) {
			$this->runner->fake_engine = new Fake_Restore_Engine();
			if ( $fk ) {
				$this->runner->fake_engine->replay_error = 'Forced replay failure';
			} else {
				$this->runner->fake_engine->swap_error = 'Forced swap failure';
			}
			$task_id = $this->make_task( 'db', array( $this->make_db_artifact( $fk ) ), $fk ? array( 'source_prefix' => 'rt_' ) : array() );
			$this->assertTrue( MainWP_Child_Backup_Mutex::acquire( 'native', 'live-owner', 3600 ) );
			$holder = MainWP_Child_Backup_Mutex::holder();
			try {
				$task = $this->drive( $task_id );
				$this->assertSame( MainWP_Child_Backup_Task::PHASE_FAILED, $task->phase );
				$this->assertSame( $fk ? MainWP_Backup_Error::ERR_RESTORE_ROLLED_BACK : MainWP_Backup_Error::ERR_RESTORE_SWAP_FAILED, $this->spine->status_payload( $task )['error_code'] );
				$this->assertSame( $holder, MainWP_Child_Backup_Mutex::holder() );
			} finally {
				MainWP_Child_Backup_Mutex::release( 'live-owner' );
			}
		}
	}

	public function test_restore_create_reclaims_abandoned_backup_mutex() {
		global $wpdb;
		$dead_id = $this->spine->create_task( 'full', array() );
		$this->assertIsString( $dead_id );
		$this->assertTrue( MainWP_Child_Backup_Mutex::acquire( 'native', $dead_id, 3600 ) );
		try {
			$this->assertSame( 1, $wpdb->query( $wpdb->prepare( 'UPDATE ' . $this->spine->get_table_name() . ' SET started_at = DATE_SUB(NOW(), INTERVAL 901 SECOND) WHERE task_id = %s', $dead_id ) ) );
			$task_id = $this->make_task( 'db', array() );
			$this->assertIsString( $task_id );
			$this->assertSame( MainWP_Child_Backup_Task::PHASE_FAILED, $this->spine->get_task( $dead_id )->phase );
			$this->assertSame( $task_id, $this->spine->get_active_tasks()[0]->task_id );
			$this->assertNull( MainWP_Child_Backup_Mutex::holder() );
		} finally {
			MainWP_Child_Backup_Mutex::release( $dead_id );
		}
	}

	public function test_restore_create_reclaims_deadline_expired_task() {
		global $wpdb;
		$dead_id = $this->spine->create_task( 'restore_db', array() );
		$this->assertIsString( $dead_id );
		$this->assertSame( 1, $wpdb->query( $wpdb->prepare( 'UPDATE ' . $this->spine->get_table_name() . ' SET max_wall_clock_at = DATE_SUB(NOW(), INTERVAL 1 SECOND) WHERE task_id = %s', $dead_id ) ) );

		$task_id = $this->make_task( 'db', array() );

		$this->assertIsString( $task_id );
		$this->assertNotSame( $dead_id, $task_id );
		$this->assertSame( MainWP_Child_Backup_Task::PHASE_FAILED, $this->spine->get_task( $dead_id )->phase );
		$this->assertSame( MainWP_Backup_Error::ERR_WALL_CLOCK, $this->spine->status_payload( $this->spine->get_task( $dead_id ) )['error_code'] );
		$this->assertSame( $task_id, $this->spine->get_active_tasks()[0]->task_id );
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
	 * The live Woo preflight reports the same order-storage options the
	 * preserve gate reads in the apply phase, so the dashboard can refuse
	 * a doomed preserve-orders restore at initiate time instead of after
	 * the whole transfer.
	 */
	public function test_restore_preflight_returns_live_woo_options() {
		$this->seed_woo_tables();
		$this->assertSame(
			array(
				'woo_hpos_enabled' => true,
				'woo_sync_enabled' => false,
				'woo_db_version'   => '9.4.0',
			),
			$this->runner->live_woo_preflight()
		);
	}

	/**
	 * A store still synchronizing orders to the posts table reads back
	 * exactly as the apply-phase gate would judge it.
	 */
	public function test_restore_preflight_reflects_sync_mode_store() {
		$this->seed_woo_tables( array( 'woocommerce_custom_orders_table_data_sync_enabled' => 'yes' ) );

		$preflight = $this->runner->live_woo_preflight();

		$this->assertTrue( $preflight['woo_hpos_enabled'] );
		$this->assertTrue( $preflight['woo_sync_enabled'] );
	}

	/**
	 * The dashboard reaches the live preflight through a registered
	 * callable.
	 */
	public function test_restore_preflight_callable_registered() {
		$this->assertTrue( MainWP_Child_Callable::get_instance()->is_callable_function( 'restore_preflight' ) );
	}

	public static function customer_capture_reads() {
		return array(
			'customers' => array( 'SELECT u.* FROM `rt_users`' ),
			'usermeta'  => array( 'SELECT * FROM `rt_usermeta` WHERE user_id IN' ),
		);
	}

	/** @dataProvider customer_capture_reads */
	public function test_woo_customer_read_failure_stops_before_replay( $read ) {
		global $wpdb;
		$this->seed_woo_tables();
		$task_id = $this->make_task( 'db', array( $this->make_hpos_db_artifact() ), array( 'preserve_orders' => true, 'woo_order_since' => 0 ) );
		$filter = static function ( $query ) use ( $read ) {
			return 0 === strpos( $query, $read ) ? 'SELECT mainwp_forced_customer_read_failure' : $query;
		};
		$suppress = $wpdb->suppress_errors( true );
		add_filter( 'query', $filter );
		try {
			$task = $this->drive( $task_id );
		} finally {
			remove_filter( 'query', $filter );
			$wpdb->suppress_errors( $suppress );
		}
		$sub = $this->spine->read_sub_state( $task, $this->dir );
		$this->assertSame( MainWP_Child_Backup_Task::PHASE_FAILED, $task->phase );
		$this->assertSame( MainWP_Backup_Error::ERR_RESTORE_SNAPSHOT_FAILED, $sub['error']['code'] );
		$this->assertArrayNotHasKey( 'woo_capture', $sub );
		$this->assertNotContains( 'replay_slice', $this->runner->fake_engine->calls );
		$this->assertSame( 'buyer', $wpdb->get_var( 'SELECT user_login FROM `rt_users` WHERE ID = 50' ) );
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
	 * outcome, and skips the order tables. The swap itself enters and exits
	 * maintenance mode around the atomic rename, same as any swap-path
	 * restore (see run_swapping_db).
	 */
	public function test_preserve_pipeline_swap_path() {
		$this->seed_woo_tables();
		$task_id = $this->make_task( 'db', array( $this->make_hpos_db_artifact() ), array( 'preserve_orders' => true, 'woo_order_since' => 0 ) );
		$task    = $this->drive( $task_id );

		$this->assertSame( MainWP_Child_Backup_Task::PHASE_COMPLETED, $task->phase );
		$calls = $this->runner->fake_engine->calls;
		$this->assertContains( 'begin_maintenance', $calls );
		$this->assertContains( 'exit_maintenance', $calls );
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
	 * WooCommerce's analytics lookup tables are regenerated continuously by
	 * Action Scheduler jobs on a live store, so their counts drift between
	 * the dump and the post-swap check the same way the actionscheduler_*
	 * tables do. A drift there is not a failed restore; the real order
	 * tables stay under the strict check.
	 */
	public function test_verify_excludes_woo_analytics_tables() {
		global $wpdb;
		foreach ( array( 'rt_wc_orders', 'rt_wc_order_product_lookup', 'rt_wc_order_stats', 'rt_wc_order_tax_lookup', 'rt_wc_order_coupon_lookup', 'rt_wc_customer_lookup' ) as $analytics_table ) {
			$wpdb->query( 'CREATE TABLE IF NOT EXISTS `' . $analytics_table . '` (id BIGINT NOT NULL AUTO_INCREMENT, PRIMARY KEY (id))' ); // phpcs:ignore WordPress.DB -- test fixture.
		}

		$this->runner->fake_engine->tables = array(
			'items'                    => array( 'status' => 'ok', 'rows' => 2 ),
			'wc_orders'                => array( 'status' => 'ok', 'rows' => 3 ),
			'wc_order_product_lookup'  => array( 'status' => 'ok', 'rows' => 15 ),
			'wc_order_stats'           => array( 'status' => 'ok', 'rows' => 4 ),
			'wc_order_tax_lookup'      => array( 'status' => 'ok', 'rows' => 6 ),
			'wc_order_coupon_lookup'   => array( 'status' => 'ok', 'rows' => 1 ),
			'wc_customer_lookup'       => array( 'status' => 'ok', 'rows' => 8 ),
		);
		$task_id = $this->make_task( 'db', array( $this->make_db_artifact() ) );
		$task    = $this->drive( $task_id );

		$this->assertSame( MainWP_Child_Backup_Task::PHASE_COMPLETED, $task->phase );
		$expected = $this->runner->fake_engine->last_verify_expected;
		$this->assertArrayHasKey( 'items', $expected, 'Ordinary tables stay under the strict check.' );
		$this->assertArrayHasKey( 'wc_orders', $expected, 'Real order tables stay under the strict check.' );
		$this->assertArrayNotHasKey( 'wc_order_product_lookup', $expected );
		$this->assertArrayNotHasKey( 'wc_order_stats', $expected );
		$this->assertArrayNotHasKey( 'wc_order_tax_lookup', $expected );
		$this->assertArrayNotHasKey( 'wc_order_coupon_lookup', $expected );
		$this->assertArrayNotHasKey( 'wc_customer_lookup', $expected );
	}

	public function test_verify_remeasures_identity_after_new_request_ledger_row() {
		global $wpdb;
		$engine = $this->runner->fake_engine;
		$engine->tables = array( 'options' => array( 'status' => 'ok', 'rows' => 1 ) );
		$engine->verify_real_counts = true;
		$this->runner->yield_before_url_rewrite = true;
		$this->assertSame( 1, $wpdb->insert( 'rt_options', array( 'option_name' => 'mainwp_child_pubkey', 'option_value' => 'live-key', 'autoload' => 'no' ) ) );

		$task_id = $this->make_task( 'db', array( $this->make_db_artifact() ) );
		$task = $this->spine->get_task( $task_id );
		$initial = $this->spine->read_sub_state( $task, $this->dir );
		$tmp_table = $engine->tmp_prefix( $initial['params']['restore_id'] ) . 'options';
		$this->identity_fixture_tables[] = $tmp_table;
		$this->assertNotFalse( $wpdb->query( 'CREATE TABLE `' . $tmp_table . '` LIKE `rt_options`' ) );
		$this->assertSame( 1, $wpdb->insert( $tmp_table, array( 'option_name' => 'active_plugins', 'option_value' => 'a:0:{}', 'autoload' => 'yes' ) ) );
		$engine->on_database_live = function () use ( $wpdb, $tmp_table ) {
			$this->assertNotFalse( $wpdb->query( 'DELETE FROM `rt_options`' ) );
			$this->assertSame( 1, $wpdb->query( 'INSERT INTO `rt_options` SELECT * FROM `' . $tmp_table . '`' ) );
		};
		for ( $slice = 0; $slice < 30 && MainWP_Child_Backup_Task::PHASE_REWRITING_URLS !== $task->phase && ! MainWP_Child_Backup_Task::is_terminal_phase( $task->phase ); ++$slice ) {
			$task = $this->runner->advance_one_slice( $task, MainWP_Child_Backup_Task_Runner::LEASE_OWNER_DASHBOARD );
		}
		$this->assertSame( MainWP_Child_Backup_Task::PHASE_REWRITING_URLS, $task->phase );
		$this->assertContains( 'swap_tables:options', $engine->calls );
		$this->assertNotContains( 'verify_row_counts', $engine->calls );
		$state = $this->spine->read_sub_state( $task, $this->dir );
		$this->assertSame( 0, $state['identity_dump_count'] );
		$this->assertSame( 1, $state['identity_preserve_delta'] );

		// A later authenticated request adds a ledger row after the write-back delta is pinned.
		$this->assertSame( 1, $wpdb->insert( 'rt_options', array( 'option_name' => 'mainwp_child_request_id_' . hash( 'sha256', 'request-after-identity-write-back' ), 'option_value' => (string) time(), 'autoload' => 'off' ) ) );
		$old_expected = (int) $state['db_replay']['cursor']['tables']['options']['rows'] + $state['identity_preserve_delta'];
		$actual = (int) $wpdb->get_var( 'SELECT COUNT(*) FROM `rt_options`' );
		$this->assertSame( 2, $old_expected );
		$this->assertSame( $old_expected + 1, $actual, 'The pinned delta would reject the new request ledger row.' );

		$task = $this->drive( $task_id );
		$this->assertSame( MainWP_Child_Backup_Task::PHASE_COMPLETED, $task->phase );
		$this->assertSame( $actual, $engine->last_verify_expected['options'] );
		$state = $this->spine->read_sub_state( $task, $this->dir );
		$this->assertTrue( $state['verification']['row_counts_ok'] );
		$this->assertSame( 2, $state['verification']['identity_verify_delta'] );
		$this->assertSame( 1, $state['identity_preserve_delta'], 'Verification must retain the write-back evidence.' );
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
	 * phases. The swap itself still enters/exits maintenance mode around the
	 * atomic rename, same as any swap-path restore.
	 */
	public function test_no_preserve_skips_woo_phases() {
		$this->seed_woo_tables();
		$task_id = $this->make_task( 'db', array( $this->make_hpos_db_artifact() ) );
		$task    = $this->drive( $task_id );

		$this->assertSame( MainWP_Child_Backup_Task::PHASE_COMPLETED, $task->phase );
		$sub_state = $this->spine->read_sub_state( $task, $this->dir );
		$this->assertArrayNotHasKey( 'woo_capture', $sub_state );
		$this->assertArrayNotHasKey( 'woo_merge', $sub_state );
		$this->assertContains( 'begin_maintenance', $this->runner->fake_engine->calls );
		$this->assertContains( 'exit_maintenance', $this->runner->fake_engine->calls );
	}

	public function test_restore_slice_advances_without_a_backup_mutex() {
		$this->assertNull( MainWP_Child_Backup_Mutex::holder() );
		$task_id = $this->make_task( 'db', array( $this->make_db_artifact() ) );
		$before = $this->spine->get_task( $task_id );
		$this->assertTrue( MainWP_Child_Backup_Task::is_restore_type( $before->type ) );
		$mutex_queries = array();
		$record_mutex_query = function ( $sql ) use ( &$mutex_queries ) {
			if ( false !== strpos( $sql, MainWP_Child_Backup_Mutex::OPTION_NAME ) ) {
				$mutex_queries[] = $sql;
			}
			return $sql;
		};
		add_filter( 'query', $record_mutex_query );
		try {
			$after = $this->runner->advance_one_slice( $before, 'dashboard' );
			$this->assertGreaterThan( (int) $before->version, (int) $after->version );
			$this->assertNotSame( MainWP_Child_Backup_Task::PHASE_FAILED, $after->phase );
			$completed = $this->drive( $task_id );
			$this->assertSame( MainWP_Child_Backup_Task::PHASE_COMPLETED, $completed->phase );
			// An absent dump mutex needs only a capture read, never a delete.
			$this->assertCount( 1, $mutex_queries, 'Restore slices and terminal checkpoints must not acquire, refresh, or release the backup mutex.' );
			$this->assertStringStartsWith( 'SELECT option_value FROM', $mutex_queries[0] );
			$this->assertStringNotContainsString( 'LIKE', $mutex_queries[0] );
		} finally {
			remove_filter( 'query', $record_mutex_query );
		}
		$this->assertNull( MainWP_Child_Backup_Mutex::holder() );
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
	 * The swap path enters maintenance immediately before the atomic RENAME
	 * and exits immediately after, so live traffic is never served a
	 * transitional schema. Live tables are never touched before the swap
	 * (loading happens into isolated tmp tables), so the window starts right
	 * at the swap, not earlier.
	 */
	public function test_swap_path_enters_and_exits_maintenance_around_swap() {
		$task_id = $this->make_task( 'db', array( $this->make_db_artifact() ) );
		$task    = $this->drive( $task_id );

		$this->assertSame( MainWP_Child_Backup_Task::PHASE_COMPLETED, $task->phase );

		$calls          = $this->runner->fake_engine->calls;
		$begin_index    = array_search( 'begin_maintenance', $calls, true );
		$swap_index     = array_search( 'swap_tables:items', $calls, true );
		$exit_index     = array_search( 'exit_maintenance', $calls, true );

		$this->assertNotFalse( $begin_index, 'begin_maintenance must be called.' );
		$this->assertNotFalse( $exit_index, 'exit_maintenance must be called.' );
		$this->assertLessThan( $swap_index, $begin_index, 'maintenance must begin before the swap.' );
		$this->assertGreaterThan( $swap_index, $exit_index, 'maintenance must exit after the swap.' );
	}

	/**
	 * A swap failure still leaves the site out of maintenance: the runner's
	 * terminal cleanup unconditionally exits maintenance on any thrown
	 * failure, so a forced-terminal swap is not left stuck offline.
	 */
	public function test_swap_path_exits_maintenance_on_swap_failure() {
		$this->runner->fake_engine->swap_error = 'swap exploded';

		$task_id = $this->make_task( 'db', array( $this->make_db_artifact() ) );
		$task    = $this->drive( $task_id );

		$this->assertSame( MainWP_Child_Backup_Task::PHASE_FAILED, $task->phase );
		$this->assertContains( 'exit_maintenance', $this->runner->fake_engine->calls );
	}

	/**
	 * Maintenance is entered and exited exactly once across a completed swap
	 * path restore - it is never re-entered for the multi-slice URL-rewrite
	 * phase that follows the swap, matching the existing foreign-key path's
	 * precedent (it also exits maintenance before URL rewriting begins).
	 * Combined with the ordering proven in
	 * test_swap_path_enters_and_exits_maintenance_around_swap, this confirms
	 * the window is scoped to the swap alone.
	 */
	public function test_swap_path_maintenance_does_not_cover_rewrite_phase() {
		$task_id = $this->make_task( 'db', array( $this->make_db_artifact() ) );
		$task    = $this->drive( $task_id );

		$this->assertSame( MainWP_Child_Backup_Task::PHASE_COMPLETED, $task->phase );

		$calls = $this->runner->fake_engine->calls;
		$this->assertSame( 1, count( array_keys( $calls, 'begin_maintenance', true ) ) );
		$this->assertSame( 1, count( array_keys( $calls, 'exit_maintenance', true ) ) );
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

	public function test_tombstone_removed_during_failed_unlink_counts_as_deleted() {
		$directory = $this->site . '/unlink-race';
		mkdir( $directory );
		$target = $directory . '/stale.txt';
		file_put_contents( $target, 'old bytes' );
		$tombstones = $this->make_named_tombstones_artifact( 'race-tombstones.list', "unlink-race/stale.txt\n" );
		chmod( $directory, 0555 );
		try {
			clearstatcache( true, $directory );
			if ( is_writable( $directory ) ) { $this->markTestSkipped( 'The test user can delete from a read-only directory.' ); }
			$raced = false;
			set_error_handler( static function ( $severity, $message ) use ( $directory, $target, &$raced ) {
				if ( false !== strpos( $message, 'unlink(' ) && ! $raced ) {
					$raced = true;
					chmod( $directory, 0755 );
					unlink( $target );
				}
				return true;
			} );
			try {
				$result = ( new \MainWP\Child\MainWP_Child_Backup_File_Restorer() )->apply_tombstones( $this->dir . '/' . $tombstones['file'], $this->site );
			} finally {
				restore_error_handler();
			}
			$this->assertTrue( $raced, 'The fixture must exercise a failed unlink.' );
			$this->assertNull( $result['error'], 'Another process already deleted the tombstone.' );
			$this->assertNull( $result['error_code'] );
			$this->assertSame( 1, $result['deleted'] );
			$this->assertFileDoesNotExist( $target );
		} finally {
			chmod( $directory, 0755 );
		}
	}

	public function test_failed_tombstone_unlink_fails_restore_status() {
		$directory = $this->site . '/locked';
		mkdir( $directory );
		file_put_contents( $directory . '/stale.txt', 'undeleted bytes' );
		$tombstones = $this->make_named_tombstones_artifact( 'locked-tombstones.list', "locked/stale.txt\n" );
		chmod( $directory, 0555 );
		try {
			clearstatcache( true, $directory );
			if ( is_writable( $directory ) ) {
				$this->markTestSkipped( 'The test user can delete from a read-only directory.' );
			}
			$warnings = array();
			set_error_handler( static function ( $severity, $message ) use ( &$warnings ) {
				if ( error_reporting() & $severity ) { $warnings[] = $message; }
				return true;
			} );
			try {
				$task = $this->drive( $this->make_task( 'files', array( $tombstones ) ) );
			} finally {
				restore_error_handler();
			}
			$this->assertSame( MainWP_Child_Backup_Task::PHASE_FAILED, $task->phase, 'An undeleted tombstone must fail the restore.' );
			$payload = MainWP_Child_Backup_Task::instance()->status_payload( $task, $this->dir );
			$this->assertSame( MainWP_Backup_Error::ERR_RESTORE_EXTRACT, $payload['error_code'] );
			$this->assertStringContainsString( 'locked/stale.txt', $payload['error'] );
			$this->assertStringNotContainsString( $this->site, $payload['error'] );
			$this->assertSame( 'undeleted bytes', file_get_contents( $directory . '/stale.txt' ) );
			$this->assertSame( array(), $warnings );
		} finally {
			chmod( $directory, 0755 );
		}
	}

	/**
	 * Build a zip artifact with arbitrary entries in the backup dir.
	 *
	 * @param string $name    Archive file name.
	 * @param array  $entries relpath => content map.
	 * @param string $role    Artifact role ('full' or 'part').
	 *
	 * @return array Artifact entry.
	 */
	protected function make_named_zip_artifact( $name, $entries, $role = 'part' ) {
		$zip_path = $this->dir . '/' . $name;
		$zip      = new ZipArchive();
		$zip->open( $zip_path, ZipArchive::CREATE );
		foreach ( $entries as $relpath => $content ) {
			$zip->addFromString( $relpath, $content );
		}
		$zip->close();

		return array(
			'role'       => $role,
			'file'       => $name,
			'size'       => (int) filesize( $zip_path ),
			'sha256'     => hash_file( 'sha256', $zip_path ),
			'adopt_only' => false,
		);
	}

	/**
	 * Build a named tombstones artifact in the backup dir.
	 *
	 * @param string $name     List file name.
	 * @param string $contents One relpath per line.
	 *
	 * @return array Artifact entry.
	 */
	protected function make_named_tombstones_artifact( $name, $contents ) {
		$path = $this->dir . '/' . $name;
		file_put_contents( $path, $contents ); // phpcs:ignore -- fixture.
		return array(
			'role'       => 'tombstones',
			'file'       => $name,
			'size'       => (int) filesize( $path ),
			'sha256'     => hash_file( 'sha256', $path ),
			'adopt_only' => false,
		);
	}

	/**
	 * A three-link incremental chain restores to the tip's exact state: the
	 * base full extracts first, then each increment applies its tombstones
	 * before its parts in chain order, and the chain-newest separate db
	 * artifact replays instead of the base full's embedded dump.
	 *
	 * Artifact names carry the dashboard's s<snapshot_id>- staging prefix:
	 * every increment emits the same sidecar basenames (tombstones.list,
	 * <component>.part001.zip), so the plan namespaces them per snapshot.
	 */
	public function test_incremental_chain_restore_applies_links_in_order() {
		// Live-site state the chain must correct: a post-snapshot edit to a
		// tracked file, and an untracked file no snapshot knows about.
		file_put_contents( $this->site . '/a.txt', 'post-snapshot-edit' ); // phpcs:ignore -- fixture.
		file_put_contents( $this->site . '/unknown.txt', 'survives' ); // phpcs:ignore -- fixture.

		$base = $this->make_named_zip_artifact(
			's1-full001.zip',
			array(
				'a.txt'                                => 'a-v1',
				'b.txt'                                => 'carried',
				'c.txt'                                => 'c-v1',
				'dir/x.txt'                            => 'x-v1',
				// A real base full embeds its dump inside the site archive;
				// the chain's separate db artifact must win over it.
				'wp-content/dbBackup-bn_items.sql.php' => '<?php exit(); ?>' . "\nDROP TABLE IF EXISTS `bn_items`;\nCREATE TABLE `bn_items` (id INT);\n",
				'wp-content/dbBackup.sql'              => '',
			),
			'full'
		);

		// Increment 1: a.txt changed, d.txt added; c.txt and dir/x.txt deleted.
		$inc1_tombstones = $this->make_named_tombstones_artifact( 's2-tombstones.list', "c.txt\ndir/x.txt\n" );
		$inc1_part       = $this->make_named_zip_artifact(
			's2-plugins.part001.zip',
			array(
				'a.txt' => 'a-v2',
				'd.txt' => 'd-v1',
			)
		);

		// Increment 2: d.txt (added in increment 1) deleted; c.txt deleted
		// then recreated in the SAME increment - its tombstone must apply
		// before its part; e.txt added.
		$inc2_tombstones = $this->make_named_tombstones_artifact( 's3-tombstones.list', "d.txt\nc.txt\n" );
		$inc2_part       = $this->make_named_zip_artifact(
			's3-plugins.part001.zip',
			array(
				'e.txt' => 'e-v1',
				'c.txt' => 'c-v2',
			)
		);

		// The dashboard plan order: base full, each increment's tombstones
		// then parts in chain order, the chain-newest db dump last.
		$task_id = $this->make_task(
			'full',
			array( $base, $inc1_tombstones, $inc1_part, $inc2_tombstones, $inc2_part, $this->make_db_artifact() )
		);
		$task    = $this->drive( $task_id );

		$this->assertSame( MainWP_Child_Backup_Task::PHASE_COMPLETED, $task->phase );

		// Tip-state files.
		$this->assertSame( 'a-v2', file_get_contents( $this->site . '/a.txt' ), 'The later part must win over the base and the post-snapshot edit.' );
		$this->assertSame( 'carried', file_get_contents( $this->site . '/b.txt' ), 'A file untouched since the base carries through.' );
		$this->assertSame( 'c-v2', file_get_contents( $this->site . '/c.txt' ), 'A same-increment delete+recreate must end at the recreated content.' );
		$this->assertSame( 'e-v1', file_get_contents( $this->site . '/e.txt' ) );

		// Chain deletions.
		$this->assertFileDoesNotExist( $this->site . '/d.txt', 'A file added in increment 1 and tombstoned in increment 2 must be gone.' );
		$this->assertFileDoesNotExist( $this->site . '/dir/x.txt' );

		// Overlay semantics: a chain restore never deletes files it has no
		// tombstone for.
		$this->assertSame( 'survives', file_get_contents( $this->site . '/unknown.txt' ) );

		// The chain-newest separate db artifact replayed - not the base
		// full's embedded dump - and the embedded dump litter was swept.
		$config = $this->runner->fake_engine->last_replay_config;
		$this->assertSame( 'src_', $config['source_prefix'], 'The separate chain-newest db artifact must replay, not the embedded base dump.' );
		$this->assertCount( 1, $config['files'] );
		$this->assertStringContainsString( 'dbBackup-src_items.sql.php', $config['files'][0] );
		$this->assertFileDoesNotExist( $this->site . '/wp-content/dbBackup-bn_items.sql.php' );
		$this->assertFileDoesNotExist( $this->site . '/wp-content/dbBackup.sql' );
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
	 * A pre-feature cursor cannot preserve the connection after an FK replay.
	 */
	public function test_fk_missing_identity_capture_enters_rollback_and_keeps_live_rows() {
		global $wpdb;
		$names = array(
			'mainwp_child_pubkey',
			'mainwp_child_uniqueId',
			'mainwp_child_nonce',
			'mainwp_child_connected_admin',
			'mainwp_child_server',
			'mainwp_child_openssl_sign_algo',
			'mainwp_child_auth',
		);
		foreach ( $names as $name ) {
			$this->assertSame( 1, $wpdb->insert( 'rt_options', array( 'option_name' => $name, 'option_value' => 'live-' . $name, 'autoload' => 'no' ) ) );
		}
		$before = $wpdb->get_results( 'SELECT option_name, option_value, autoload FROM `rt_options` ORDER BY option_name', ARRAY_A );
		$this->runner->fake_engine->replay_yield_once = true;
		$task_id = $this->make_task( 'db', array( $this->make_db_artifact( true ) ), array( 'source_prefix' => 'rt_' ) );
		$task = $this->spine->get_task( $task_id );
		for ( $slice = 0; $slice < 20 && MainWP_Child_Backup_Task::PHASE_REPLAYING_DB !== $task->phase; ++$slice ) {
			$task = $this->runner->advance_one_slice( $task, MainWP_Child_Backup_Task_Runner::LEASE_OWNER_DASHBOARD );
		}
		$this->assertSame( MainWP_Child_Backup_Task::PHASE_REPLAYING_DB, $task->phase );
		$state = $this->spine->read_sub_state( $task, $this->dir );
		$this->assertTrue( $state['db_replay']['snapshot_done'] );
		unset( $state['identity_preserve'] );
		$this->assertNotFalse( $this->spine->checkpoint( $task_id, (int) $task->version, array( 'sub_state' => $state ) ) );
		$task = $this->spine->get_task( $task_id );
		$state = $this->spine->read_sub_state( $task, $this->dir );
		$replay = new ReflectionMethod( MainWP_Child_Restore_Task_Runner::class, 'run_replaying_db' );
		$replay->setAccessible( true );
		$replay->invokeArgs( $this->runner, array( $task, (int) $task->version, &$state, time() + 30, $this->dir ) );
		$task = $this->spine->get_task( $task_id );
		$this->assertSame( MainWP_Child_Backup_Task::PHASE_FK_ROLLBACK, $task->phase );
		$state = $this->spine->read_sub_state( $task, $this->dir );
		$this->assertSame( MainWP_Backup_Error::ERR_RESTORE_DB_REPLAY, $state['fk_rollback']['original_error_code'] );
		$this->assertStringContainsString( 'identity capture is missing', $state['fk_rollback']['original_error'] );
		$this->assertStringContainsString( 'Retry the restore', $state['fk_rollback']['original_error'] );
		$this->assertArrayNotHasKey( 'identity_preserve_delta', $state );
		$this->assertSame( $before, $wpdb->get_results( 'SELECT option_name, option_value, autoload FROM `rt_options` ORDER BY option_name', ARRAY_A ) );
		$task = $this->drive( $task_id );
		$this->assertSame( MainWP_Child_Backup_Task::PHASE_FAILED, $task->phase );
		$this->assertSame( MainWP_Backup_Error::ERR_RESTORE_ROLLED_BACK, $this->spine->status_payload( $task )['error_code'] );
		$this->assertContains( 'rollback_aside_slice', $this->runner->fake_engine->calls );
		$this->assertSame( $before, $wpdb->get_results( 'SELECT option_name, option_value, autoload FROM `rt_options` ORDER BY option_name', ARRAY_A ) );
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
	 * A live-table enumeration failure during the snapshot phase aborts the
	 * restore before it touches the live database: the snapshot is never taken,
	 * snapshot_done is never set, no in-place replay runs, and the snapshot-
	 * failed code is reported. Without this an enumeration failure would read as
	 * an empty live set and drive an unprotected in-place replay.
	 */
	public function test_fk_enumeration_failure_aborts_before_replay() {
		$this->runner->fake_engine->list_dump_throw = true;

		$task_id = $this->make_task( 'db', array( $this->make_db_artifact( true ) ), array( 'source_prefix' => 'rt_' ) );
		$task    = $this->drive( $task_id );

		$this->assertSame( MainWP_Child_Backup_Task::PHASE_FAILED, $task->phase );
		$this->assertSame( MainWP_Backup_Error::ERR_RESTORE_SNAPSHOT_FAILED, $this->spine->status_payload( $task )['error_code'] );

		$calls = $this->runner->fake_engine->calls;
		$this->assertNotContains( 'snapshot_tables_slice', $calls, 'No aside copy may be written when enumeration fails.' );
		$this->assertNotContains( 'replay_slice:fk', $calls, 'The in-place replay must not run when enumeration fails.' );

		$sub_state = $this->spine->read_sub_state( $task, $this->dir );
		$this->assertTrue( empty( $sub_state['db_replay']['snapshot_done'] ), 'snapshot_done must not be set on an enumeration failure.' );
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

		$nf_id      = $this->make_task( 'db', array( $this->make_db_artifact( false ) ), array( 'restore_id' => 'rrun02' ) );
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
	 * A rollback adopts the safety backup by copying it out of the backup
	 * folder, so the source stays there after the restore. Pruning the
	 * safety backup's task must delete that source.
	 */
	public function test_prune_of_adopted_safety_backup_removes_the_source() {
		$artifact = $this->make_full_archive_with_embedded_dump();
		$source   = $this->dir . '/' . $artifact['file'];

		$safety_id = $this->spine->create_task( 'full', array() );
		$this->spine->checkpoint(
			$safety_id,
			0,
			array(
				'phase'     => MainWP_Child_Backup_Task::PHASE_COMPLETED,
				'sub_state' => array(
					'artifact' => array(
						'file' => $artifact['file'],
						'size' => $artifact['size'],
					),
				),
			)
		);

		$rollback = array_merge(
			$artifact,
			array(
				'role'       => 'full',
				'sha256'     => '',
				'adopt_only' => true,
			)
		);
		$task_id  = $this->make_task(
			'full',
			array( $rollback ),
			array(
				'source_prefix'    => '',
				'has_foreign_keys' => false,
			)
		);
		$task     = $this->drive( $task_id );

		$this->assertSame( MainWP_Child_Backup_Task::PHASE_COMPLETED, $task->phase );
		$this->assertFileExists( $source, 'Adoption copies the safety backup; the source stays in the backup folder.' );
		$this->assertDirectoryDoesNotExist( $this->dir . '/restore-' . $task_id, 'The adopted copy goes with the restore scratch.' );

		$this->assertSame( 'pruned', $this->spine->prune_task( $safety_id, $this->dir ) );
		$this->assertFileDoesNotExist( $source, 'Pruning the safety backup task deletes the source the copy came from.' );
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

	protected function dump_sweep_runner() {
		$runner = new Dump_Sweep_Restore_Runner();
		$runner->test_backup_dir = $this->dir;
		$runner->test_site_root = $this->site;
		$runner->test_content_roots = $this->runner->test_content_roots;
		$engine = new Dump_Sweep_Restore_Engine();
		$engine->maintenance_file = $this->site . '/.maintenance';
		$engine->tmp_table = 'rt_dump_sweep_tmp';
		$engine->old_table = 'rt_dump_sweep_old';
		$runner->fake_engine = $engine;
		$this->runner = $runner;
		return $runner;
	}

	protected function dump_sweep_fixture( $phase = MainWP_Child_Backup_Task::PHASE_REPLAYING_DB ) {
		$this->dump_sweep_runner();
		$task_id = $this->make_task( 'db', array( $this->make_db_artifact() ) );
		$task = $this->spine->get_task( $task_id );
		$scratch = $this->runner->scratch_dir( $task_id, $this->dir );
		mkdir( $scratch . 'aside', 0777, true );
		file_put_contents( $scratch . 'aside/recovery.sql.php', 'recovery snapshot' );
		file_put_contents( $this->dir . '/pre-snapshot.zip', 'pre-snapshot' );
		file_put_contents( $this->dir . '/dbBackup-scratch.sql.php', 'scratch dump' );
		$this->runner->fake_engine->begin_maintenance();
		global $wpdb;
		foreach ( array( 'rt_dump_sweep_tmp', 'rt_dump_sweep_old' ) as $table ) {
			$wpdb->query( 'CREATE TABLE `' . $table . '` (id INT)' );
			$wpdb->query( 'INSERT INTO `' . $table . '` VALUES (17)' );
			$this->identity_fixture_tables[] = $table;
		}
		$state = $this->spine->read_sub_state( $task, $this->dir );
		$state['db_replay'] = array( 'files' => array(), 'fk_path' => false, 'cursor' => array(), 'seen_resumes' => 0, 'source_prefix' => 'src_' );
		$state['fk_snapshot'] = array( 'existing' => array( 'rt_items' ), 'files' => array( $scratch . 'aside/recovery.sql.php' ) );
		$this->assertNotFalse( $this->spine->checkpoint( $task_id, (int) $task->version, array( 'phase' => $phase, 'sub_state' => $state ) ) );
		$this->seed_dump_sweep_litter();
		$this->runner->fake_engine->calls = array();
		return $task_id;
	}

	protected function seed_dump_sweep_litter( $bytes = 'extracted dump' ) {
		wp_mkdir_p( $this->site . '/wp-content/nested' );
		foreach ( array( 'dbBackup-test.sql.php', 'dbBackup.sql', 'wp-content/dbBackup-test.sql.php', 'wp-content/dbBackup.sql' ) as $file ) {
			file_put_contents( $this->site . '/' . $file, $bytes );
		}
		file_put_contents( $this->site . '/wp-content/nested/dbBackup-keep.sql.php', 'nested content' );
		file_put_contents( $this->site . '/dbBackup-keep.txt', 'site content' );
	}

	protected function assert_dump_sweep_state( $task_id, $litter_kept, $maintenance_on, $scratch_kept, $tmp_kept = false ) {
		global $wpdb;
		clearstatcache();
		foreach ( array( 'dbBackup-test.sql.php', 'dbBackup.sql', 'wp-content/dbBackup-test.sql.php', 'wp-content/dbBackup.sql' ) as $file ) {
			$this->assertSame( $litter_kept, is_file( $this->site . '/' . $file ), $file );
			if ( $litter_kept ) {
				$this->assertSame( 'extracted dump', file_get_contents( $this->site . '/' . $file ) );
			}
		}
		$this->assertSame( $maintenance_on, is_file( $this->site . '/.maintenance' ), 'Maintenance state.' );
		$recovery = $this->runner->scratch_dir( $task_id, $this->dir ) . 'aside/recovery.sql.php';
		$this->assertSame( $scratch_kept, is_file( $recovery ), 'Recovery snapshot.' );
		if ( $scratch_kept ) {
			$this->assertSame( 'recovery snapshot', file_get_contents( $recovery ) );
		}
		$this->assertSame( 'pre-snapshot', file_get_contents( $this->dir . '/pre-snapshot.zip' ) );
		$this->assertSame( 'scratch dump', file_get_contents( $this->dir . '/dbBackup-scratch.sql.php' ) );
		$this->assertSame( 'nested content', file_get_contents( $this->site . '/wp-content/nested/dbBackup-keep.sql.php' ) );
		$this->assertSame( 'site content', file_get_contents( $this->site . '/dbBackup-keep.txt' ) );
		$this->assertSame( '17', $wpdb->get_var( 'SELECT id FROM `rt_dump_sweep_old`' ), 'Aside table retained.' );
		$prior = $wpdb->suppress_errors( true );
		$tmp = $wpdb->get_var( 'SELECT id FROM `rt_dump_sweep_tmp`' );
		$wpdb->suppress_errors( $prior );
		$this->assertSame( $tmp_kept ? '17' : null, $tmp, 'Temporary table state.' );
	}

	public function test_block_c_a_leased_cancel_sweeps_dump_litter() {
		$task_id = $this->dump_sweep_fixture();
		$fences = array();
		$observe = function ( $file ) use ( $task_id, &$fences ) {
			if ( false !== strpos( $file, '/dbBackup' ) ) {
				$task = $this->spine->get_task( $task_id );
				$fences[] = array( $task->active_slot, $task->lease_owner, $task->phase );
			}
			return $file;
		};
		add_filter( 'wp_delete_file', $observe );
		try {
			$this->assertSame( 'ok', $this->runner->cancel_restore_task( $task_id )['result'] );
		} finally {
			remove_filter( 'wp_delete_file', $observe );
		}
		$this->assertSame( MainWP_Backup_Error::ERR_RESTORE_CANCELLED, $this->spine->status_payload( $this->spine->get_task( $task_id ), $this->dir )['error_code'] );
		$this->assert_dump_sweep_state( $task_id, false, false, false );
		$this->assertCount( 4, $fences );
		foreach ( $fences as $fence ) {
			$this->assertSame( '1', $fence[0], 'Sweep before the terminal write releases the slot.' );
			$this->assertMatchesRegularExpression( '/^cancel:[a-f0-9-]{36}$/', $fence[1], 'Cleanup owns this cancellation invocation.' );
			$this->assertSame( MainWP_Child_Backup_Task::PHASE_REPLAYING_DB, $fence[2] );
		}
	}

	public function test_block_c_b_phase_exception_sweeps_dump_litter() {
		$task_id = $this->dump_sweep_fixture();
		$this->runner->fake_engine->replay_throw = true;
		$task = $this->drive( $task_id );
		$this->assertSame( MainWP_Backup_Error::ERR_RESTORE_DB_REPLAY, $this->spine->status_payload( $task, $this->dir )['error_code'] );
		$this->assert_dump_sweep_state( $task_id, false, false, false );
	}

	public function test_block_c_c_reconcile_abort_sweeps_dump_litter() {
		$task_id = $this->dump_sweep_fixture( MainWP_Child_Backup_Task::PHASE_RESTORING_FILES );
		$task = $this->spine->get_task( $task_id );
		$state = $this->spine->read_sub_state( $task, $this->dir );
		$manifest = $this->runner->scratch_dir( $task_id, $this->dir ) . 'bad-files.list';
		file_put_contents( $manifest, "invalid\trow\t1\t1\n" );
		$state['file_restore'] = array( 'op_index' => 0, 'part_cursor' => array(), 'dump_litter_swept' => true );
		$state['params']['reconcile'] = array( 'manifest' => 'bad-files.list', 'walk' => array( 'excludes' => array(), 'excludenonwp' => 0, 'excludezip' => 0 ) );
		$state['verified_manifests'] = array( 'bad-files.list' => hash_file( 'sha256', $manifest ) );
		// A persisted preserve set skips the capture, so the malformed manifest reaches reconcile.
		$state['mu_plugins_preserve'] = array( 'directory' => null, 'mode' => 'set', 'paths' => array() );
		$this->spine->checkpoint( $task_id, (int) $task->version, array( 'sub_state' => $state ) );
		global $wpdb;
		$wpdb->query( $wpdb->prepare( 'UPDATE ' . $this->spine->get_table_name() . ' SET type = %s WHERE task_id = %s', 'restore_files', $task_id ) );
		$task = $this->drive( $task_id );
		$this->assertSame( MainWP_Backup_Error::ERR_RESTORE_RECONCILE, $this->spine->status_payload( $task, $this->dir )['error_code'] );
		$this->assert_dump_sweep_state( $task_id, false, false, false );
	}

	protected function with_dump_sweep_singleton( $callback ) {
		$singleton = new ReflectionProperty( MainWP_Child_Restore_Task_Runner::class, 'restore_instance' );
		$singleton->setAccessible( true );
		$prior = $singleton->getValue();
		$singleton->setValue( null, $this->runner );
		try {
			return $callback();
		} finally {
			$singleton->setValue( null, $prior );
		}
	}

	public function test_block_c_d_external_timeout_sweeps_dump_litter() {
		$task_id = $this->dump_sweep_fixture();
		$this->force_deadline_past( $task_id );
		$admission = null;
		$observe = function ( $file ) use ( &$admission ) {
			if ( null === $admission && false !== strpos( $file, '/dbBackup' ) ) {
				try {
					$admission = $this->make_task( 'db', array( $this->make_db_artifact() ) );
				} catch ( MainWP_Exception $e ) {
					$admission = $e->get_message_error_code();
				}
			}
			return $file;
		};
		add_filter( 'wp_delete_file', $observe );
		try {
			$this->assertTrue( $this->with_dump_sweep_singleton( function () use ( $task_id ) {
				return $this->spine->enforce_wall_clock_deadline( $task_id );
			} ) );
		} finally {
			remove_filter( 'wp_delete_file', $observe );
		}
		$this->assertSame( MainWP_Backup_Error::ERR_WALL_CLOCK, $this->spine->status_payload( $this->spine->get_task( $task_id ), $this->dir )['error_code'] );
		$this->assert_dump_sweep_state( $task_id, false, false, false );
		$this->assertSame( MainWP_Backup_Error::ERR_ALREADY_RUNNING, $admission, 'The terminal sweeper fences successor admission during deletion.' );
	}

	public function test_block_c_e_stale_slice_owner_cannot_sweep() {
		$task_id = $this->dump_sweep_fixture();
		$this->runner->fake_engine->replay_throw = true;
		$ran_successor = false;
		$this->runner->before_terminal_cleanup = function () use ( $task_id, &$ran_successor ) {
			global $wpdb;
			$wpdb->query( $wpdb->prepare( 'UPDATE ' . $this->spine->get_table_name() . ' SET lease_expires_at = DATE_SUB(NOW(), INTERVAL 1 SECOND) WHERE task_id = %s', $task_id ) );
			$successor = clone $this->runner;
			$successor->before_terminal_cleanup = null;
			$successor->fake_engine = clone $this->runner->fake_engine;
			$successor->fake_engine->replay_throw = false;
			$successor->fake_engine->replay_yield_once = true;
			$task = $successor->advance_one_slice( $this->spine->get_task( $task_id ), 'successor' );
			$this->assertSame( MainWP_Child_Backup_Task::PHASE_REPLAYING_DB, $task->phase );
			$ran_successor = true;
		};
		$task = $this->runner->advance_one_slice( $this->spine->get_task( $task_id ), 'stale' );
		$this->assertTrue( $ran_successor );
		$this->assertSame( MainWP_Child_Backup_Task::PHASE_REPLAYING_DB, $task->phase );
		$this->assert_dump_sweep_state( $task_id, true, true, true, true );
	}

	/**
	 * A slice that overran its lease and then threw still owns the row when
	 * nobody took it over, so it re-extends the lease and the failure lands.
	 */
	public function test_block_c_e_expired_untaken_lease_still_fails_the_task() {
		$task_id = $this->dump_sweep_fixture();
		$this->runner->fake_engine->replay_throw = true;
		$this->runner->before_terminal_cleanup = function () use ( $task_id ) {
			global $wpdb;
			$wpdb->query( $wpdb->prepare( 'UPDATE ' . $this->spine->get_table_name() . ' SET lease_expires_at = DATE_SUB(NOW(), INTERVAL 1 SECOND) WHERE task_id = %s', $task_id ) );
		};
		$task = $this->runner->advance_one_slice( $this->spine->get_task( $task_id ), 'overran' );
		$this->assertSame( MainWP_Child_Backup_Task::PHASE_FAILED, $task->phase );
		$this->assertSame( MainWP_Backup_Error::ERR_RESTORE_DB_REPLAY, $this->spine->status_payload( $task, $this->dir )['error_code'] );
		$this->assertNull( $task->active_slot );
		$this->assert_dump_sweep_state( $task_id, false, false, false );
	}

	public function test_block_c_f_successor_after_slot_release_keeps_fresh_dumps() {
		$task_id = $this->dump_sweep_fixture();
		$this->force_deadline_past( $task_id );
		$successor_id = null;
		$this->runner->before_external_cleanup = function () use ( &$successor_id ) {
			$this->assertEmpty( $this->spine->get_active_tasks(), 'Pause after the terminal write releases the slot.' );
			$successor = clone $this->runner;
			$successor->before_external_cleanup = null;
			$successor->fake_engine = clone $this->runner->fake_engine;
			$successor->fake_engine->replay_yield_once = true;
			$artifact = $this->make_full_archive_with_embedded_dump();
			$successor_id = $successor->create_restore_task( 'full', array( 'restore_id' => 'sweep_successor', 'source_prefix' => 'bn_', 'artifacts' => array( $artifact ), 'has_foreign_keys' => null ) );
			$task = $this->spine->get_task( $successor_id );
			for ( $i = 0; $i < 3 && MainWP_Child_Backup_Task::PHASE_REPLAYING_DB !== $task->phase; ++$i ) {
				$task = $successor->advance_one_slice( $task, 'successor' );
			}
			$this->assertSame( MainWP_Child_Backup_Task::PHASE_REPLAYING_DB, $task->phase );
			$this->assertFileExists( $this->site . '/wp-content/dbBackup-bn_items.sql.php' );
			$this->seed_dump_sweep_litter();
		};
		$this->assertTrue( $this->with_dump_sweep_singleton( function () use ( $task_id ) {
			return $this->spine->enforce_wall_clock_deadline( $task_id );
		} ) );
		$this->assertNotNull( $successor_id );
		$this->assertFileExists( $this->site . '/wp-content/dbBackup-bn_items.sql.php' );
		$this->assert_dump_sweep_state( $task_id, true, false, false );
	}

	public function test_block_c_g_completed_fk_rollback_sweeps_dump_litter() {
		$task_id = $this->dump_sweep_fixture( MainWP_Child_Backup_Task::PHASE_FK_ROLLBACK );
		$task = $this->drive( $task_id );
		$this->assertSame( MainWP_Backup_Error::ERR_RESTORE_ROLLED_BACK, $this->spine->status_payload( $task, $this->dir )['error_code'] );
		$this->assert_dump_sweep_state( $task_id, false, false, false, true );
	}

	/** @dataProvider block_c_rollback_failures */
	public function test_block_c_h_failed_fk_rollback_keeps_recovery( $failure ) {
		$task_id = $this->dump_sweep_fixture( MainWP_Child_Backup_Task::PHASE_FK_ROLLBACK );
		if ( 'error' === $failure ) {
			$this->runner->fake_engine->rollback_error = 'rollback failed';
			$task = $this->drive( $task_id );
		} elseif ( 'exception' === $failure ) {
			$this->runner->fake_engine->rollback_throw = true;
			$task = $this->drive( $task_id );
		} else {
			$this->with_dump_sweep_singleton( function () use ( $task_id, $failure ) {
				if ( 'deadline' === $failure ) {
					$this->force_deadline_past( $task_id );
					$this->assertTrue( $this->spine->enforce_wall_clock_deadline( $task_id ) );
				} else {
					global $wpdb;
					$wpdb->query( $wpdb->prepare( 'UPDATE ' . $this->spine->get_table_name() . ' SET resume_count = max_resumes, last_progress_at = DATE_SUB(NOW(), INTERVAL 1 HOUR) WHERE task_id = %s', $task_id ) );
					\MainWP\Child\MainWP_Child_Backup_Watchdog::instance()->check_tasks( 1 );
				}
			} );
			$task = $this->spine->get_task( $task_id );
		}
		$this->assertSame( MainWP_Child_Backup_Task::PHASE_FAILED, $task->phase );
		$expected_code = 'watchdog' === $failure ? MainWP_Backup_Error::ERR_STALLED : MainWP_Backup_Error::ERR_RESTORE_ROLLBACK_FAILED;
		$this->assertSame( $expected_code, $this->spine->status_payload( $task, $this->dir )['error_code'] );
		$this->assert_dump_sweep_state( $task_id, true, true, true, true );
	}

	public static function block_c_rollback_failures() {
		return array( 'error' => array( 'error' ), 'exception' => array( 'exception' ), 'deadline' => array( 'deadline' ), 'watchdog' => array( 'watchdog' ) );
	}

	public function test_block_c_h_cancel_reroute_keeps_recovery() {
		$task_id = $this->dump_sweep_fixture();
		$task = $this->spine->get_task( $task_id );
		$state = $this->spine->read_sub_state( $task, $this->dir );
		$state['db_replay']['fk_path'] = true;
		$state['db_replay']['snapshot_done'] = true;
		$this->spine->checkpoint( $task_id, (int) $task->version, array( 'sub_state' => $state ) );
		$this->assertSame( 'rolling_back', $this->runner->cancel_restore_task( $task_id )['result'] );
		$this->assertSame( MainWP_Child_Backup_Task::PHASE_FK_ROLLBACK, $this->spine->get_task( $task_id )->phase );
		$this->assert_dump_sweep_state( $task_id, true, true, true, true );
	}

	/** @dataProvider block_c_sweep_failures */
	public function test_block_c_i_sweep_failure_keeps_cancel_outcome( $throws ) {
		$task_id = $this->dump_sweep_fixture();
		$attempts = 0;
		$prevent_delete = function ( $file ) use ( $throws, &$attempts ) {
			if ( false !== strpos( $file, '/dbBackup' ) ) {
				++$attempts;
				if ( $throws ) {
					throw new RuntimeException( 'deletion failed' );
				}
				return '';
			}
			return $file;
		};
		add_filter( 'wp_delete_file', $prevent_delete );
		try {
			$this->assertSame( 'ok', $this->runner->cancel_restore_task( $task_id )['result'] );
		} finally {
			remove_filter( 'wp_delete_file', $prevent_delete );
		}
		$this->assertSame( MainWP_Backup_Error::ERR_RESTORE_CANCELLED, $this->spine->status_payload( $this->spine->get_task( $task_id ), $this->dir )['error_code'] );
		$this->assert_dump_sweep_state( $task_id, true, false, false );
		$this->assertGreaterThan( 0, $attempts, 'The terminal path attempted the sweep.' );
	}

	public static function block_c_sweep_failures() {
		return array( 'failed deletion' => array( false ), 'exception' => array( true ) );
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

	public function test_dump_litter_is_swept_before_embedded_replay() {
		global $wpdb;
		$session_query = 'SELECT @@SESSION.sql_mode AS sql_mode, @@SESSION.foreign_key_checks AS foreign_key_checks, @@SESSION.unique_checks AS unique_checks, @@SESSION.wait_timeout AS wait_timeout, @@SESSION.net_read_timeout AS net_read_timeout, @@SESSION.net_write_timeout AS net_write_timeout';
		// The replay runs on the shared test connection, which outlives this
		// test; production's dedicated connection dies with the permissive
		// session instead, so the fixture has to put it back.
		$session_pristine = $wpdb->get_row( $session_query, ARRAY_A );
		$artifact = $this->make_full_archive_with_embedded_dump();
		$zip = new ZipArchive();
		$zip->open( $this->dir . '/full001.zip' );
		$sql = str_replace( 'bn_', 'rt_', $zip->getFromName( 'wp-content/dbBackup-bn_items.sql.php' ) );
		$zip->deleteName( 'wp-content/dbBackup-bn_items.sql.php' );
		$member = 'wp-content/dbBackup-task-original-rt_items.sql.php';
		$zip->addFromString( $member, $sql );
		$zip->close();
		clearstatcache();
		$artifact['size'] = filesize( $this->dir . '/full001.zip' );
		$artifact['sha256'] = hash_file( 'sha256', $this->dir . '/full001.zip' );
		mkdir( $this->site . '/wp-content', 0777, true );
		$stale = $this->site . '/wp-content/dbBackup-wp_posts.sql.php';
		file_put_contents( $stale, str_replace( '(1),(2)', '(99)', $sql ) );

		$this->runner = new class extends Testable_Restore_Task_Runner {
			public $pause_before_replay = true;

			protected function run_replaying_db( $task, $version, $sub_state, $deadline, $backup_dir ) {
				if ( $this->pause_before_replay ) {
					$this->pause_before_replay = false;
					return $this->checkpoint_sub_state( $task, $version, $sub_state, $backup_dir, true );
				}
				return parent::run_replaying_db( $task, $version, $sub_state, $deadline, $backup_dir );
			}
		};
		$this->runner->test_backup_dir = $this->dir;
		$this->runner->test_site_root = $this->site;
		$this->runner->test_content_roots = array( $this->site . '/wp-content' );

		// Use the real replay parser on the test connection so its temporary tables remain visible.
		$this->runner->fake_engine = new class extends Fake_Restore_Engine {
			private $saved_session;

			protected function connect() {
				global $wpdb;
				// A later connect() would capture the permissive session this
				// replay just set, so only the first one is the clean state.
				if ( null === $this->saved_session ) {
					$this->saved_session = $wpdb->get_row( 'SELECT @@SESSION.sql_mode AS sql_mode, @@SESSION.foreign_key_checks AS foreign_key_checks, @@SESSION.unique_checks AS unique_checks, @@SESSION.wait_timeout AS wait_timeout, @@SESSION.net_read_timeout AS net_read_timeout, @@SESSION.net_write_timeout AS net_write_timeout', ARRAY_A );
					if ( ! is_array( $this->saved_session ) ) {
						throw new RuntimeException( 'Could not save the shared test session.' );
					}
				}
				return $wpdb->dbh;
			}
			protected function close_connection( $conn ) {
				global $wpdb;
				// The shared connection outlives the replay, unlike the production connection.
				foreach ( $this->saved_session as $name => $value ) {
					// Only sql_mode takes a quoted value; the numeric session
					// variables refuse a string and would leave the session
					// permissive for every later test in this process.
					$sql = 'sql_mode' === $name
						? $wpdb->prepare( 'SET SESSION sql_mode = %s', $value )
						: $wpdb->prepare( 'SET SESSION ' . $name . ' = %d', $value );
					if ( false === $wpdb->query( $sql ) ) {
						throw new RuntimeException( 'Could not restore the shared test session.' );
					}
				}
			}
			protected function run_statement( $conn, $sql ) {
				global $wpdb;
				$result = $wpdb->query( $sql );
				return false === $result ? $wpdb->last_error : (int) $result;
			}
		};
		$this->runner->fake_engine->replay_real_sql = true;
		$this->runner->fake_engine->verify_real_counts = true;
		$task_id = $this->make_task( 'full', array( $artifact ), array( 'source_prefix' => 'rt_', 'has_foreign_keys' => true ) );
		$task = $this->spine->get_task( $task_id );
		for ( $i = 0; $i < 20; ++$i ) {
			$task = $this->runner->advance_one_slice( $task, MainWP_Child_Backup_Task_Runner::LEASE_OWNER_DASHBOARD );
			$state = $this->spine->read_sub_state( $task, $this->dir );
			if ( isset( $state['file_restore'] ) ) {
				break;
			}
		}
		$this->assertSame( MainWP_Child_Backup_Task::PHASE_REPLAYING_DB, $task->phase );
		$this->assertTrue( $state['file_restore']['dump_litter_swept'] );
		$this->assertFileDoesNotExist( $stale );
		$this->assertFileExists( $this->site . '/' . $member );
		$this->assertNull( $this->runner->fake_engine->last_replay_config );
		try {
			$task = $this->drive( $task_id );
		} finally {
			foreach ( $session_pristine as $name => $value ) {
				// Only sql_mode takes a quoted value; the numeric session
				// variables refuse a string and leave the session permissive.
				$sql = 'sql_mode' === $name
					? $wpdb->prepare( 'SET SESSION sql_mode = %s', $value )
					: $wpdb->prepare( 'SET SESSION ' . $name . ' = %d', $value );
				$this->assertNotFalse( $wpdb->query( $sql ), $name );
			}
		}
		$this->assertSame( $session_pristine, $wpdb->get_row( $session_query, ARRAY_A ), 'This test must not leave the shared connection permissive for the tests after it.' );
		$this->assertSame( MainWP_Child_Backup_Task::PHASE_COMPLETED, $task->phase );
		$state = $this->spine->read_sub_state( $task, $this->dir );
		$this->assertSame( array( $this->site . '/' . $member ), $state['dump_files'] );
		$this->assertSame( $state['dump_files'], $this->runner->fake_engine->last_replay_config['files'] );
		$this->assertSame( array( '1', '2' ), $wpdb->get_col( 'SELECT id FROM rt_items ORDER BY id' ) );
		$this->assertSame( array( 'items' => 2 ), $this->runner->fake_engine->last_verify_expected );
		$this->assertFileDoesNotExist( $this->site . '/' . $member );
	}

	public function test_find_embedded_dump_files_prefers_extraction_record() {
		mkdir( $this->site . '/wp-content', 0777, true );
		$fresh = $this->site . '/wp-content/dbBackup-task-original-wp_posts.sql.php';
		$stale = $this->site . '/wp-content/dbBackup-wp_posts.sql.php';
		file_put_contents( $fresh, 'fresh' );
		file_put_contents( $stale, 'stale' );
		$method = new ReflectionMethod( MainWP_Child_Restore_Task_Runner::class, 'find_embedded_dump_files' );
		$method->setAccessible( true );
		$this->assertSame( array( $fresh ), $method->invoke( $this->runner, array( 'dump_files' => array( $fresh ) ) ) );
		$this->assertSame( array(), $method->invoke( $this->runner, array( 'dump_files' => array() ) ) );
		$this->assertEqualsCanonicalizing( array( $fresh, $stale ), $method->invoke( $this->runner, array() ) );
	}

	/**
	 * A failed litter deletion must stop extraction before stale SQL can be replayed.
	 */
	public function test_undeletable_dump_litter_fails_before_extraction() {
		mkdir( $this->site . '/wp-content', 0777, true );
		$stale = $this->site . '/wp-content/dbBackup-wp_posts.sql.php';
		file_put_contents( $stale, 'stale' );
		$prevent_delete = static function ( $file ) use ( $stale ) {
			return $stale === $file ? '' : $file;
		};
		add_filter( 'wp_delete_file', $prevent_delete );
		try {
			$task_id = $this->make_task( 'full', array( $this->make_full_archive_with_embedded_dump() ) );
			$task = $this->drive( $task_id );
		} finally {
			remove_filter( 'wp_delete_file', $prevent_delete );
		}

		$this->assertSame( MainWP_Child_Backup_Task::PHASE_FAILED, $task->phase );
		$payload = $this->spine->status_payload( $task );
		$this->assertSame( MainWP_Backup_Error::ERR_RESTORE_EXTRACT, $payload['error_code'] );
		$this->assertStringContainsString( basename( $stale ), $payload['error'] );
		$this->assertStringNotContainsString( $this->site, $payload['error'] );
		$this->assertFileExists( $stale );
		$this->assertFileDoesNotExist( $this->site . '/wp-content/uploads/restored.txt' );
		$this->assertNull( $this->runner->fake_engine->last_replay_config );
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
	 * Cancelling a foreign-key restore caught mid in-place replay must not
	 * delete the aside snapshot dumps: they are the only rollback source and
	 * the live tables may be half-overwritten. The cancel reroutes to the
	 * rollback phase, keeps the scratch dir, and the runner then replays the
	 * aside dumps back to the pre-restore state.
	 */
	public function test_cancel_mid_fk_replay_reroutes_to_rollback_and_keeps_aside() {
		$this->runner->fake_engine->replay_yield_once = true;

		$task_id = $this->make_task( 'db', array( $this->make_db_artifact( true ) ), array( 'source_prefix' => 'rt_' ) );

		// Step until the aside snapshot is taken and the first replay slice has
		// yielded: paused in replaying_db with the live tables mid-overwrite.
		$task  = $this->spine->get_task( $task_id );
		$guard = 0;
		while ( MainWP_Child_Backup_Task::PHASE_REPLAYING_DB !== $task->phase && $guard < 20 ) {
			$task = $this->runner->advance_one_slice( $task, MainWP_Child_Backup_Task_Runner::LEASE_OWNER_DASHBOARD );
			++$guard;
		}
		$this->assertSame( MainWP_Child_Backup_Task::PHASE_REPLAYING_DB, $task->phase );
		$this->assertDirectoryExists( $this->dir . '/restore-' . $task_id . '/aside' );

		$outcome = $this->runner->cancel_restore_task( $task_id );

		$this->assertSame( 'rolling_back', $outcome['result'], 'A mid-replay FK cancel must roll back, not clean-cancel.' );
		$this->assertSame( MainWP_Child_Backup_Task::PHASE_FK_ROLLBACK, $outcome['phase'] );
		$this->assertSame( MainWP_Child_Backup_Task::PHASE_FK_ROLLBACK, $this->spine->get_task( $task_id )->phase );
		$this->assertDirectoryExists( $this->dir . '/restore-' . $task_id, 'The aside dumps must survive a cancel mid-replay.' );

		$task = $this->drive( $task_id );
		$this->assertSame( MainWP_Child_Backup_Task::PHASE_FAILED, $task->phase );
		$this->assertSame( MainWP_Backup_Error::ERR_RESTORE_ROLLED_BACK, $this->spine->status_payload( $task )['error_code'] );
		$this->assertContains( 'rollback_aside_slice', $this->runner->fake_engine->calls, 'The cancel must replay the aside dumps back.' );
	}

	/**
	 * A foreign-key restore cancelled while it is already rolling back must not
	 * have its aside dumps deleted out from under the in-progress rollback: the
	 * cancel leaves the row rolling back rather than running terminal cleanup.
	 */
	public function test_cancel_during_fk_rollback_preserves_rollback() {
		$task_id = $this->spine->create_task( 'restore_db', array( 'db_replay' => array( 'fk_path' => true, 'snapshot_done' => true ) ), 20, 3600 );
		$version = (int) $this->spine->get_task( $task_id )->version;
		$this->spine->checkpoint( $task_id, $version, array( 'phase' => MainWP_Child_Backup_Task::PHASE_FK_ROLLBACK ) );

		// Seed the scratch dir the rollback needs so its survival can be asserted.
		mkdir( $this->dir . '/restore-' . $task_id . '/aside', 0777, true );

		$outcome = $this->runner->cancel_restore_task( $task_id );

		$this->assertSame( 'rolling_back', $outcome['result'] );
		$this->assertSame( MainWP_Child_Backup_Task::PHASE_FK_ROLLBACK, $this->spine->get_task( $task_id )->phase );
		$this->assertDirectoryExists( $this->dir . '/restore-' . $task_id . '/aside', 'A cancel during rollback must not delete the aside dumps.' );
		$this->assertNotContains( 'exit_maintenance', $this->runner->fake_engine->calls, 'A cancel during rollback must not run terminal cleanup.' );
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

	public function test_callable_cancel_by_restore_id_cancels_live_task() {
		$task_id = $this->make_task( 'db', array( $this->make_db_artifact() ) );
		$task = $this->spine->get_task( $task_id );
		$outcome = $this->callable_cancel( array( 'restore_id' => $task->restore_id ) );

		$this->assertSame( 'ok', $outcome['result'] ?? null );
		$this->assertSame( MainWP_Child_Backup_Task::PHASE_FAILED, $this->spine->get_task( $task_id )->phase );
		$this->assertSame( MainWP_Backup_Error::ERR_RESTORE_CANCELLED, $this->spine->status_payload( $this->spine->get_task( $task_id ) )['error_code'] );
	}

	public function test_callable_cancel_unknown_restore_id_changes_nothing() {
		$task_id = $this->make_task( 'db', array( $this->make_db_artifact() ) );
		$before = $this->spine->get_task( $task_id );

		$this->assertSame( array( 'result' => 'not_found' ), $this->callable_cancel( array( 'restore_id' => 'missing123' ) ) );
		$this->assertEquals( $before, $this->spine->get_task( $task_id ) );
	}

	public function test_callable_cancel_reports_failed_restore_id_lookup_as_error() {
		global $wpdb;
		$task_id = $this->make_task( 'db', array( $this->make_db_artifact() ) );
		$before  = $this->spine->get_task( $task_id );
		// Rewrites only the lookup so the query fails without DDL, which would end the test transaction.
		$break    = static function ( $query ) {
			return false !== strpos( $query, 'WHERE restore_id = ' ) ? 'SELECT * FROM mainwp_child_missing_restore_lookup' : $query;
		};
		$suppress = $wpdb->suppress_errors( true );
		add_filter( 'query', $break );
		try {
			$outcome = $this->callable_cancel( array( 'restore_id' => $before->restore_id ) );
		} finally {
			remove_filter( 'query', $break );
			$wpdb->suppress_errors( $suppress );
		}

		$this->assertArrayHasKey( 'error', $outcome );
		$this->assertArrayNotHasKey( 'result', $outcome );
		$this->assertEquals( $before, $this->spine->get_task( $task_id ) );
	}

	/** @dataProvider invalid_cancel_restore_ids */
	public function test_callable_cancel_refuses_invalid_restore_id( $restore_id ) {
		$task_id = $this->make_task( 'db', array( $this->make_db_artifact() ) );
		$before = $this->spine->get_task( $task_id );
		$outcome = $this->callable_cancel( array( 'restore_id' => $restore_id ) );

		$this->assertArrayHasKey( 'error', $outcome );
		$this->assertArrayNotHasKey( 'result', $outcome );
		$this->assertEquals( $before, $this->spine->get_task( $task_id ) );
	}

	public static function invalid_cancel_restore_ids() {
		return array(
			'missing' => array( '' ),
			'short' => array( 'abc12' ),
			'long' => array( str_repeat( 'a', 17 ) ),
			'uppercase' => array( 'RESTORE1' ),
			'path' => array( '../restore1' ),
		);
	}

	public function test_callable_cancel_task_id_takes_precedence_over_restore_id() {
		$task_id = $this->make_task( 'db', array( $this->make_db_artifact() ) );
		$before = $this->spine->get_task( $task_id );
		$outcome = $this->callable_cancel( array( 'task_id' => 'unknown-task', 'restore_id' => $before->restore_id ) );
		$this->assertArrayHasKey( 'error', $outcome );
		$this->assertEquals( $before, $this->spine->get_task( $task_id ) );

		$outcome = $this->callable_cancel( array( 'task_id' => $task_id, 'restore_id' => 'INVALID' ) );
		$this->assertSame( 'ok', $outcome['result'] ?? null );
		$this->assertSame( MainWP_Child_Backup_Task::PHASE_FAILED, $this->spine->get_task( $task_id )->phase );
	}

	/** @dataProvider cancel_restore_id_outcomes */
	public function test_callable_cancel_by_restore_id_preserves_runner_outcomes( $phase, $leased, $expected ) {
		$task_id = $this->make_task( 'db', array( $this->make_db_artifact() ) );
		$task = $this->spine->get_task( $task_id );
		$this->spine->checkpoint( $task_id, (int) $task->version, array( 'phase' => $phase ) );
		if ( $leased ) {
			$this->assertTrue( $this->spine->acquire_lease( $task_id, 'other-runner', (int) $this->spine->get_task( $task_id )->version ) );
		}
		$outcome = $this->callable_cancel( array( 'restore_id' => $task->restore_id ) );
		$this->assertSame( $expected, $outcome['result'] ?? null );
		$this->assertSame( $phase, $this->spine->get_task( $task_id )->phase );
	}

	public static function cancel_restore_id_outcomes() {
		return array(
			'busy' => array( MainWP_Child_Backup_Task::PHASE_QUEUED, true, 'busy' ),
			'terminal' => array( MainWP_Child_Backup_Task::PHASE_COMPLETED, false, 'already_terminal' ),
			'rollback' => array( MainWP_Child_Backup_Task::PHASE_FK_ROLLBACK, false, 'rolling_back' ),
		);
	}

	private function callable_cancel( $post ) {
		// The response writer exits PHP, so only that boundary is replaced in the real methods.
		if ( ! class_exists( 'MainWP\\Child\\Restore_Cancel_Callable_Fixture', false ) ) {
			$methods = '';
			foreach ( array( 'restore_task_cancel', 'load_restore_task_from_request' ) as $name ) {
				$method = new ReflectionMethod( MainWP_Child_Callable::class, $name );
				$lines = file( $method->getFileName() );
				$methods .= str_replace( 'MainWP_Helper', '\\Restore_Cancel_Response_Helper', implode( '', array_slice( $lines, $method->getStartLine() - 1, $method->getEndLine() - $method->getStartLine() + 1 ) ) );
			}
			eval( 'namespace MainWP\\Child; class Restore_Cancel_Callable_Fixture extends MainWP_Child_Callable {' . $methods . '}' );
		}
		$previous_post = $_POST;
		$property = new ReflectionProperty( MainWP_Child_Restore_Task_Runner::class, 'restore_instance' );
		$property->setAccessible( true );
		$previous_runner = $property->getValue();
		$property->setValue( null, $this->runner );
		$_POST = $post;
		try {
			( new \MainWP\Child\Restore_Cancel_Callable_Fixture() )->restore_task_cancel();
			$this->fail( 'The callable must write a response.' );
		} catch ( Restore_Cancel_Response $response ) {
			return $response->payload;
		} finally {
			$_POST = $previous_post;
			$property->setValue( null, $previous_runner );
		}
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
		$this->make_task( 'db', array( $this->make_db_artifact() ), array( 'restore_id' => 'second01' ) );

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

	/** Unsupported layouts must be refused before creating restore state. */
	public function test_create_restore_task_refuses_external_content_without_state() {
		global $wpdb;
		$this->runner->test_content_roots = array( $this->dir . '/wp-content' );
		foreach ( array( 'full', 'files' ) as $sub_type ) {
			try {
				$this->make_task( $sub_type, array( $this->make_part_artifact() ) );
				$this->fail( 'File restores must refuse content outside the site root: ' . $sub_type );
			} catch ( MainWP_Exception $e ) {
				$this->assertSame( MainWP_Backup_Error::ERR_UNSUPPORTED_LAYOUT, $e->get_message_error_code() );
				$this->assertSame( esc_html( MainWP_Backup_Error::message( MainWP_Backup_Error::ERR_UNSUPPORTED_LAYOUT ) ), $e->getMessage() );
			}
			$this->assertSame( 0, (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . $this->spine->get_table_name() ) );
			$this->assertNull( \MainWP\Child\MainWP_Child_Backup_Mutex::holder() );
			$this->assertSame( array(), $this->runner->fake_engine->calls );
			$this->assertSame( array(), glob( $this->dir . '/restore-*' ) );
		}
	}

	/** Extraction resolves symlinks, so a restore must refuse content that only looks inside the site root. */
	public function test_create_restore_task_refuses_outward_symlinked_content() {
		$site     = $this->dir . '/linked-site';
		$external = sys_get_temp_dir() . '/mwp-restore-external-' . uniqid();
		mkdir( $site, 0755, true );
		mkdir( $external . '/uploads', 0755, true );
		$this->assertTrue( symlink( $external, $site . '/wp-content' ) );
		$this->runner->test_site_root     = $site;
		$this->runner->test_content_roots = array( $site . '/wp-content', $site . '/wp-content/uploads' );
		try {
			$this->make_task( 'full', array( $this->make_part_artifact() ) );
			$this->fail( 'A restore must refuse wp-content that resolves outside the site root.' );
		} catch ( MainWP_Exception $e ) {
			$this->assertSame( MainWP_Backup_Error::ERR_UNSUPPORTED_LAYOUT, $e->get_message_error_code() );
		} finally {
			unlink( $site . '/wp-content' );
			rmdir( $external . '/uploads' );
			rmdir( $external );
		}
	}

	public function test_create_db_restore_allows_external_content() {
		$this->runner->test_content_roots = array( $this->dir . '/wp-content' );
		$task_id = $this->make_task( 'db', array( $this->make_db_artifact() ) );
		$this->assertSame( 'restore_db', $this->spine->get_task( $task_id )->type );
	}

	public function test_create_restore_allows_empty_site_root() {
		$this->runner->test_site_root = '';
		$this->runner->test_content_roots = null;
		$task_id = $this->make_task( 'files', array( $this->make_part_artifact() ) );
		$this->assertSame( 'restore_files', $this->spine->get_task( $task_id )->type );
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
		$runner->test_content_roots = $this->runner->test_content_roots;
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
			'https://[fd00:ec2::254]/'                           => 'AWS IPv6 metadata IP',
			'https://[::ffff:169.254.169.254]/'                  => 'IPv4-mapped IPv6 metadata IP',
			'https://[::ffff:a9fe:a9fe]/'                        => 'Hex IPv4-mapped IPv6 metadata IP',
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

		try {
			foreach ( array( $manifest['artifacts'][0]['url'], 'https://[::ffff:10.0.0.1]/db.zip', 'https://[::ffff:127.0.0.1]/db.zip' ) as $url ) {
				$manifest['artifacts'][0]['url'] = $url;
				delete_option( 'mainwp_backup_allow_private_pull' );
				$this->assertNull(
					MainWP_Child_Restore_Task_Runner::sanitize_pull_manifest( $manifest, $artifacts ),
					$url . ' must be denied by default.'
				);

				update_option( 'mainwp_backup_allow_private_pull', '1' );
				$clean = MainWP_Child_Restore_Task_Runner::sanitize_pull_manifest( $manifest, $artifacts );
				$this->assertNotNull( $clean, $url . ' must be allowed with the private-pull override.' );
				$this->assertSame( 'db.zip', $clean['artifacts'][0]['file'] );
			}
		} finally {
			delete_option( 'mainwp_backup_allow_private_pull' );
		}
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

	/**
	 * Insert a terminal restore-type task row carrying a known restore id.
	 *
	 * @param string $restore_id Restore id.
	 *
	 * @return string Task id.
	 */
	private function make_terminal_restore( $restore_id ) {
		$task_id = $this->spine->create_task( 'restore_db', array( 'params' => array( 'restore_id' => $restore_id ) ) );
		$this->spine->checkpoint(
			$task_id,
			0,
			array(
				'phase'     => MainWP_Child_Backup_Task::PHASE_FAILED,
				'sub_state' => array( 'params' => array( 'restore_id' => $restore_id ) ),
			)
		);
		return $task_id;
	}

	/** @test */
	public function b14_repeated_restore_id_reuses_active_and_terminal_task() {
		global $wpdb;
		foreach ( array( MainWP_Child_Backup_Task::PHASE_COMPLETED, MainWP_Child_Backup_Task::PHASE_FAILED ) as $phase ) {
			$params = array( 'restore_id' => 'completed' === $phase ? 'b14done' : 'b14fail', 'artifacts' => array() );
			$reused = null;
			$task_id = $this->runner->create_restore_task( 'db', $params, $reused );
			$this->assertFalse( $reused );
			$original = $this->spine->get_task( $task_id );
			$calls = $this->runner->fake_engine->calls;
			$this->assertSame( $task_id, $this->runner->create_restore_task( 'db', $params, $reused ) );
			$this->assertTrue( $reused );
			$this->assertEquals( $original, $this->spine->get_task( $task_id ) );
			$this->assertSame( $calls, $this->runner->fake_engine->calls );
			$this->assertSame( 1, $this->spine->checkpoint( $task_id, 0, array( 'phase' => $phase ), false ) );
			$terminal = $this->spine->get_task( $task_id );
			$this->assertSame( $task_id, $this->runner->create_restore_task( 'db', $params, $reused ) );
			$this->assertTrue( $reused );
			$this->assertEquals( $terminal, $this->spine->get_task( $task_id ) );
			$this->assertSame( $calls, $this->runner->fake_engine->calls );
			$this->assertSame( 1, (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . $this->spine->get_table_name() . ' WHERE restore_id = %s', $params['restore_id'] ) ) );
		}
	}

	/** @test */
	public function b14_restore_id_insert_duplicate_adopts_terminal_task() {
		global $wpdb;
		$table = $this->spine->get_table_name();
		$params = array( 'restore_id' => 'b14race', 'artifacts' => array() );
		$inserted = false;
		$race = function ( $query ) use ( &$race, &$inserted, $table, $params, $wpdb ) {
			if ( 0 === strpos( $query, "INSERT INTO {$table} " ) ) {
				remove_filter( 'query', $race );
				$this->assertNull( $this->spine->get_task_by_restore_id( $params['restore_id'] ) );
				$this->assertSame( 1, $wpdb->insert( $table, array( 'task_id' => 'b14-race-winner', 'phase' => 'completed', 'type' => 'restore_db', 'restore_id' => $params['restore_id'] ) ) );
				$inserted = true;
			}
			return $query;
		};
		add_filter( 'query', $race );
		try {
			$reused = false;
			$this->assertSame( 'b14-race-winner', $this->runner->create_restore_task( 'db', $params, $reused ) );
			$this->assertDirectoryDoesNotExist( $this->runner->scratch_dir( 'b14-race-winner', $this->dir ) );
			$this->assertTrue( $inserted );
			$this->assertTrue( $reused );
			$this->assertSame( 1, (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE restore_id = %s", $params['restore_id'] ) ) );
			$this->assertEmpty( $this->runner->fake_engine->calls );
			$this->assertNull( MainWP_Child_Backup_Mutex::holder() );
		} finally {
			remove_filter( 'query', $race );
		}
	}

	/** @test */
	public function b14_restore_id_insert_duplicate_without_row_throws_generic_error() {
		global $wpdb;
		$table = $this->spine->get_table_name();
		$params = array( 'restore_id' => 'b14gone', 'artifacts' => array() );
		$inserted = false;
		$removed = false;
		$race = function ( $query ) use ( &$race, &$inserted, &$removed, $table, $params, $wpdb ) {
			if ( ! $inserted && 0 === strpos( $query, "INSERT INTO {$table} " ) ) {
				$inserted = true;
				$this->assertSame( 1, $wpdb->insert( $table, array( 'task_id' => 'b14-race-gone', 'phase' => 'completed', 'type' => 'restore_db', 'restore_id' => $params['restore_id'] ) ) );
			} elseif ( $inserted && 0 === strpos( $query, "SELECT * FROM {$table} WHERE restore_id =" ) ) {
				remove_filter( 'query', $race );
				$this->assertSame( 1, $wpdb->delete( $table, array( 'task_id' => 'b14-race-gone' ) ) );
				$removed = true;
			}
			return $query;
		};
		add_filter( 'query', $race );
		$reused = false;
		try {
			$this->runner->create_restore_task( 'db', $params, $reused );
			$this->fail( 'A duplicate without a recoverable row must fail.' );
		} catch ( MainWP_Exception $e ) {
			$this->assertSame( 'The restore task could not be created on this site.', $e->getMessage() );
			$this->assertSame( '', $e->get_message_error_code() );
			$this->assertTrue( $inserted );
			$this->assertTrue( $removed );
			$this->assertFalse( $reused );
			$this->assertEmpty( $this->runner->fake_engine->calls );
			$this->assertNull( MainWP_Child_Backup_Mutex::holder() );
		} finally {
			remove_filter( 'query', $race );
		}
	}

	/** @test */
	public function b14_different_restore_id_after_terminal_creates_new_task() {
		$first = $this->make_task( 'db', array(), array( 'restore_id' => 'b14first' ) );
		$this->assertSame( 1, $this->spine->checkpoint( $first, 0, array( 'phase' => MainWP_Child_Backup_Task::PHASE_COMPLETED ), false ) );
		$second = $this->make_task( 'db', array(), array( 'restore_id' => 'b14second' ) );
		$this->assertNotSame( $first, $second );
		$this->assertSame( 'b14second', $this->spine->get_task( $second )->restore_id );
		$this->assertSame( MainWP_Child_Backup_Task::PHASE_QUEUED, $this->spine->get_task( $second )->phase );
		$this->assertSame( $first, $this->make_task( 'db', array(), array( 'restore_id' => 'b14first' ) ) );
		$this->assertSame( $second, $this->spine->get_active_tasks()[0]->task_id );
	}

	public function test_create_restore_task_refuses_task_inserted_after_precheck() {
		global $wpdb;
		$competing_id = null;
		$insert_race  = function ( $query ) use ( &$insert_race, &$competing_id ) {
			if ( 0 === strpos( $query, 'INSERT INTO ' . $this->spine->get_table_name() . ' ' ) ) {
				remove_filter( 'query', $insert_race );
				$competing_id = $this->spine->create_task( 'full', array(), null, null, null, true );
			}
			return $query;
		};
		add_filter( 'query', $insert_race );
		try {
			$this->make_task( 'db', array() );
			$this->fail( 'A competing task inserted after the pre-check must block the restore.' );
		} catch ( \MainWP\Child\MainWP_Exception $e ) {
			$this->assertSame( esc_html( MainWP_Backup_Error::message( MainWP_Backup_Error::ERR_ALREADY_RUNNING ) ), $e->getMessage() );
			$this->assertSame( MainWP_Backup_Error::ERR_ALREADY_RUNNING, $e->get_message_error_code() );
		} finally {
			remove_filter( 'query', $insert_race );
		}

		$this->assertNotEmpty( $competing_id );
		$this->assertSame( 1, (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . $this->spine->get_table_name() ) );
		$this->assertSame( $competing_id, $this->spine->get_active_tasks()[0]->task_id );
		$this->assertEmpty( $this->runner->fake_engine->calls );
	}

	public function test_restore_create_refuses_backup_mutex_holder() {
		$this->assertTrue( MainWP_Child_Backup_Mutex::acquire( 'legacy', 'backup-owner', 300 ) );
		$holder = MainWP_Child_Backup_Mutex::holder();
		try {
			$this->make_task( 'db', array() );
			$this->fail( 'A backup mutex must block restore creation.' );
		} catch ( MainWP_Exception $e ) {
			$this->assertSame( MainWP_Backup_Error::ERR_ALREADY_RUNNING, $e->get_message_error_code() );
			$this->assertSame( esc_html( MainWP_Backup_Error::message( MainWP_Backup_Error::ERR_ALREADY_RUNNING ) ), $e->getMessage() );
			$this->assertSame( $holder, MainWP_Child_Backup_Mutex::holder() );
			$this->assertEmpty( $this->spine->get_active_tasks() );
		} finally {
			MainWP_Child_Backup_Mutex::release( 'backup-owner' );
		}
	}

	public function test_restore_create_mutex_database_failure_has_write_failed_error_code() {
		global $wpdb;
		$failed = false;
		$block  = static function ( $query ) use ( &$failed ) {
			if ( 0 === strpos( $query, 'INSERT IGNORE INTO' ) && false !== strpos( $query, MainWP_Child_Backup_Mutex::OPTION_NAME ) ) {
				$failed = true;
				return 'SELECT mainwp_restore_mutex_forced_failure';
			}
			return $query;
		};
		$this->assertNull( MainWP_Child_Backup_Mutex::holder() );
		$suppress = $wpdb->suppress_errors();
		add_filter( 'query', $block );
		try {
			$this->make_task( 'db', array() );
			$this->fail( 'A database failure must refuse restore creation.' );
		} catch ( MainWP_Exception $e ) {
			$this->assertTrue( $failed );
			$this->assertSame( MainWP_Backup_Error::ERR_MUTEX_WRITE_FAILED, $e->get_message_error_code() );
			$this->assertSame( esc_html( MainWP_Backup_Error::message( MainWP_Backup_Error::ERR_MUTEX_WRITE_FAILED ) ), $e->getMessage() );
			$this->assertStringNotContainsString( MainWP_Backup_Error::message( MainWP_Backup_Error::ERR_ALREADY_RUNNING ), $e->getMessage() );
			$this->assertEmpty( $this->spine->get_active_tasks() );
			$this->assertNull( MainWP_Child_Backup_Mutex::holder() );
			$this->assertEmpty( $this->runner->fake_engine->calls );
		} finally {
			remove_filter( 'query', $block );
			$wpdb->suppress_errors( $suppress );
		}
	}

	public function test_restore_create_releases_short_mutex_after_insert() {
		$observed = null;
		$started  = time();
		$observe  = function ( $query ) use ( &$observed ) {
			if ( 0 === strpos( $query, 'INSERT INTO ' . $this->spine->get_table_name() . ' ' ) ) {
				$observed = MainWP_Child_Backup_Mutex::holder();
			}
			return $query;
		};
		add_filter( 'query', $observe );
		try {
			$id = $this->make_task( 'db', array() );
			$this->assertStringStartsWith( 'task-', $id );
			$this->assertNotNull( $observed );
			$this->assertSame( 'native', $observed['engine'] );
			$this->assertStringStartsWith( 'restore-create-', $observed['owner'] );
			$this->assertGreaterThanOrEqual( $started + 60, $observed['expires_at'] );
			$this->assertLessThanOrEqual( time() + 60, $observed['expires_at'] );
			$this->assertNull( MainWP_Child_Backup_Mutex::holder() );
		} finally {
			remove_filter( 'query', $observe );
		}
	}

	public function test_backup_create_rechecks_spine_after_mutex_acquire() {
		$competing_id = null;
		$race = function ( $query ) use ( &$race, &$competing_id ) {
			if ( false !== strpos( $query, 'INSERT IGNORE INTO' ) && false !== strpos( $query, MainWP_Child_Backup_Mutex::OPTION_NAME ) ) {
				remove_filter( 'query', $race );
				$competing_id = $this->spine->create_task( 'restore_db' );
			}
			return $query;
		};
		add_filter( 'query', $race );
		try {
			$this->runner->create_task_for_params( 'full', array() );
			$this->fail( 'The post-acquire check must see the competing restore.' );
		} catch ( MainWP_Exception $e ) {
			$this->assertSame( MainWP_Backup_Error::ERR_ALREADY_RUNNING, $e->get_message_error_code() );
			$this->assertSame( esc_html( MainWP_Backup_Error::message( MainWP_Backup_Error::ERR_ALREADY_RUNNING ) ), $e->getMessage() );
			$this->assertSame( $competing_id, $this->spine->get_active_tasks()[0]->task_id );
			$this->assertCount( 1, $this->spine->get_active_tasks() );
			$this->assertNull( MainWP_Child_Backup_Mutex::holder() );
		} finally {
			remove_filter( 'query', $race );
		}
	}

	public function test_backup_busy_read_survives_occupant_becoming_terminal() {
		global $wpdb;
		$competing_id = null;
		$reads = 0;
		$race = function ( $query ) use ( &$race, &$competing_id, &$reads, $wpdb ) {
			if ( 0 === strpos( $query, 'SELECT * FROM ' . $this->spine->get_table_name() . ' WHERE phase NOT IN' ) && 3 === ++$reads ) {
				remove_filter( 'query', $race );
				$competing_id = $this->spine->create_task( 'restore_db' );
				$this->spine->checkpoint( $competing_id, 0, array( 'phase' => MainWP_Child_Backup_Task::PHASE_COMPLETED ) );
				// Return the occupied snapshot even though its row has just finished.
				return $wpdb->prepare( 'SELECT %s AS task_id', $competing_id );
			}
			return $query;
		};
		add_filter( 'query', $race );
		try {
			$this->runner->create_task_for_params( 'full', array() );
			$this->fail( 'An occupied read must retain its retryable error.' );
		} catch ( MainWP_Exception $e ) {
			$this->assertSame( 3, $reads );
			$this->assertSame( MainWP_Backup_Error::ERR_ALREADY_RUNNING, $e->get_message_error_code() );
			$this->assertEmpty( $this->spine->get_active_tasks() );
			$this->assertNull( MainWP_Child_Backup_Mutex::holder() );
		} finally {
			remove_filter( 'query', $race );
		}
	}

	public function test_restore_occupied_result_survives_occupant_becoming_terminal() {
		global $wpdb;
		$competing_id = null;
		$refused = null;
		$race = function ( $query ) use ( &$race, &$competing_id, &$refused, $wpdb ) {
			if ( 0 === strpos( $query, 'INSERT INTO ' . $this->spine->get_table_name() . ' ' ) ) {
				remove_filter( 'query', $race );
				$competing_id = $this->spine->create_task( 'full' );
				$refused = $wpdb->query( $query );
				$this->spine->checkpoint( $competing_id, 0, array( 'phase' => MainWP_Child_Backup_Task::PHASE_COMPLETED ) );
				// Return the refused statement's zero-row result after the occupant finishes.
				return 'SELECT 1 WHERE 1 = 0';
			}
			return $query;
		};
		add_filter( 'query', $race );
		try {
			$this->make_task( 'db', array() );
			$this->fail( 'Occupancy must survive the occupant finishing.' );
		} catch ( MainWP_Exception $e ) {
			$this->assertSame( 0, $refused );
			$this->assertSame( MainWP_Backup_Error::ERR_ALREADY_RUNNING, $e->get_message_error_code() );
			$this->assertSame( esc_html( MainWP_Backup_Error::message( MainWP_Backup_Error::ERR_ALREADY_RUNNING ) ), $e->getMessage() );
			$this->assertSame( MainWP_Child_Backup_Task::PHASE_COMPLETED, $this->spine->get_task( $competing_id )->phase );
			$this->assertEmpty( $this->spine->get_active_tasks() );
			$this->assertNull( MainWP_Child_Backup_Mutex::holder() );
		} finally {
			remove_filter( 'query', $race );
		}
	}

	public function test_create_query_failure_is_not_reclassified_by_new_occupant() {
		foreach ( array( 'backup', 'restore' ) as $kind ) {
			$occupant = null;
			$fail = function ( $query ) use ( &$fail, &$occupant ) {
				if ( 0 === strpos( $query, 'INSERT INTO ' . $this->spine->get_table_name() . ' ' ) ) {
					remove_filter( 'query', $fail );
					$occupant = $this->spine->create_task( 'full' );
					return '';
				}
				return $query;
			};
			add_filter( 'query', $fail );
			try {
				if ( 'backup' === $kind ) {
					$this->runner->create_task_for_params( 'full', array() );
				} else {
					$this->make_task( 'db', array() );
				}
				$this->fail( 'Query failure must throw the generic error.' );
			} catch ( MainWP_Exception $e ) {
				$this->assertSame( '', $e->get_message_error_code() );
				$this->assertSame( 'The ' . $kind . ' task could not be created on this site.', $e->getMessage() );
				$this->assertNotNull( $occupant );
				$this->assertNull( MainWP_Child_Backup_Mutex::holder() );
			} finally {
				remove_filter( 'query', $fail );
				if ( null !== $occupant ) {
					$this->spine->checkpoint( $occupant, 0, array( 'phase' => MainWP_Child_Backup_Task::PHASE_COMPLETED ) );
				}
			}
		}
	}

	/**
	 * An insert failure on an idle spine keeps the generic error on both paths.
	 */
	public function test_guarded_creates_keep_generic_error_when_insert_fails() {
		$observed = null;
		$fail = function ( $query ) use ( &$observed ) {
			if ( 0 === strpos( $query, 'INSERT INTO ' . $this->spine->get_table_name() . ' ' ) ) {
				$observed = MainWP_Child_Backup_Mutex::holder();
			}
			return 0 === strpos( $query, 'INSERT INTO ' . $this->spine->get_table_name() . ' ' ) ? '' : $query;
		};
		add_filter( 'query', $fail );
		try {
			foreach ( array( 'backup', 'restore' ) as $kind ) {
				$started = time();
				try {
					if ( 'backup' === $kind ) {
						$this->runner->create_task_for_params( 'full', array() );
					} else {
						$this->make_task( 'db', array() );
					}
					$this->fail( 'An insert failure must throw.' );
				} catch ( \MainWP\Child\MainWP_Exception $e ) {
					$this->assertSame( 'The ' . $kind . ' task could not be created on this site.', $e->getMessage() );
					$this->assertSame( '', $e->get_message_error_code() );
					$this->assertNotNull( $observed );
					if ( 'restore' === $kind ) {
						$this->assertGreaterThanOrEqual( $started + 60, $observed['expires_at'] );
						$this->assertLessThanOrEqual( time() + 60, $observed['expires_at'] );
					}
					$this->assertEmpty( $this->spine->get_active_tasks() );
					$this->assertNull( \MainWP\Child\MainWP_Child_Backup_Mutex::holder() );
				}
			}
		} finally {
			remove_filter( 'query', $fail );
			$holder = \MainWP\Child\MainWP_Child_Backup_Mutex::holder();
			if ( null !== $holder ) {
				\MainWP\Child\MainWP_Child_Backup_Mutex::release( $holder['owner'] );
			}
		}
		$this->assertEmpty( $this->runner->fake_engine->calls );
	}

	/**
	 * Creating a restore sweeps the aside tables of every prior restore. The
	 * single-active-task guard means those priors are all terminal, so their
	 * old<id>_/tmp<id>_ tables are superseded litter.
	 */
	public function test_create_restore_task_prunes_prior_restore_aside_tables() {
		$this->make_terminal_restore( 'prior01' );

		$this->make_task( 'db', array(), array( 'restore_id' => 'newrid01' ) );

		$this->assertContains( 'prune_aside_tables', $this->runner->fake_engine->calls );
		$swept = end( $this->runner->fake_engine->pruned_id_sets );
		$this->assertContains( 'prior01', $swept, 'a prior restore id is swept' );
		$this->assertContains( 'newrid01', $swept, 'the new restore id is included (no-op on a fresh id)' );
	}

	/**
	 * With no prior restores, the sweep targets only the just-created restore.
	 */
	public function test_create_restore_task_with_no_prior_sweeps_only_current() {
		$this->make_task( 'db', array(), array( 'restore_id' => 'solo01' ) );

		$swept = end( $this->runner->fake_engine->pruned_id_sets );
		$this->assertSame( array( 'solo01' ), $swept );
	}

	/**
	 * Multiple prior restores are all swept in one pass.
	 */
	public function test_create_restore_task_prunes_all_prior_restores() {
		$this->make_terminal_restore( 'priora1' );
		$this->make_terminal_restore( 'priorb2' );

		$this->make_task( 'db', array(), array( 'restore_id' => 'newrid02' ) );

		$swept = end( $this->runner->fake_engine->pruned_id_sets );
		$this->assertContains( 'priora1', $swept );
		$this->assertContains( 'priorb2', $swept );
	}

	public function test_outcome_guard_phase_order_for_each_restore_type() {
		$prefix = array( 'queued', 'pulling', 'receiving', 'verifying' );
		$orders = array(
			'restore' => array_merge( $prefix, array( 'restoring_files', 'replaying_db', 'swapping_db', 'rewriting_urls', 'verify_site', 'completed' ) ),
			'restore_db' => array_merge( $prefix, array( 'replaying_db', 'swapping_db', 'rewriting_urls', 'verify_site', 'completed' ) ),
			'restore_files' => array_merge( $prefix, array( 'restoring_files', 'verify_site', 'completed' ) ),
		);
		foreach ( $orders as $type => $expected ) {
			$actual = array( 'queued' );
			while ( null !== ( $next = MainWP_Child_Backup_Task::next_phase( end( $actual ), $type ) ) ) {
				$actual[] = $next;
			}
			$this->assertSame( $expected, $actual, $type );
		}
	}

	public function test_outcome_guard_resume_between_files_and_database() {
		$artifact = $this->make_full_archive_with_embedded_dump();
		unset( $artifact['sha256'] );
		$manifest = $this->make_manifest_artifact( array( 'wp-content/uploads/restored.txt' ) );
		$over = $this->reconcile_options();
		$this->assertArrayNotHasKey( 'skipped_count', $over['reconcile']['walk'] );
		$id = $this->make_task( 'full', array( $artifact, $manifest ), $over );
		$this->runner->fake_engine->replay_yield_once = true;
		list( $task ) = $this->advance_until( $id, static function ( $sub, $task ) {
			return 'replaying_db' === $task->phase;
		} );
		$this->assertSame( 'restored-content', file_get_contents( $this->site . '/wp-content/uploads/restored.txt' ) );
		$this->assertSame( 'replaying_db', $task->phase );
		$this->assertSame( 'completed', $this->drive( $id )->phase );
		$this->assertSame( 1, count( array_filter( $this->runner->fake_engine->calls, static function ( $call ) {
			return 0 === strpos( $call, 'swap_tables:' );
		} ) ) );
	}

	private static function beta6_cancel_read( $payload ) {
		$result = (string) ( $payload['result'] ?? '' );
		if ( in_array( $result, array( 'ok', 'busy', 'rolling_back' ), true ) ) {
			return $result;
		}
		if ( 'not_found' === $result ) {
			return 'failed';
		}
		if ( 'already_terminal' === $result ) {
			return 'completed' === (string) ( $payload['phase'] ?? '' ) ? 'completed' : 'failed';
		}
		throw new RuntimeException( $payload['error'] ?? 'Cancel unconfirmed' );
	}

	private static function beta6_running_read( $payload ) {
		if ( ! isset( $payload['phase'] ) && isset( $payload['error'] ) ) {
			return array( 'retryable', $payload['error'], $payload['error_code'] ?? '' );
		}
		if ( 'completed' === ( $payload['phase'] ?? '' ) ) {
			return array( 'completed', $payload['sub_state']['verification'] ?? array() );
		}
		if ( 'failed' === ( $payload['phase'] ?? '' ) ) {
			return array( 'failed', $payload['error'] ?? $payload['sub_state']['error']['message'] ?? '', $payload['error_code'] ?? $payload['sub_state']['error']['code'] ?? '' );
		}
		return array( 'poll' );
	}

	public function test_outcome_guard_beta6_readers() {
		foreach ( array( 'ok' => 'ok', 'busy' => 'busy', 'rolling_back' => 'rolling_back', 'not_found' => 'failed' ) as $result => $expected ) {
			$this->assertSame( $expected, self::beta6_cancel_read( array( 'result' => $result ) ) );
		}
		$this->assertSame( 'completed', self::beta6_cancel_read( array( 'result' => 'already_terminal', 'phase' => 'completed' ) ) );
		$this->assertSame( 'failed', self::beta6_cancel_read( array( 'result' => 'already_terminal', 'phase' => 'failed' ) ) );
		$this->assertSame( array( 'failed', 'top message', 'top code' ), self::beta6_running_read( array( 'phase' => 'failed', 'error' => 'top message', 'error_code' => 'top code', 'sub_state' => array( 'error' => array( 'message' => 'nested message', 'code' => 'nested code' ) ) ) ) );
		$this->assertSame( array( 'failed', 'nested message', 'nested code' ), self::beta6_running_read( array( 'phase' => 'failed', 'sub_state' => array( 'error' => array( 'message' => 'nested message', 'code' => 'nested code' ) ) ) ) );
		$this->assertSame( array( 'completed', array( 'sentinel_ok' => true ) ), self::beta6_running_read( array( 'phase' => 'completed', 'sub_state' => array( 'verification' => array( 'sentinel_ok' => true ) ) ) ) );
		$this->expectException( RuntimeException::class );
		self::beta6_cancel_read( array( 'error' => 'request failed' ) );
	}


	private function pause_outcome_task( $id, $phase ) {
		$this->runner->pause_before_phase = $phase;
		return $this->advance_until( $id, static function ( $sub, $task ) use ( $phase ) {
			return $phase === $task->phase;
		} );
	}

	private function set_outcome_cursor( $id, $phase, $patch = array(), $remove = array() ) {
		$task = $this->spine->get_task( $id );
		$sub = $this->spine->read_sub_state( $task, $this->dir );
		$sub = array_replace_recursive( $sub, $patch );
		foreach ( $remove as $key ) {
			unset( $sub[ $key ] );
		}
		$this->assertNotFalse( $this->spine->checkpoint( $id, (int) $task->version, array( 'phase' => $phase, 'sub_state' => $sub ), false, $this->dir ) );
		return $this->spine->get_task( $id );
	}

	private function stored_outcome( $id ) {
		$payload = $this->spine->status_payload( $this->spine->get_task( $id ), $this->dir );
		$this->assertArrayHasKey( 'outcome', $payload['sub_state']['verification'] ?? array() );
		return $payload['sub_state']['verification']['outcome'];
	}

	private function force_outcome_terminal( $id, $writer ) {
		return $this->with_dump_sweep_singleton( function () use ( $id, $writer ) {
			global $wpdb;
			if ( 'watchdog' === $writer ) {
				$wpdb->query( $wpdb->prepare( 'UPDATE ' . $this->spine->get_table_name() . ' SET resume_count = max_resumes, last_progress_at = DATE_SUB(NOW(), INTERVAL 1 HOUR) WHERE task_id = %s', $id ) );
				\MainWP\Child\MainWP_Child_Backup_Watchdog::instance()->check_tasks( 1 );
				return 'failed' === $this->spine->get_task( $id )->phase;
			}
			$this->force_deadline_past( $id );
			return 'reclaim' === $writer ? 1 === $this->spine->reclaim_expired_tasks( $this->dir ) : $this->spine->enforce_wall_clock_deadline( $id );
		} );
	}

	public static function outcome_fixture_cases() {
		$cases = array( 'completed-full', 'completed-files-only', 'completed-db-only', 'failed-before-any-change', 'failed-mid-files', 'failed-after-files-before-swap', 'failed-after-swap', 'fk-rolled-back', 'fk-rollback-failed', 'cancelled-before-any-change', 'cancelled-mid-files', 'cancelled-after-files-before-swap', 'cancelled-after-swap' );
		return array_combine( $cases, array_map( static function ( $case ) { return array( $case ); }, $cases ) );
	}

	private function outcome_fixture_payload( $case ) {
		$this->runner->test_cache_purger = new class {
			public function auto_purge_cache() {
				update_option( 'mainwp_cache_control_log', '{"action":"SUCCESS","result_basis":"not_attempted"}' );
			}
		};
		$fk = 0 === strpos( $case, 'fk-' );
		$type = $fk || 'completed-db-only' === $case ? 'db' : ( 'completed-files-only' === $case ? 'files' : 'full' );
		$artifacts = array();
		if ( 'db' !== $type ) {
			$artifacts[] = $this->make_named_zip_artifact( 'outcome-files.zip', array( 'wp-content/uploads/restored.txt' => 'restored-content', 'wp-content/uploads/second.txt' => 'second' ) );
		}
		if ( 'files' !== $type ) {
			$artifacts[] = $this->make_db_artifact( $fk );
		}
		// The FK in-place path needs matching prefixes; across prefixes it is refused.
		$id = $this->make_task( $type, $artifacts, $fk ? array( 'source_prefix' => 'rt_' ) : array() );
		$this->runner->fake_snapshot_bytes = 0;
		$listener = null;
		try {
			if ( $fk ) {
				$this->runner->fake_engine->replay_error = 'replay fixture failed';
				if ( 'fk-rollback-failed' === $case ) {
					$this->runner->fake_engine->rollback_error = 'rollback fixture failed';
				}
			} elseif ( 'failed-before-any-change' === $case ) {
				$this->assertTrue( $this->force_outcome_terminal( $id, 'deadline' ) );
			} elseif ( 'failed-mid-files' === $case || 'failed-after-swap' === $case ) {
				$point = 'failed-mid-files' === $case ? 'after_first_file_change' : 'after_swap_commit';
				$listener = static function ( $actual, $task_id ) use ( $id, $point ) {
					if ( $id === $task_id && $point === $actual ) {
						throw new MainWP_Exception( 'checkpoint fixture failed', null, MainWP_Backup_Error::ERR_RESTORE_DB_REPLAY );
					}
				};
				add_action( 'mainwp_child_restore_checkpoint', $listener, 10, 2 );
			} elseif ( 'failed-after-files-before-swap' === $case ) {
				$this->runner->fake_engine->replay_throw = true;
			} elseif ( 'cancelled-mid-files' === $case ) {
				$this->slice_one_file_at_a_time();
				$this->advance_until( $id, static function ( $sub, $task ) {
					return 'restoring_files' === $task->phase && ! empty( $sub['file_restore']['part_cursor']['entry_index'] );
				} );
			} elseif ( 'cancelled-after-files-before-swap' === $case || 'cancelled-after-swap' === $case ) {
				$this->pause_outcome_task( $id, 'cancelled-after-swap' === $case ? 'rewriting_urls' : 'replaying_db' );
			}
			if ( 0 === strpos( $case, 'cancelled-' ) ) {
				$payload = $this->callable_cancel( array( 'task_id' => $id ) );
				$this->assertSame( 'ok', $payload['result'] );
				$this->assertSame( 'failed', $payload['phase'] );
				$this->assertArrayNotHasKey( 'error', $payload );
				return $payload;
			}
			$task = $this->drive( $id );
			$this->assertSame( 0 === strpos( $case, 'completed-' ) ? 'completed' : 'failed', $task->phase );
			return $this->spine->status_payload( $task, $this->dir );
		} finally {
			if ( null !== $listener ) {
				remove_action( 'mainwp_child_restore_checkpoint', $listener, 10 );
			}
		}
	}

	private static function normalize_outcome_payload( $payload ) {
		foreach ( array( 'task_id', 'started_at', 'last_progress_at', 'max_wall_clock_at', 'version' ) as $key ) {
			if ( array_key_exists( $key, $payload ) ) {
				$payload[ $key ] = '<' . $key . '>';
			}
		}
		return json_decode( wp_json_encode( $payload ), true );
	}

	/** @dataProvider outcome_fixture_cases */
	public function test_outcome_response_fixture_and_beta6_compatibility( $case ) {
		$payload = self::normalize_outcome_payload( $this->outcome_fixture_payload( $case ) );
		$path = __DIR__ . '/fixtures/restore-outcome-' . $case . '.json';
		$expected = json_decode( file_get_contents( $path ), true );
		$this->assertIsArray( $expected, $path );
		$this->assertEquals( $expected, $payload, $case );
		$old = $payload;
		unset( $old['verification']['outcome'], $old['failure'], $old['sub_state']['verification']['outcome'] );
		if ( isset( $payload['result'] ) ) {
			$this->assertSame( self::beta6_cancel_read( $old ), self::beta6_cancel_read( $payload ) );
		} else {
			$new_read = self::beta6_running_read( $payload );
			if ( 'completed' === $payload['phase'] ) {
				$this->assertSame( $payload['sub_state']['verification']['outcome'], $new_read[1]['outcome'] );
				unset( $new_read[1]['outcome'] );
			}
			$this->assertEquals( self::beta6_running_read( $old ), $new_read );
		}
	}

	public function test_outcome_derivation_never_applies_an_unfinished_half() {
		$phases = array( 'queued', 'pulling', 'receiving', 'verifying', 'restoring_files', 'snapshot_db', 'replaying_db', 'preserve_woo', 'swapping_db', 'rewriting_urls', 'merge_woo', 'verify_site', 'fk_rollback' );
		foreach ( array( 'restore', 'restore_files', 'restore_db' ) as $type ) {
			foreach ( $phases as $phase ) {
				foreach ( array( 'completed', 'failed', 'cancelled' ) as $kind ) {
					foreach ( array( array(), array( 'files_touched' => false ), array( 'files_touched' => true ) ) as $cursor ) {
						foreach ( array( false, true, null ) as $probe ) {
							$out = MainWP_Child_Restore_Task_Runner::derive_outcome( $type, $phase, $cursor, $kind, '', $probe );
							$label = wp_json_encode( array( $type, $phase, $kind, $cursor, $probe ) );
							$this->assertFalse( $out['files_copy_taken'], $label );
							$this->assertSame( $phase, $out['phase_reached'], $label );
							if ( 'restore_db' === $type ) {
								$this->assertSame( 'not_applicable', $out['files'], $label );
							} elseif ( 'completed' === $kind ) {
								$this->assertSame( 'applied', $out['files'], $label );
							} elseif ( in_array( $phase, array( 'queued', 'pulling', 'receiving', 'verifying' ), true ) ) {
								$this->assertSame( 'not_started', $out['files'], $label );
							} elseif ( 'restoring_files' === $phase ) {
								$this->assertSame( isset( $cursor['files_touched'] ) && false === $cursor['files_touched'] ? 'not_started' : 'left_partial', $out['files'], $label );
							} else {
								$this->assertSame( 'applied', $out['files'], $label );
							}
							if ( 'restore_files' === $type ) {
								$this->assertSame( 'not_applicable', $out['database'], $label );
							} elseif ( 'completed' === $kind ) {
								$this->assertSame( 'applied', $out['database'], $label );
							} else {
								$this->assertNotSame( 'applied', $out['database'], $label );
								if ( in_array( $phase, array( 'queued', 'pulling', 'receiving', 'verifying', 'restoring_files', 'snapshot_db', 'replaying_db', 'preserve_woo' ), true ) || ( 'swapping_db' === $phase && false === $probe ) ) {
									$this->assertSame( 'not_started', $out['database'], $label );
								} else {
									$this->assertSame( 'left_partial', $out['database'], $label );
								}
							}
						}
					}
				}
			}
		}
	}

	/** @dataProvider outcome_forced_writers */
	public function test_outcome_forced_end_between_files_and_database( $writer ) {
		$id = $this->make_task( 'full', array( $this->make_part_artifact(), $this->make_db_artifact() ) );
		$this->pause_outcome_task( $id, 'replaying_db' );
		$this->assertTrue( $this->force_outcome_terminal( $id, $writer ) );
		$this->assertSame( array( 'files' => 'applied', 'database' => 'not_started', 'files_copy_taken' => false, 'phase_reached' => 'replaying_db' ), $this->stored_outcome( $id ) );
		$payload = $this->spine->status_payload( $this->spine->get_task( $id ), $this->dir );
		$this->assertSame( 'watchdog' === $writer ? MainWP_Backup_Error::ERR_STALLED : MainWP_Backup_Error::ERR_WALL_CLOCK, $payload['error_code'] );
	}

	public static function outcome_forced_writers() {
		return array( 'deadline' => array( 'deadline' ), 'watchdog' => array( 'watchdog' ), 'reclaim' => array( 'reclaim' ) );
	}

	/** @dataProvider outcome_forced_writers */
	public function test_outcome_forced_end_after_uncheckpointed_file_change( $writer ) {
		$id = $this->make_task( 'full', array( $this->make_part_artifact(), $this->make_db_artifact() ) );
		$observed = false;
		$listener = function ( $point, $task_id ) use ( $id, &$observed ) {
			if ( 'after_first_file_change' !== $point || $id !== $task_id ) {
				return;
			}
			$observed = true;
			$task = $this->spine->get_task( $id );
			$sub = $this->spine->read_sub_state( $task, $this->dir );
			$this->assertTrue( $sub['files_touched'] );
			$this->assertArrayNotHasKey( 'file_restore', $sub );
			$this->assertFileExists( $this->site . '/wp-content/uploads/restored.txt' );
			global $wpdb;
			$wpdb->query( $wpdb->prepare( 'UPDATE ' . $this->spine->get_table_name() . ' SET lease_expires_at = DATE_SUB(NOW(), INTERVAL 1 SECOND) WHERE task_id = %s', $id ) );
			$this->assertTrue( $this->spine->acquire_lease( $id, 'crash-successor', (int) $task->version ) );
			throw new MainWP_Exception( 'host interrupted the slice', null, MainWP_Backup_Error::ERR_RESTORE_EXTRACT );
		};
		$this->advance_until( $id, function ( $sub, $task ) {
			return 'restoring_files' === $task->phase;
		} );
		add_action( 'mainwp_child_restore_checkpoint', $listener, 10, 2 );
		try {
			for ( $i = 0; $i < 5 && ! $observed; ++$i ) {
				$this->runner->advance_one_slice( $this->spine->get_task( $id ), 'crashed' );
			}
		} finally {
			remove_action( 'mainwp_child_restore_checkpoint', $listener, 10 );
		}
		$this->assertTrue( $observed );
		$task = $this->spine->get_task( $id );
		$this->assertSame( 'restoring_files', $task->phase );
		$this->spine->release_lease( $id, 'crash-successor', (int) $task->version );
		$this->assertTrue( $this->force_outcome_terminal( $id, $writer ) );
		$this->assertSame( 'left_partial', $this->stored_outcome( $id )['files'] );
	}

	public static function outcome_swap_ends() {
		$rows = array();
		foreach ( array( 'slice', 'cancel', 'deadline', 'watchdog', 'reclaim' ) as $writer ) {
			foreach ( array( 'no' => false, 'yes' => true, 'unknown' => null ) as $name => $probe ) {
				$rows[ $writer . '-' . $name ] = array( $writer, $probe );
			}
		}
		return $rows;
	}

	/** @dataProvider outcome_swap_ends */
	public function test_outcome_swap_probe_precedes_tmp_drop( $writer, $probe ) {
		$id = $this->make_task( 'db', array( $this->make_db_artifact() ) );
		$this->pause_outcome_task( $id, 'swapping_db' );
		$this->runner->fake_engine->calls = array();
		$this->runner->fake_engine->swap_committed = $probe;
		if ( 'slice' === $writer ) {
			$this->runner->fake_engine->swap_error = 'swap fixture failed';
			$task = $this->drive( $id );
			$this->assertSame( 'failed', $task->phase );
			$this->assertSame( MainWP_Backup_Error::ERR_RESTORE_SWAP_FAILED, $this->spine->status_payload( $task, $this->dir )['error_code'] );
		} elseif ( 'cancel' === $writer ) {
			$this->assertSame( 'ok', $this->callable_cancel( array( 'task_id' => $id ) )['result'] );
		} else {
			$this->assertTrue( $this->force_outcome_terminal( $id, $writer ) );
		}
		$this->assertSame( false === $probe ? 'not_started' : 'left_partial', $this->stored_outcome( $id )['database'] );
		$calls = $this->runner->fake_engine->calls;
		$this->assertContains( 'is_swap_committed', $calls );
		$this->assertContains( 'cleanup_tmp_tables', $calls );
		$this->assertLessThan( array_search( 'cleanup_tmp_tables', $calls, true ), array_search( 'is_swap_committed', $calls, true ) );
		$this->assertNotContains( 'drop_old_tables', $calls );
	}

	/** @dataProvider outcome_post_swap_failures */
	public function test_outcome_post_swap_failure_keeps_restored_and_old_tables( $failure ) {
		$id = $this->dump_sweep_fixture( 'rewriting_urls' );
		global $wpdb;
		$wpdb->insert( 'rt_items', array( 'id' => 99, 'val' => 'restored bytes' ) );
		$this->set_outcome_cursor( $id, 'merge' === $failure ? 'merge_woo' : ( 'rewrite' === $failure ? 'rewriting_urls' : 'verify_site' ), array( 'old_tables' => array( 'rt_dump_sweep_old' ) ) );
		$this->runner->throw_url_rewrite = 'rewrite' === $failure;
		$this->runner->throw_woo_merge = 'merge' === $failure;
		$this->runner->fake_engine->verify_ok = 'counts' !== $failure;
		$listener = static function ( $point ) use ( $failure ) {
			if ( 'listener' === $failure && 'before_post_swap_check' === $point ) {
				throw new MainWP_Exception( 'post check fixture failed', null, MainWP_Backup_Error::ERR_RESTORE_DB_REPLAY );
			}
		};
		add_action( 'mainwp_child_restore_checkpoint', $listener, 10, 2 );
		try {
			$this->assertSame( 'failed', $this->drive( $id )->phase );
		} finally {
			remove_action( 'mainwp_child_restore_checkpoint', $listener, 10 );
		}
		$this->assertSame( 'left_partial', $this->stored_outcome( $id )['database'] );
		$this->assertNotContains( 'drop_old_tables', $this->runner->fake_engine->calls );
		$this->assertSame( '17', $wpdb->get_var( 'SELECT id FROM rt_dump_sweep_old' ) );
		$this->assertSame( 'restored bytes', $wpdb->get_var( 'SELECT val FROM rt_items WHERE id = 99' ) );
	}

	public static function outcome_post_swap_failures() {
		return array( 'rewrite' => array( 'rewrite' ), 'merge' => array( 'merge' ), 'counts' => array( 'counts' ), 'listener' => array( 'listener' ) );
	}


	/** @dataProvider outcome_stale_actors */
	public function test_outcome_stale_slice_and_cancel_keep_successor_state( $actor ) {
		$id = $this->dump_sweep_fixture();
		$this->runner->fake_engine->replay_throw = 'slice' === $actor;
		$successor_row = null;
		$this->runner->before_terminal_cleanup = function () use ( $id, &$successor_row ) {
			global $wpdb;
			$wpdb->query( $wpdb->prepare( 'UPDATE ' . $this->spine->get_table_name() . ' SET lease_expires_at = DATE_SUB(NOW(), INTERVAL 1 SECOND) WHERE task_id = %s', $id ) );
			$row = $this->spine->get_task( $id );
			$this->assertTrue( $this->spine->acquire_lease( $id, 'successor', (int) $row->version ) );
			$successor_row = $this->spine->get_task( $id );
		};
		if ( 'slice' === $actor ) {
			$this->runner->advance_one_slice( $this->spine->get_task( $id ), 'stale' );
		} else {
			$answer = $this->callable_cancel( array( 'task_id' => $id ) );
			$this->assertSame( 'busy', $answer['result'] );
			$this->assertArrayNotHasKey( 'verification', $answer );
		}
		$this->assertNotNull( $successor_row );
		$this->assertEquals( $successor_row, $this->spine->get_task( $id ) );
		$this->assert_dump_sweep_state( $id, true, true, true, true );
		$this->assertNotContains( 'cleanup_tmp_tables', $this->runner->fake_engine->calls );
		$this->assertNotContains( 'exit_maintenance', $this->runner->fake_engine->calls );
		$this->assertArrayNotHasKey( 'verification', $this->spine->read_sub_state( $successor_row, $this->dir ) );
		$this->spine->release_lease( $id, 'successor', (int) $successor_row->version );
		$this->runner->fake_engine->replay_throw = false;
		$this->assertSame( 'completed', $this->drive( $id )->phase );
		$this->assertSame( 'applied', $this->stored_outcome( $id )['database'] );
	}

	public static function outcome_stale_actors() {
		return array( 'slice' => array( 'slice' ), 'cancel' => array( 'cancel' ) );
	}

	public static function outcome_legacy_cursors() {
		return array(
			'verifying' => array( 'verifying', false, 'not_started', 'not_started' ),
			'files-without-cursor' => array( 'restoring_files', false, 'left_partial', 'not_started' ),
			'files-with-cursor' => array( 'restoring_files', true, 'left_partial', 'not_started' ),
			'replay' => array( 'replaying_db', true, 'applied', 'not_started' ),
			'swap' => array( 'swapping_db', true, 'applied', 'left_partial' ),
			'verify-site' => array( 'verify_site', true, 'applied', 'left_partial' ),
		);
	}

	/** @dataProvider outcome_legacy_cursors */
	public function test_outcome_legacy_cursor_deadline_is_conservative( $phase, $with_files, $files, $database ) {
		$id = $this->make_task( 'full', array( $this->make_part_artifact(), $this->make_db_artifact() ) );
		$patch = $with_files ? array( 'file_restore' => array( 'op_index' => 0 ) ) : array();
		$this->set_outcome_cursor( $id, $phase, $patch, array( 'files_touched' ) );
		$this->runner->fake_engine->swap_committed = null;
		$this->assertTrue( $this->force_outcome_terminal( $id, 'deadline' ) );
		$out = $this->stored_outcome( $id );
		$this->assertSame( $files, $out['files'] );
		$this->assertSame( $database, $out['database'] );
	}

	public function test_outcome_legacy_archives_and_walk_complete_without_new_fields() {
		$artifact = $this->make_full_archive_with_embedded_dump();
		unset( $artifact['sha256'] );
		$manifest = $this->make_manifest_artifact( array( 'wp-content/uploads/restored.txt' ) );
		$over = $this->reconcile_options();
		$this->assertArrayNotHasKey( 'skipped_count', $over['reconcile']['walk'] );
		$id = $this->make_task( 'full', array( $artifact, $manifest ), $over );
		$this->assertSame( 'completed', $this->drive( $id )->phase );
		$this->assertSame( 'restored-content', file_get_contents( $this->site . '/wp-content/uploads/restored.txt' ) );
		$this->assertSame( 'applied', $this->stored_outcome( $id )['files'] );
		$this->assertSame( 'applied', $this->stored_outcome( $id )['database'] );
	}

	public function test_outcome_deadline_retries_with_new_cursor_and_one_terminal_write() {
		global $wpdb;
		$id = $this->make_task( 'full', array() );
		$this->set_outcome_cursor( $id, 'restoring_files' );
		$this->force_deadline_past( $id );
		$attempts = array();
		$raced_version = null;
		$race = function ( $query ) use ( $id, &$attempts, &$raced_version ) {
			if ( false !== strpos( $query, "SET phase = 'failed', active_slot = NULL" ) && false !== strpos( $query, $id ) ) {
				$attempts[] = $query;
				if ( 1 === count( $attempts ) ) {
					$row = $this->set_outcome_cursor( $id, 'restoring_files', array( 'files_touched' => true ) );
					$raced_version = (int) $row->version;
				}
			}
			return $query;
		};
		add_filter( 'query', $race );
		try {
			$this->assertTrue( $this->with_dump_sweep_singleton( function () use ( $id ) { return $this->spine->enforce_wall_clock_deadline( $id ); } ) );
		} finally {
			remove_filter( 'query', $race );
		}
		$this->assertCount( 2, $attempts );
		$this->assertStringContainsString( 'AND version = ', $attempts[0] );
		$this->assertSame( $raced_version + 1, (int) $this->spine->get_task( $id )->version );
		$this->assertSame( 'left_partial', $this->stored_outcome( $id )['files'] );
	}

	public function test_outcome_deadline_retry_is_bounded() {
		$id = $this->make_task( 'full', array() );
		$this->force_deadline_past( $id );
		$attempts = 0;
		$race = function ( $query ) use ( $id, &$attempts ) {
			if ( false !== strpos( $query, "SET phase = 'failed', active_slot = NULL" ) && false !== strpos( $query, $id ) ) {
				++$attempts;
				$this->set_outcome_cursor( $id, 'queued' );
			}
			return $query;
		};
		add_filter( 'query', $race );
		try {
			$this->assertFalse( $this->with_dump_sweep_singleton( function () use ( $id ) { return $this->spine->enforce_wall_clock_deadline( $id ); } ) );
		} finally {
			remove_filter( 'query', $race );
		}
		$this->assertSame( 3, $attempts );
		$this->assertSame( 'queued', $this->spine->get_task( $id )->phase );
		$this->assertArrayNotHasKey( 'verification', $this->spine->read_sub_state( $this->spine->get_task( $id ), $this->dir ) );
	}

	/** @dataProvider outcome_forced_writers */
	public function test_outcome_forced_terminal_credentials_stay_scrubbed( $writer ) {
		$id = $this->make_task( 'full', array() );
		$this->set_outcome_cursor( $id, 'restoring_files', array( 'params' => array( 'pull_manifest' => array( 'secret' => 'fixture credential' ) ), 'identity_preserve' => array( 'secret' => 'fixture identity' ), 'padding' => str_repeat( 'x', 60000 ) ) );
		$task = $this->spine->get_task( $id );
		$cursor = $this->spine->read_sub_state( $task, $this->dir );
		$sidecar = $this->spine->write_sub_state( $id, $cursor, $this->dir, (int) $task->version );
		$this->assertNotFalse( $sidecar );
		$sidecar_path = $this->dir . '/' . json_decode( $sidecar, true )['file'];
		$this->assertFileExists( $sidecar_path );
		$this->assertNotFalse( $this->spine->checkpoint( $id, (int) $task->version, array( 'sub_state' => $sidecar ) ) );
		$this->assertTrue( $this->force_outcome_terminal( $id, $writer ) );
		$this->spine->reclaim_expired_tasks( $this->dir );
		$stored = $this->spine->get_task( $id )->sub_state;
		$this->assertStringNotContainsString( 'fixture credential', $stored );
		$this->assertStringNotContainsString( 'fixture identity', $stored );
		$this->assertStringNotContainsString( '_sidecar', $stored );
		$this->stored_outcome( $id );
		$this->assertFileDoesNotExist( $sidecar_path );
	}

	/**
	 * The report must never stop a terminal write or a terminal cancel answer:
	 * with the backup dir unavailable the inline cursor still answers.
	 */
	public function test_outcome_unreadable_backup_dir_does_not_block_terminal_writes() {
		$id = $this->make_task( 'full', array( $this->make_part_artifact(), $this->make_db_artifact() ) );
		$this->set_outcome_cursor( $id, 'replaying_db' );
		$this->force_deadline_past( $id );
		$this->runner->backup_dir_throws = true;
		try {
			$this->with_dump_sweep_singleton( function () use ( $id ) {
				$this->spine->enforce_wall_clock_deadline( $id );
			} );
		} catch ( \Throwable $cleanup_error ) {
			// Cleanup after the terminal write still needs the directory; the write itself must have landed.
			unset( $cleanup_error );
		}
		$answer = $this->runner->cancel_restore_task( $id );
		$this->runner->backup_dir_throws = false;
		$this->assertSame( 'failed', $this->spine->get_task( $id )->phase );
		$this->assertSame( array( 'files' => 'applied', 'database' => 'not_started', 'files_copy_taken' => false, 'phase_reached' => 'replaying_db' ), $this->stored_outcome( $id ) );
		$this->assertSame( 'already_terminal', $answer['result'] );
		$this->assertSame( 'not_started', $answer['verification']['outcome']['database'] );
		$this->assertSame( MainWP_Backup_Error::ERR_WALL_CLOCK, $answer['failure']['code'] );
	}

	/**
	 * A deadline reroute to the FK rollback while an overrun slice is about to
	 * clean up must leave the aside dumps, maintenance and the rollback phase in
	 * place: the reroute clears the expired owner, so the slice's fence fails.
	 */
	public function test_outcome_fk_reroute_during_overrun_cleanup_keeps_rollback_source() {
		$this->dump_sweep_runner();
		// The in-place replay has applied; leaving maintenance then fails, so the slice cleans up.
		$this->runner->fake_engine->exit_maintenance_throw = true;
		$id = $this->make_task( 'db', array( $this->make_db_artifact( true ) ), array( 'source_prefix' => 'rt_' ) );
		$paused = false;
		$aside_at_pause = false;
		$this->runner->before_terminal_cleanup = function () use ( $id, &$paused, &$aside_at_pause ) {
			global $wpdb;
			$paused = true;
			$aside_at_pause = is_dir( $this->runner->scratch_dir( $id, $this->dir ) . 'aside' );
			$wpdb->query( $wpdb->prepare( 'UPDATE ' . $this->spine->get_table_name() . ' SET lease_expires_at = DATE_SUB(NOW(), INTERVAL 1 SECOND) WHERE task_id = %s', $id ) );
			$this->force_deadline_past( $id );
			$this->assertTrue( $this->spine->enforce_wall_clock_deadline( $id ) );
		};
		$task = $this->spine->get_task( $id );
		for ( $i = 0; $i < 30 && ! $paused && ! MainWP_Child_Backup_Task::is_terminal_phase( $task->phase ); ++$i ) {
			$task = $this->runner->advance_one_slice( $task, MainWP_Child_Backup_Task_Runner::LEASE_OWNER_DASHBOARD );
		}
		$this->assertTrue( $paused );
		$this->assertTrue( $aside_at_pause );
		$task = $this->spine->get_task( $id );
		$this->assertSame( MainWP_Child_Backup_Task::PHASE_FK_ROLLBACK, $task->phase );
		$this->assertArrayNotHasKey( 'error', $this->spine->read_sub_state( $task, $this->dir ) );
		$this->assertDirectoryExists( $this->runner->scratch_dir( $id, $this->dir ) . 'aside' );
	}

	/**
	 * The cap must not terminalize while an overrun slice has re-extended its
	 * lease to clean up: the watchdog's probe would run after the slice dropped
	 * the tmp tables, and the slice's own failure write would follow it.
	 */
	public function test_outcome_watchdog_cap_yields_to_a_slice_that_re_extended_its_lease() {
		global $wpdb;
		$id = $this->make_task( 'full', array( $this->make_part_artifact(), $this->make_db_artifact() ) );
		$this->set_outcome_cursor( $id, 'swapping_db', array( 'files_touched' => true ) );
		$task = $this->spine->get_task( $id );
		$this->assertTrue( $this->spine->acquire_lease( $id, 'overrun-slice', (int) $task->version ) );
		$table = $this->spine->get_table_name();
		$wpdb->query( $wpdb->prepare( 'UPDATE ' . $table . ' SET lease_expires_at = DATE_SUB(NOW(), INTERVAL 1 SECOND), resume_count = max_resumes, last_progress_at = DATE_SUB(NOW(), INTERVAL 1 HOUR) WHERE task_id = %s', $id ) );
		$interleaved = false;
		$spine       = $this->spine;
		$race        = function ( $sql ) use ( $id, $table, $spine, &$interleaved ) {
			// The slice's cleanup fence lands between the watchdog's stalled-task read and its first write.
			if ( ! $interleaved && 0 === strpos( ltrim( $sql ), 'UPDATE ' . $table ) && false !== strpos( $sql, $id ) ) {
				$interleaved = true;
				$this->assertTrue( $spine->renew_lease( $id, 'overrun-slice', 180, true ) );
			}
			return $sql;
		};
		add_filter( 'query', $race );
		try {
			\MainWP\Child\MainWP_Child_Backup_Watchdog::instance()->check_tasks( 1 );
		} finally {
			remove_filter( 'query', $race );
		}
		$this->assertTrue( $interleaved );
		$task = $this->spine->get_task( $id );
		$this->assertSame( 'swapping_db', $task->phase );
		$this->assertSame( 'overrun-slice', $task->lease_owner );
		$this->assertArrayNotHasKey( 'error', $this->spine->read_sub_state( $task, $this->dir ) );
	}

	/** @dataProvider outcome_forced_writers */
	public function test_outcome_fk_rollback_forced_end_keeps_maintenance_and_codes( $writer ) {
		$id = $this->dump_sweep_fixture( 'fk_rollback' );
		$this->set_outcome_cursor( $id, 'fk_rollback', array( 'db_replay' => array( 'fk_path' => true ) ) );
		$this->assertTrue( $this->force_outcome_terminal( $id, $writer ) );
		$payload = $this->spine->status_payload( $this->spine->get_task( $id ), $this->dir );
		$this->assertSame( 'watchdog' === $writer ? MainWP_Backup_Error::ERR_STALLED : MainWP_Backup_Error::ERR_RESTORE_ROLLBACK_FAILED, $payload['error_code'] );
		$this->assertSame( 'left_partial', $this->stored_outcome( $id )['database'] );
		$this->assert_dump_sweep_state( $id, true, true, true, true );
	}

	public function test_outcome_cancel_rollback_busy_and_old_terminal_answers() {
		$id = $this->make_task( 'db', array() );
		$this->set_outcome_cursor( $id, 'fk_rollback', array( 'db_replay' => array( 'fk_path' => true ) ) );
		$before = $this->spine->read_sub_state( $this->spine->get_task( $id ), $this->dir );
		$this->assertSame( array( 'result' => 'rolling_back', 'phase' => 'fk_rollback' ), $this->callable_cancel( array( 'task_id' => $id ) ) );
		$this->assertEquals( $before, $this->spine->read_sub_state( $this->spine->get_task( $id ), $this->dir ) );
		$task = $this->spine->get_task( $id );
		$this->assertTrue( $this->spine->acquire_lease( $id, 'other', (int) $task->version ) );
		$this->assertSame( array( 'result' => 'busy', 'phase' => 'fk_rollback' ), $this->callable_cancel( array( 'task_id' => $id ) ) );
		$task = $this->spine->get_task( $id );
		$this->spine->release_lease( $id, 'other', (int) $task->version );
		$this->set_outcome_cursor( $id, 'failed', array( 'error' => array( 'message' => 'failure at /srv/private/file.php', 'code' => MainWP_Backup_Error::ERR_RESTORE_DB_REPLAY ) ) );
		$answer = $this->callable_cancel( array( 'task_id' => $id ) );
		$this->assertSame( 'already_terminal', $answer['result'] );
		$this->assertArrayNotHasKey( 'verification', $answer );
		$this->assertArrayNotHasKey( 'error', $answer );
		$this->assertSame( \MainWP\Child\MainWP_Backup_Error::sanitize_message( 'failure at /srv/private/file.php' ), $answer['failure']['message'] );
	}

	public function test_outcome_create_replay_and_two_pollers_read_same_terminal_report() {
		$artifact = $this->make_db_artifact();
		$id = $this->make_task( 'db', array( $artifact ), array( 'restore_id' => 'outcomereplay' ) );
		$this->assertSame( 'completed', $this->drive( $id )->phase );
		$one = $this->spine->status_payload( $this->spine->get_task( $id ), $this->dir );
		$reused = false;
		$again = $this->runner->create_restore_task( 'db', array( 'restore_id' => 'outcomereplay', 'artifacts' => array( $artifact ) ), $reused );
		$this->assertSame( $id, $again );
		$this->assertTrue( $reused );
		$two = $this->spine->status_payload( $this->spine->get_task( $again ), $this->dir );
		$this->assertSame( $one, $two );
		$this->assertSame( $one['sub_state']['verification']['outcome'], $this->callable_cancel( array( 'task_id' => $id ) )['verification']['outcome'] );
	}

	public function test_outcome_drill_points_and_task_id_once_per_file_slice() {
		$id = $this->make_task( 'full', array( $this->make_named_zip_artifact( 'two.zip', array( 'a.txt' => 'one', 'b.txt' => 'two' ) ), $this->make_db_artifact() ) );
		$seen = array();
		$slice = 0;
		$listener = function ( $point, $task_id ) use ( $id, &$seen, &$slice ) {
			$this->assertSame( $id, $task_id );
			$seen[] = array( $point, $slice );
			if ( 'after_swap_commit' === $point ) {
				$row = $this->spine->get_task( $id );
				$this->assertSame( 'swapping_db', $row->phase );
				$sub = $this->spine->read_sub_state( $row, $this->dir );
				$this->assertNotEmpty( $sub['old_tables'] );
				$this->assertArrayHasKey( 'identity_preserve', $sub );
				$this->assertContains( 'exit_maintenance', $this->runner->fake_engine->calls );
			}
			if ( 'before_post_swap_check' === $point ) {
				$this->assertNotContains( 'verify_row_counts', $this->runner->fake_engine->calls );
			}
		};
		add_action( 'mainwp_child_restore_checkpoint', $listener, 10, 2 );
		try {
			$task = $this->spine->get_task( $id );
			while ( ! MainWP_Child_Backup_Task::is_terminal_phase( $task->phase ) && ++$slice < 30 ) {
				$task = $this->runner->advance_one_slice( $task, 'drill' );
			}
			$this->assertSame( 'completed', $task->phase );
		} finally {
			remove_action( 'mainwp_child_restore_checkpoint', $listener, 10 );
		}
		$this->assertSame( array( 'after_first_file_change', 'after_swap_commit', 'before_post_swap_check' ), array_column( $seen, 0 ) );
	}

	public function test_outcome_tombstone_delete_is_a_drill_checkpoint() {
		file_put_contents( $this->site . '/stale.txt', 'live' );
		$id = $this->make_task( 'files', array( $this->make_tombstones_artifact() ) );
		$seen = 0;
		$listener = function ( $point, $task_id ) use ( $id, &$seen ) {
			if ( 'after_first_file_change' === $point ) {
				++$seen;
				$this->assertSame( $id, $task_id );
				$this->assertFileDoesNotExist( $this->site . '/stale.txt' );
				$this->assertTrue( $this->spine->read_sub_state( $this->spine->get_task( $id ), $this->dir )['files_touched'] );
				throw new MainWP_Exception( 'delete checkpoint failed', null, MainWP_Backup_Error::ERR_RESTORE_EXTRACT );
			}
		};
		add_action( 'mainwp_child_restore_checkpoint', $listener, 10, 2 );
		try {
			$this->assertSame( 'failed', $this->drive( $id )->phase );
		} finally {
			remove_action( 'mainwp_child_restore_checkpoint', $listener, 10 );
		}
		$this->assertSame( 1, $seen );
		$payload = $this->spine->status_payload( $this->spine->get_task( $id ), $this->dir );
		$this->assertSame( 'delete checkpoint failed', $payload['error'] );
		$this->assertSame( MainWP_Backup_Error::ERR_RESTORE_EXTRACT, $payload['error_code'] );
		$this->assertSame( 'left_partial', $this->stored_outcome( $id )['files'] );
	}

	public function test_outcome_capability_is_available_without_readiness_probes() {
		$report = \MainWP\Child\MainWP_Backup::get()->check_backup_readiness( true );
		$this->assertTrue( $report['restore_outcome_report'] );
	}


	public function test_outcome_fk_and_committed_cursor_evidence_rules() {
		foreach ( array( 'restore', 'restore_db', 'restore_files' ) as $type ) {
			foreach ( array( 'verifying', 'snapshot_db', 'replaying_db', 'preserve_woo', 'swapping_db', 'fk_rollback', 'rewriting_urls', 'merge_woo', 'verify_site' ) as $phase ) {
				foreach ( array( '', MainWP_Backup_Error::ERR_RESTORE_ROLLED_BACK, MainWP_Backup_Error::ERR_RESTORE_ROLLBACK_FAILED ) as $code ) {
					$sub = array( 'files_touched' => true, 'db_replay' => array( 'fk_path' => true ) );
					$out = MainWP_Child_Restore_Task_Runner::derive_outcome( $type, $phase, $sub, 'failed', $code, false );
					$expected = 'not_started';
					if ( 'restore_files' === $type ) {
						$expected = 'not_applicable';
					} elseif ( 'fk_rollback' === $phase && MainWP_Backup_Error::ERR_RESTORE_ROLLED_BACK === $code ) {
						$expected = 'rolled_back';
					} elseif ( in_array( $phase, array( 'replaying_db', 'fk_rollback', 'rewriting_urls', 'merge_woo', 'verify_site' ), true ) ) {
						$expected = 'left_partial';
					}
					$this->assertSame( $expected, $out['database'], wp_json_encode( array( $type, $phase, $code ) ) );
				}
			}
		}
		foreach ( array( array( 'old_tables' => array() ), array( 'restored_mutex' => '' ) ) as $evidence ) {
			$out = MainWP_Child_Restore_Task_Runner::derive_outcome( 'restore', 'swapping_db', $evidence, 'failed', '', false );
			$this->assertSame( 'left_partial', $out['database'] );
		}
	}

	public function test_outcome_file_marker_lost_cas_stops_before_any_mutation() {
		$id = $this->make_task( 'files', array( $this->make_files_archive_artifact() ) );
		$this->advance_until( $id, static function ( $sub, $task ) {
			return 'restoring_files' === $task->phase && isset( $sub['mu_plugins_preserve'] );
		} );
		file_put_contents( $this->site . '/dbBackup-marker.sql.php', 'must survive' );
		$raced = false;
		$race = function ( $query ) use ( $id, &$raced, &$race ) {
			if ( false !== strpos( $query, 'SET last_progress_at = NOW(), version = version + 1, sub_state =' ) && false !== strpos( $query, $id ) && false !== strpos( $query, 'files_touched' ) ) {
				remove_filter( 'query', $race );
				global $wpdb;
				$wpdb->query( $wpdb->prepare( 'UPDATE ' . $this->spine->get_table_name() . ' SET lease_expires_at = DATE_SUB(NOW(), INTERVAL 1 SECOND) WHERE task_id = %s', $id ) );
				$task = $this->spine->get_task( $id );
				$this->assertTrue( $this->spine->acquire_lease( $id, 'marker-successor', (int) $task->version ) );
				$raced = true;
			}
			return $query;
		};
		add_filter( 'query', $race );
		try {
			$task = $this->runner->advance_one_slice( $this->spine->get_task( $id ), 'marker' );
		} finally {
			remove_filter( 'query', $race );
		}
		$this->assertTrue( $raced );
		$this->assertSame( 'restoring_files', $task->phase );
		$this->assertFalse( $this->spine->read_sub_state( $task, $this->dir )['files_touched'] );
		$this->assertFileExists( $this->site . '/dbBackup-marker.sql.php' );
		$this->assertFileDoesNotExist( $this->site . '/wp-content/uploads/restored.txt' );
	}

	public static function outcome_cancel_races() {
		return array( 'terminal-checkpoint' => array( false ), 'fk-reroute-checkpoint' => array( true ) );
	}

	/** @dataProvider outcome_cancel_races */
	public function test_outcome_lost_cancel_cas_returns_stored_report( $fk ) {
		$id = $this->make_task( $fk ? 'db' : 'full', array() );
		if ( $fk ) {
			$this->set_outcome_cursor( $id, 'replaying_db', array( 'db_replay' => array( 'fk_path' => true, 'snapshot_done' => true ) ) );
		}
		$raced = false;
		$target = $fk ? "phase = 'fk_rollback'" : "phase = 'failed'";
		$race = function ( $query ) use ( $id, $fk, $target, &$raced, &$race ) {
			if ( false !== strpos( $query, 'SET last_progress_at = NOW(), version = version + 1' ) && false !== strpos( $query, $target ) && false !== strpos( $query, $id ) ) {
				remove_filter( 'query', $race );
				global $wpdb;
				$wpdb->query( $wpdb->prepare( 'UPDATE ' . $this->spine->get_table_name() . ' SET lease_expires_at = DATE_SUB(NOW(), INTERVAL 1 SECOND) WHERE task_id = %s', $id ) );
				if ( $fk ) {
					$this->assertTrue( $this->force_outcome_terminal( $id, 'deadline' ) );
					$this->assertSame( 'fk_rollback', $this->spine->get_task( $id )->phase );
					$this->assertTrue( $this->force_outcome_terminal( $id, 'deadline' ) );
				} else {
					$this->assertTrue( $this->force_outcome_terminal( $id, 'watchdog' ) );
				}
				$raced = true;
			}
			return $query;
		};
		add_filter( 'query', $race );
		try {
			$answer = $this->callable_cancel( array( 'task_id' => $id ) );
		} finally {
			remove_filter( 'query', $race );
		}
		$this->assertTrue( $raced );
		$this->assertSame( 'already_terminal', $answer['result'] );
		$this->assertSame( 'failed', $answer['phase'] );
		$this->assertArrayNotHasKey( 'error', $answer );
		$this->assertSame( $this->stored_outcome( $id ), $answer['verification']['outcome'] );
		$this->assertSame( $fk ? MainWP_Backup_Error::ERR_RESTORE_ROLLBACK_FAILED : MainWP_Backup_Error::ERR_STALLED, $answer['failure']['code'] );
	}

	public function test_outcome_cancel_at_post_swap_verification_keeps_result_and_report() {
		$id = $this->make_task( 'full', array( $this->make_part_artifact(), $this->make_db_artifact() ) );
		$this->pause_outcome_task( $id, 'verify_site' );
		$answer = $this->callable_cancel( array( 'task_id' => $id ) );
		$this->assertSame( 'ok', $answer['result'] );
		$this->assertSame( 'failed', $answer['phase'] );
		$this->assertSame( array( 'files' => 'applied', 'database' => 'left_partial', 'files_copy_taken' => false, 'phase_reached' => 'verify_site' ), $answer['verification']['outcome'] );
		$this->assertNotContains( 'drop_old_tables', $this->runner->fake_engine->calls );
	}

	public function test_outcome_terminal_rescrub_preserves_report() {
		global $wpdb;
		$id = $this->make_task( 'full', array() );
		$this->runner->cancel_restore_task( $id );
		$expected = $this->stored_outcome( $id );
		$task = $this->spine->get_task( $id );
		$sub = $this->spine->read_sub_state( $task, $this->dir );
		$sub['params']['pull_manifest'] = array( 'secret' => 'fixture credential' );
		$sub['identity_preserve'] = array( 'secret' => 'fixture identity' );
		$sub['padding'] = str_repeat( 'x', 60000 );
		$pointer = $this->spine->write_sub_state( $id, $sub, $this->dir, (int) $task->version );
		$this->assertNotFalse( $pointer );
		$path = $this->dir . '/' . json_decode( $pointer, true )['file'];
		$this->assertFileExists( $path );
		$this->assertSame( 1, $wpdb->update( $this->spine->get_table_name(), array( 'sub_state' => $pointer ), array( 'task_id' => $id ) ) );
		$this->spine->reclaim_expired_tasks( $this->dir );
		$this->assertSame( $expected, $this->stored_outcome( $id ) );
		$stored = $this->spine->get_task( $id )->sub_state;
		$this->assertStringNotContainsString( 'fixture credential', $stored );
		$this->assertStringNotContainsString( 'fixture identity', $stored );
		$this->assertFileDoesNotExist( $path );
	}

	public static function outcome_deadline_races() {
		return array( 'terminal' => array( 'terminal' ), 'leased' => array( 'leased' ), 'future' => array( 'future' ), 'fk-rerouted' => array( 'fk-rerouted' ) );
	}

	/** @dataProvider outcome_deadline_races */
	public function test_outcome_deadline_retry_rechecks_eligibility( $change ) {
		$id = $this->make_task( 'db', array() );
		$this->set_outcome_cursor( $id, 'replaying_db' );
		$this->force_deadline_past( $id );
		$raced = false;
		$race = function ( $query ) use ( $id, $change, &$raced, &$race ) {
			if ( false !== strpos( $query, "SET phase = 'failed', active_slot = NULL" ) && false !== strpos( $query, $id ) ) {
				remove_filter( 'query', $race );
				$row = $this->set_outcome_cursor( $id, 'terminal' === $change ? 'completed' : 'replaying_db', 'fk-rerouted' === $change ? array( 'db_replay' => array( 'fk_path' => true, 'snapshot_done' => true ) ) : array() );
				if ( 'leased' === $change ) {
					$this->assertTrue( $this->spine->acquire_lease( $id, 'deadline-successor', (int) $row->version ) );
				} elseif ( 'future' === $change ) {
					$this->spine->extend_wall_clock( $id, 3600 );
				}
				$raced = true;
			}
			return $query;
		};
		add_filter( 'query', $race );
		try {
			$result = $this->with_dump_sweep_singleton( function () use ( $id ) { return $this->spine->enforce_wall_clock_deadline( $id ); } );
		} finally {
			remove_filter( 'query', $race );
		}
		$this->assertTrue( $raced );
		$this->assertSame( 'fk-rerouted' === $change, $result );
		$this->assertSame( 'terminal' === $change ? 'completed' : ( 'fk-rerouted' === $change ? 'fk_rollback' : 'replaying_db' ), $this->spine->get_task( $id )->phase );
		$this->assertArrayNotHasKey( 'verification', $this->spine->read_sub_state( $this->spine->get_task( $id ), $this->dir ) );
	}

	/** @dataProvider outcome_rollback_errors */
	public function test_outcome_fk_rollback_error_and_throw_keep_recovery( $throw ) {
		$id = $this->dump_sweep_fixture( 'fk_rollback' );
		$this->set_outcome_cursor( $id, 'fk_rollback', array( 'db_replay' => array( 'fk_path' => true ) ) );
		if ( $throw ) {
			$this->runner->fake_engine->rollback_throw = true;
		} else {
			$this->runner->fake_engine->rollback_error = 'rollback failed';
		}
		$this->assertSame( 'failed', $this->drive( $id )->phase );
		$this->assertSame( MainWP_Backup_Error::ERR_RESTORE_ROLLBACK_FAILED, $this->spine->status_payload( $this->spine->get_task( $id ), $this->dir )['error_code'] );
		$this->assertSame( 'left_partial', $this->stored_outcome( $id )['database'] );
		$this->assert_dump_sweep_state( $id, true, true, true, true );
	}

	public static function outcome_rollback_errors() {
		return array( 'returned' => array( false ), 'thrown' => array( true ) );
	}

	public function test_outcome_dump_sweep_change_fires_after_marker_checkpoint() {
		file_put_contents( $this->site . '/dbBackup-first.sql.php', 'old dump' );
		$id = $this->make_task( 'files', array( $this->make_files_archive_artifact() ) );
		$seen = 0;
		$listener = function ( $point, $task_id ) use ( $id, &$seen ) {
			if ( 'after_first_file_change' === $point ) {
				++$seen;
				$this->assertSame( $id, $task_id );
				$this->assertFileDoesNotExist( $this->site . '/dbBackup-first.sql.php' );
				$this->assertFileDoesNotExist( $this->site . '/wp-content/uploads/restored.txt' );
				$this->assertTrue( $this->spine->read_sub_state( $this->spine->get_task( $id ), $this->dir )['files_touched'] );
				throw new MainWP_Exception( 'dump checkpoint failed', null, MainWP_Backup_Error::ERR_RESTORE_EXTRACT );
			}
		};
		add_action( 'mainwp_child_restore_checkpoint', $listener, 10, 2 );
		try {
			$this->assertSame( 'failed', $this->drive( $id )->phase );
		} finally {
			remove_action( 'mainwp_child_restore_checkpoint', $listener, 10 );
		}
		$this->assertSame( 1, $seen );
		$payload = $this->spine->status_payload( $this->spine->get_task( $id ), $this->dir );
		$this->assertSame( 'dump checkpoint failed', $payload['error'] );
		$this->assertSame( MainWP_Backup_Error::ERR_RESTORE_EXTRACT, $payload['error_code'] );
		$this->assertSame( 'left_partial', $this->stored_outcome( $id )['files'] );
	}


	/** @dataProvider outcome_repair_probes */
	public function test_outcome_uniqueness_repair_reports_refused_restore( $probe ) {
		global $wpdb;
		$id = $this->make_task( 'full', array() );
		$this->set_outcome_cursor( $id, 'swapping_db', array( 'db_replay' => array( 'cursor' => array( 'tables' => array( 'items' => array( 'status' => 'ok', 'rows' => 2 ) ) ) ) ) );
		$this->runner->fake_engine->swap_committed = $probe;
		$this->runner->fake_engine->calls = array();
		$this->assertSame( 1, $wpdb->insert( $this->spine->get_table_name(), array( 'task_id' => 'newer-active-fixture', 'type' => 'full', 'phase' => 'queued', 'active_slot' => null, 'started_at' => gmdate( 'Y-m-d H:i:s', time() + 60 ), 'sub_state' => '{}' ) ) );
		$repair = new ReflectionMethod( MainWP_Child_Backup_Task::class, 'repair_active_task_key' );
		$repair->setAccessible( true );
		$suppress = $wpdb->suppress_errors( true );
		try {
			$this->assertTrue( $this->with_dump_sweep_singleton( function () use ( $repair ) { return $repair->invoke( $this->spine ); } ) );
		} finally {
			$wpdb->suppress_errors( $suppress );
		}
		$task = $this->spine->get_task( $id );
		$this->assertSame( 'failed', $task->phase );
		$this->assertSame( MainWP_Backup_Error::ERR_ALREADY_RUNNING, $this->spine->status_payload( $task, $this->dir )['error_code'] );
		$this->assertSame( false === $probe ? 'not_started' : 'left_partial', $this->stored_outcome( $id )['database'] );
		$this->assertSame( 'queued', $this->spine->get_task( 'newer-active-fixture' )->phase );
		$this->assertContains( 'is_swap_committed', $this->runner->fake_engine->calls );
		$this->assertNotContains( 'cleanup_tmp_tables', $this->runner->fake_engine->calls );
		$this->assertNotContains( 'exit_maintenance', $this->runner->fake_engine->calls );
	}

	public static function outcome_repair_probes() {
		return array( 'not-committed' => array( false ), 'committed' => array( true ), 'unknown' => array( null ) );
	}

}
