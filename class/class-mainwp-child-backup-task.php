<?php
/**
 * MainWP Child Backup Task store - the minimal resumable task spine.
 *
 * A persisted task row with an atomic DB-engine lease lets large backups
 * resume across proxy-killed requests. The dashboard continuation is the
 * primary driver; the child-cron watchdog is the fallback. Every state write
 * is a compare-and-swap on the row's version column, executed as a single
 * conditional UPDATE at the database engine - correct even when a persistent
 * object cache makes option-based reads stale.
 *
 * @package MainWP\Child
 */

namespace MainWP\Child;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Class MainWP_Child_Backup_Task
 *
 * @package MainWP\Child
 */
class MainWP_Child_Backup_Task { // phpcs:ignore Generic.Classes.OpeningBraceSameLine.ContentAfterBrace -- NOSONAR.

    const PHASE_QUEUED          = 'queued';
    const PHASE_DUMPING_DB      = 'dumping_db';
    const PHASE_ARCHIVING_FILES = 'archiving_files';
    const PHASE_UPLOADING       = 'uploading';
    const PHASE_SUBMITTING      = 'submitting';
    const PHASE_COMPLETED       = 'completed';
    const PHASE_FAILED          = 'failed';

    // Restore task phases (types restore / restore_db). Names stay
    // within the varchar(20) phase column.
    const PHASE_PULLING_ARTIFACTS   = 'pulling';
    const PHASE_RECEIVING_ARTIFACTS = 'receiving';
    const PHASE_VERIFYING_ARTIFACTS = 'verifying';
    const PHASE_RESTORING_FILES     = 'restoring_files';
    const PHASE_REPLAYING_DB        = 'replaying_db';
    const PHASE_SWAPPING_DB         = 'swapping_db';
    const PHASE_REWRITING_URLS      = 'rewriting_urls';
    const PHASE_POST_RESTORE_VERIFY = 'verify_site';

    /**
     * Default resume cap - a stalled task is re-entered at most this many
     * times before it goes terminal failed (never an infinite loop).
     */
    const DEFAULT_MAX_RESUMES = 6;

    /**
     * Budget for one bounded slice of work, in seconds. Kept under typical
     * shared-hosting proxy ceilings (60-120s).
     */
    const SLICE_BUDGET_SECONDS = 90;

    /**
     * Largest sub_state JSON stored inline in the column, in bytes. Bigger
     * cursors spill to a sidecar file so writes stay far below
     * max_allowed_packet.
     */
    const SUB_STATE_INLINE_MAX = 49152;

    /**
     * Database schema version for the task table.
     */
    const DB_VERSION = '1.0.0';

    /**
     * Option name holding the installed schema version.
     */
    const DB_VERSION_OPTION = 'mainwp_child_backup_task_db_version';

    /**
     * Singleton instance.
     *
     * @var MainWP_Child_Backup_Task|null
     */
    protected static $instance = null;

    /**
     * Get the singleton instance.
     *
     * @return MainWP_Child_Backup_Task
     */
    public static function instance() {
        if ( null === static::$instance ) {
            static::$instance = new self();
        }
        return static::$instance;
    }

    /**
     * Whether a phase is terminal.
     *
     * @param string $phase Phase name.
     *
     * @return bool True for completed/failed.
     */
    public static function is_terminal_phase( $phase ) {
        return in_array( $phase, array( self::PHASE_COMPLETED, self::PHASE_FAILED ), true );
    }

    /**
     * The next phase in the machine for a given backup type.
     *
     * A db backup has no file-archive phase; a files backup has no database
     * phase; a full backup runs every work phase. Upload and submit always
     * follow the work phases. Terminal and unknown phases have no successor.
     *
     * @param string $current Current phase.
     * @param string $type    Backup type (full/db/files).
     *
     * @return string|null Next phase, or null when there is none.
     */
    public static function next_phase( $current, $type ) {
        switch ( $type ) {
            case 'db':
                $order = array( self::PHASE_QUEUED, self::PHASE_DUMPING_DB, self::PHASE_UPLOADING, self::PHASE_SUBMITTING, self::PHASE_COMPLETED );
                break;
            case 'files':
                $order = array( self::PHASE_QUEUED, self::PHASE_ARCHIVING_FILES, self::PHASE_UPLOADING, self::PHASE_SUBMITTING, self::PHASE_COMPLETED );
                break;
            case 'incremental':
                // Every work phase a full backup runs: the database is
                // dumped fresh on each incremental run, so every snapshot
                // restores to its own point in time.
                $order = array( self::PHASE_QUEUED, self::PHASE_DUMPING_DB, self::PHASE_ARCHIVING_FILES, self::PHASE_UPLOADING, self::PHASE_SUBMITTING, self::PHASE_COMPLETED );
                break;
            case 'restore':
                $order = array( self::PHASE_QUEUED, self::PHASE_PULLING_ARTIFACTS, self::PHASE_RECEIVING_ARTIFACTS, self::PHASE_VERIFYING_ARTIFACTS, self::PHASE_RESTORING_FILES, self::PHASE_REPLAYING_DB, self::PHASE_SWAPPING_DB, self::PHASE_REWRITING_URLS, self::PHASE_POST_RESTORE_VERIFY, self::PHASE_COMPLETED );
                break;
            case 'restore_db':
                // No file payload: straight from artifact verification to
                // the database replay.
                $order = array( self::PHASE_QUEUED, self::PHASE_PULLING_ARTIFACTS, self::PHASE_RECEIVING_ARTIFACTS, self::PHASE_VERIFYING_ARTIFACTS, self::PHASE_REPLAYING_DB, self::PHASE_SWAPPING_DB, self::PHASE_REWRITING_URLS, self::PHASE_POST_RESTORE_VERIFY, self::PHASE_COMPLETED );
                break;
            case 'restore_files':
                // No database payload: files extract over the site root,
                // so the replay, swap, and URL-rewrite phases do not
                // exist for this type.
                $order = array( self::PHASE_QUEUED, self::PHASE_PULLING_ARTIFACTS, self::PHASE_RECEIVING_ARTIFACTS, self::PHASE_VERIFYING_ARTIFACTS, self::PHASE_RESTORING_FILES, self::PHASE_POST_RESTORE_VERIFY, self::PHASE_COMPLETED );
                break;
            default:
                $order = array( self::PHASE_QUEUED, self::PHASE_DUMPING_DB, self::PHASE_ARCHIVING_FILES, self::PHASE_UPLOADING, self::PHASE_SUBMITTING, self::PHASE_COMPLETED );
                break;
        }

        $index = array_search( $current, $order, true );
        if ( false === $index || ! isset( $order[ $index + 1 ] ) ) {
            return null;
        }

        return $order[ $index + 1 ];
    }

    /**
     * Get the full task table name.
     *
     * @return string Table name.
     */
    public function get_table_name() {
        global $wpdb;
        return $wpdb->prefix . 'mainwp_child_backup_task';
    }

    /**
     * Create or upgrade the task table via dbDelta.
     */
    public function install() {
        if ( self::DB_VERSION === get_option( self::DB_VERSION_OPTION ) ) {
            return;
        }

        global $wpdb;
        $charset_collate = $wpdb->get_charset_collate();
        $table           = $this->get_table_name();

        $sql = "CREATE TABLE {$table} (
            task_id varchar(64) NOT NULL,
            phase varchar(20) NOT NULL DEFAULT 'queued',
            type varchar(20) NOT NULL DEFAULT 'full',
            sub_state longtext,
            started_at datetime DEFAULT NULL,
            last_progress_at datetime DEFAULT NULL,
            max_wall_clock_at datetime DEFAULT NULL,
            resume_count int(11) NOT NULL DEFAULT 0,
            max_resumes int(11) NOT NULL DEFAULT 6,
            lease_owner varchar(64) DEFAULT NULL,
            lease_expires_at datetime DEFAULT NULL,
            version bigint(20) unsigned NOT NULL DEFAULT 0,
            PRIMARY KEY  (task_id),
            KEY phase (phase)
        ) {$charset_collate};";

        require_once ABSPATH . 'wp-admin/includes/upgrade.php'; // NOSONAR - WP compatible.
        dbDelta( $sql );

        MainWP_Helper::update_option( self::DB_VERSION_OPTION, self::DB_VERSION );
    }

    /**
     * Create a new backup task in the queued phase.
     *
     * The wall-clock deadline (max_resumes x slice budget, generously
     * padded) is stamped at creation so the Dashboard can fail a task that
     * sits in a non-terminal phase on a low-traffic child whose cron never
     * fires.
     *
     * @param string   $type             Task type (full/db/files/incremental/restore/restore_db).
     * @param array    $sub_state        Initial resume cursor.
     * @param int|null $max_resumes      Resume cap override (restore tasks
     *                                   span more slices than a backup).
     * @param int|null $deadline_seconds Wall-clock horizon override, for
     *                                   work that scales with payload size.
     *
     * @return string|false Task id, or false on insert failure.
     */
    public function create_task( $type = 'full', $sub_state = array(), $max_resumes = null, $deadline_seconds = null ) {
        global $wpdb;

        $this->install();

        $max_resumes = null === $max_resumes ? self::DEFAULT_MAX_RESUMES : (int) $max_resumes;
        $task_id     = 'task-' . time() . '-' . strtolower( wp_generate_password( 12, false ) );
        $deadline    = null === $deadline_seconds ? ( self::DEFAULT_MAX_RESUMES + 2 ) * self::SLICE_BUDGET_SECONDS * 4 : (int) $deadline_seconds;

        $result = $wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- dedicated spine table.
            $wpdb->prepare(
                'INSERT INTO ' . $this->get_table_name() . // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- table name from trusted helper.
                ' (task_id, phase, type, sub_state, started_at, last_progress_at, max_wall_clock_at, resume_count, max_resumes, version)
                 VALUES (%s, %s, %s, %s, NOW(), NOW(), DATE_ADD(NOW(), INTERVAL %d SECOND), 0, %d, 0)',
                $task_id,
                self::PHASE_QUEUED,
                $type,
                wp_json_encode( $sub_state ),
                $deadline,
                $max_resumes
            )
        );

        if ( 1 !== $result ) {
            return false;
        }

        // Arm the fallback watchdog so the task resumes even if the
        // Dashboard continuation dies mid-run.
        MainWP_Child_Backup_Watchdog::instance()->arm();

        return $task_id;
    }

    /**
     * Fetch a task row.
     *
     * @param string $task_id Task id.
     *
     * @return object|null Task row or null.
     */
    public function get_task( $task_id ) {
        global $wpdb;
        return $wpdb->get_row( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- dedicated spine table, always-fresh read required for CAS.
            $wpdb->prepare(
                'SELECT * FROM ' . $this->get_table_name() . ' WHERE task_id = %s', // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- table name from trusted helper.
                $task_id
            )
        );
    }

    /**
     * Acquire the task lease with one atomic conditional UPDATE.
     *
     * The expiry is set with DATE_ADD(NOW(), ...) - MySQL time - because the
     * expiry check also runs on MySQL time. One clock owns both sides, so
     * PHP/MySQL clock skew can neither shorten nor lengthen the TTL.
     *
     * @param string $task_id     Task id.
     * @param string $owner       Lease owner label (dashboard call or watchdog).
     * @param int    $version     Version the caller last observed.
     * @param int    $ttl_seconds Lease TTL in seconds (~2x one slice).
     *
     * @return bool True when this caller now holds the lease.
     */
    public function acquire_lease( $task_id, $owner, $version, $ttl_seconds = 180 ) {
        global $wpdb;
        $result = $wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- the atomic CAS gate; must hit the DB engine.
            $wpdb->prepare(
                'UPDATE ' . $this->get_table_name() . // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- table name from trusted helper.
                ' SET lease_owner = %s, lease_expires_at = DATE_ADD(NOW(), INTERVAL %d SECOND), version = version + 1
                 WHERE task_id = %s AND version = %d AND (lease_owner IS NULL OR lease_expires_at < NOW())',
                $owner,
                $ttl_seconds,
                $task_id,
                $version
            )
        );
        return 1 === $result;
    }

    /**
     * Release a held lease.
     *
     * @param string $task_id Task id.
     * @param string $owner   Owner releasing the lease.
     * @param int    $version Version the caller last observed.
     *
     * @return bool True when released.
     */
    public function release_lease( $task_id, $owner, $version ) {
        global $wpdb;
        $result = $wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- CAS write on the spine table.
            $wpdb->prepare(
                'UPDATE ' . $this->get_table_name() . // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- table name from trusted helper.
                ' SET lease_owner = NULL, lease_expires_at = NULL, version = version + 1
                 WHERE task_id = %s AND lease_owner = %s AND version = %d',
                $task_id,
                $owner,
                $version
            )
        );
        return 1 === $result;
    }

    /**
     * Checkpoint task state with a compare-and-swap on version.
     *
     * @param string $task_id Task id.
     * @param int    $version Version the caller last observed.
     * @param array  $fields  Allowed: phase, sub_state (array), resume_count.
     *
     * @return int|false The new version on success, false when the version was stale.
     */
    public function checkpoint( $task_id, $version, $fields ) {
        global $wpdb;

        $sets = array( 'last_progress_at = NOW()', 'version = version + 1' );
        $args = array();

        if ( isset( $fields['phase'] ) ) {
            $sets[] = 'phase = %s';
            $args[] = $fields['phase'];
        }
        if ( isset( $fields['sub_state'] ) ) {
            $sets[] = 'sub_state = %s';
            // A string is already serialized (possibly a sidecar pointer from
            // write_sub_state); arrays are encoded here for existing callers.
            $args[] = is_string( $fields['sub_state'] ) ? $fields['sub_state'] : wp_json_encode( $fields['sub_state'] );
        }
        if ( isset( $fields['resume_count'] ) ) {
            $sets[] = 'resume_count = %d';
            $args[] = (int) $fields['resume_count'];
        }

        $args[] = $task_id;
        $args[] = $version;

        $result = $wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- CAS write on the spine table.
            $wpdb->prepare( // phpcs:ignore WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- args passed as array match the placeholders.
                'UPDATE ' . $this->get_table_name() . ' SET ' . implode( ', ', $sets ) . ' WHERE task_id = %s AND version = %d', // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- table name from trusted helper, sets are literal fragments.
                $args
            )
        );

        return ( 1 === $result ) ? $version + 1 : false;
    }

    /**
     * Serialize a sub_state cursor for the column, spilling oversized
     * cursors to a sidecar file.
     *
     * The full cursor goes to <backup_dir>/task_substate-<task_id>.json via
     * temp-file-then-rename (readers never see a half-written file) and the
     * column gets only a small pointer.
     *
     * @param string $task_id    Task id.
     * @param array  $sub_state  Resume cursor.
     * @param string $backup_dir Scratch directory (the randomized backup dir).
     *
     * @return string|false Column value (inline JSON or pointer JSON), false on write failure.
     */
    public function write_sub_state( $task_id, $sub_state, $backup_dir ) {
        $json = wp_json_encode( $sub_state );
        if ( strlen( $json ) <= self::SUB_STATE_INLINE_MAX ) {
            return $json;
        }

        $sidecar = $this->get_sidecar_path( $task_id, $backup_dir );
        $temp    = $sidecar . '.tmp';

        if ( false === file_put_contents( $temp, $json ) || ! rename( $temp, $sidecar ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions -- atomic temp-then-rename requires native file APIs.
            if ( file_exists( $temp ) ) {
                unlink( $temp ); // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- scratch cleanup.
            }
            return false;
        }

        return wp_json_encode(
            array(
                '_sidecar' => true,
                'file'     => basename( $sidecar ),
            )
        );
    }

    /**
     * Read a task row's sub_state, resolving a sidecar pointer transparently.
     *
     * @param object $task       Task row.
     * @param string $backup_dir Scratch directory holding any sidecar.
     *
     * @return array|null Cursor array; null when it cannot be recovered (the
     *                    caller restarts cleanly rather than resuming from a
     *                    partial cursor).
     */
    public function read_sub_state( $task, $backup_dir ) {
        $decoded = json_decode( (string) $task->sub_state, true );
        if ( ! is_array( $decoded ) ) {
            return null;
        }

        if ( empty( $decoded['_sidecar'] ) ) {
            return $decoded;
        }

        $sidecar = $backup_dir . '/' . basename( isset( $decoded['file'] ) ? (string) $decoded['file'] : '' );
        if ( ! is_file( $sidecar ) ) {
            return null;
        }

        $full = json_decode( (string) file_get_contents( $sidecar ), true ); // phpcs:ignore WordPress.WP.AlternativeFunctions -- local scratch file.
        return is_array( $full ) ? $full : null;
    }

    /**
     * Remove a task's sidecar file. Quiet no-op when none exists; terminal
     * transitions call this so finished tasks leave no cursor litter.
     *
     * @param string $task_id    Task id.
     * @param string $backup_dir Scratch directory.
     */
    public function delete_sidecar( $task_id, $backup_dir ) {
        $sidecar = $this->get_sidecar_path( $task_id, $backup_dir );
        if ( is_file( $sidecar ) ) {
            unlink( $sidecar ); // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- scratch cleanup.
        }
    }

    /**
     * The per-task sidecar path inside the scratch directory.
     *
     * @param string $task_id    Task id.
     * @param string $backup_dir Scratch directory.
     *
     * @return string Sidecar file path.
     */
    protected function get_sidecar_path( $task_id, $backup_dir ) {
        return rtrim( $backup_dir, '/' ) . '/task_substate-' . sanitize_file_name( $task_id ) . '.json';
    }

    /**
     * Find non-terminal tasks whose progress stalled past the threshold and
     * whose lease is free or expired.
     *
     * @param int $threshold_seconds Stall threshold in seconds.
     *
     * @return object[] Stalled task rows.
     */
    public function find_stalled_tasks( $threshold_seconds ) {
        global $wpdb;
        $rows = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- dedicated spine table, always-fresh read.
            $wpdb->prepare(
                'SELECT * FROM ' . $this->get_table_name() . // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- table name from trusted helper.
                " WHERE phase NOT IN ('completed','failed')
                   AND last_progress_at < DATE_SUB(NOW(), INTERVAL %d SECOND)
                   AND (lease_owner IS NULL OR lease_expires_at < NOW())",
                $threshold_seconds
            )
        );
        return is_array( $rows ) ? $rows : array();
    }

    /**
     * Time of the last progress touch, for the write throttle.
     *
     * @var int Unix timestamp.
     */
    protected $last_touch = 0;

    /**
     * Refresh a task's liveness timestamp without bumping the version.
     *
     * Long engine slices call this between files/tables so stall detection
     * sees a live task. Throttled to one write per ~5s; never a CAS, so the
     * slice holder's version chain stays valid.
     *
     * @param string $task_id Task id.
     */
    public function touch_progress( $task_id ) {
        if ( time() - $this->last_touch < 5 ) {
            return;
        }
        $this->last_touch = time();

        global $wpdb;
        $wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- liveness write on the spine table.
            $wpdb->prepare(
                'UPDATE ' . $this->get_table_name() . ' SET last_progress_at = NOW() WHERE task_id = %s', // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- table name from trusted helper.
                $task_id
            )
        );
    }

    /**
     * Mark a task failed when it is past its wall-clock deadline.
     *
     * Called by the Dashboard-driven status path on every poll - this closes
     * the stuck-forever case on children whose WP-Cron never fires.
     *
     * @param string $task_id Task id.
     *
     * @return bool True when the task was marked failed by this call.
     */
    public function enforce_wall_clock_deadline( $task_id ) {
        global $wpdb;
        $result = $wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- conditional terminal write on the spine table.
            $wpdb->prepare(
                'UPDATE ' . $this->get_table_name() . // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- table name from trusted helper.
                " SET phase = 'failed', version = version + 1
                 WHERE task_id = %s AND phase NOT IN ('completed','failed') AND max_wall_clock_at < NOW()",
                $task_id
            )
        );
        return 1 === $result;
    }

    /**
     * The status array a Dashboard poll or run call consumes.
     *
     * On completion the artifact file name and size surface top-level; on
     * failure the recorded error message and code do. The resume cursor is
     * resolved through any sidecar so the Dashboard always sees the full
     * state.
     *
     * @param object      $task       Task row.
     * @param string|null $backup_dir Scratch directory, null for the default.
     *
     * @return array Status payload.
     */
    public function status_payload( $task, $backup_dir = null ) {
        if ( null === $backup_dir ) {
            $dirs       = MainWP_Helper::get_mainwp_dir( 'backup' );
            $backup_dir = $dirs[0];
        }

        $sub_state = $this->read_sub_state( $task, $backup_dir );

        $payload = array(
            'task_id'           => $task->task_id,
            'phase'             => $task->phase,
            'type'              => $task->type,
            'sub_state'         => $sub_state,
            'started_at'        => $task->started_at,
            'last_progress_at'  => $task->last_progress_at,
            'max_wall_clock_at' => $task->max_wall_clock_at,
            'resume_count'      => (int) $task->resume_count,
            'max_resumes'       => (int) $task->max_resumes,
        );

        if ( self::PHASE_COMPLETED === $task->phase && isset( $sub_state['artifact']['file'] ) ) {
            $payload['file'] = $sub_state['artifact']['file'];
            $payload['size'] = (int) $sub_state['artifact']['size'];
        }

        // Multi-artifact runs (incremental parts, chain-base manifests)
        // list every downloadable file with its role. Optional on the
        // wire: plain runs and older runners never record it.
        if ( self::PHASE_COMPLETED === $task->phase && ! empty( $sub_state['artifacts'] ) && is_array( $sub_state['artifacts'] ) ) {
            $payload['artifacts'] = $sub_state['artifacts'];
        }

        if ( self::PHASE_FAILED === $task->phase && isset( $sub_state['error']['message'] ) ) {
            $payload['error']      = $sub_state['error']['message'];
            $payload['error_code'] = isset( $sub_state['error']['code'] ) ? $sub_state['error']['code'] : '';
        }

        // Optional on the wire: older runners never recorded a snapshot, and
        // early phases may not have one yet.
        if ( ! empty( $sub_state['progress'] ) && is_array( $sub_state['progress'] ) ) {
            $payload['progress'] = $sub_state['progress'];
        }

        return $payload;
    }

    /**
     * Prune a terminal task: delete its artifact files and sidecar from
     * the backup directory, then its row. The dashboard calls this when
     * retention removes the backups a run produced. Running tasks are
     * refused, and a missing row counts as already pruned so the call is
     * idempotent.
     *
     * @param string $task_id    Task id.
     * @param string $backup_dir Backup directory holding the artifacts.
     *
     * @return string One of 'pruned', 'active', 'missing'.
     */
    public function prune_task( $task_id, $backup_dir ) {
        $task = $this->get_task( $task_id );
        if ( null === $task ) {
            return 'missing';
        }

        if ( ! self::is_terminal_phase( $task->phase ) ) {
            return 'active';
        }

        $sub_state = $this->read_sub_state( $task, $backup_dir );
        $files     = array();

        if ( is_array( $sub_state ) ) {
            if ( isset( $sub_state['artifact']['file'] ) ) {
                $files[] = (string) $sub_state['artifact']['file'];
            }
            if ( ! empty( $sub_state['artifacts'] ) && is_array( $sub_state['artifacts'] ) ) {
                foreach ( $sub_state['artifacts'] as $entry ) {
                    if ( ! empty( $entry['file'] ) ) {
                        $files[] = (string) $entry['file'];
                    }
                }
            }
        }

        foreach ( array_unique( $files ) as $file ) {
            $path = rtrim( $backup_dir, '/' ) . '/' . basename( $file );
            if ( is_file( $path ) ) {
                wp_delete_file( $path );
            }
        }

        $this->delete_sidecar( $task_id, $backup_dir );

        global $wpdb;
        $wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- dedicated spine table.
            $wpdb->prepare(
                'DELETE FROM ' . $this->get_table_name() . ' WHERE task_id = %s', // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- table name from trusted helper.
                $task_id
            )
        );

        return 'pruned';
    }

    /**
     * Sweep stale legacy run leftovers from the backup directory: .pid and
     * .done markers and old db dump fragments past the age threshold. No
     * task row tracks these, so age is the only signal. Archives are never
     * touched, whatever their age - artifact deletion is prune_task's job
     * and only ever by name.
     *
     * @param string $backup_dir  Backup directory.
     * @param int    $age_seconds Minimum age before a leftover is stale.
     */
    public function sweep_stale_markers( $backup_dir, $age_seconds = 10800 ) {
        $patterns = array( 'backup-*.pid', 'backup-*.done', 'dbBackup-*.sql*', 'manifest-staged-*.list' );
        $cutoff   = time() - $age_seconds;

        foreach ( $patterns as $pattern ) {
            $matches = glob( rtrim( $backup_dir, '/' ) . '/' . $pattern );
            if ( ! is_array( $matches ) ) {
                continue;
            }
            foreach ( $matches as $file ) {
                if ( is_file( $file ) && filemtime( $file ) < $cutoff ) {
                    wp_delete_file( $file );
                }
            }
        }
    }

    /**
     * Get all non-terminal tasks.
     *
     * @return object[] Task rows.
     */
    public function get_active_tasks() {
        global $wpdb;
        $rows = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- dedicated spine table, always-fresh read.
            'SELECT * FROM ' . $this->get_table_name() . " WHERE phase NOT IN ('completed','failed')" // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- table name from trusted helper, no user input.
        );
        return is_array( $rows ) ? $rows : array();
    }
}
