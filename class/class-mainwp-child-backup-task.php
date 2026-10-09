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
    const PHASE_SNAPSHOTTING_DB     = 'snapshot_db';
    const PHASE_REPLAYING_DB        = 'replaying_db';
    const PHASE_SWAPPING_DB         = 'swapping_db';
    const PHASE_FK_ROLLBACK         = 'fk_rollback';
    const PHASE_REWRITING_URLS      = 'rewriting_urls';
    const PHASE_POST_RESTORE_VERIFY = 'verify_site';

    // WooCommerce order preservation phases (restore / restore_db when
    // preserve_orders is set): capture the live customers and stock the kept
    // orders reference, then re-merge them after the database is live.
    const PHASE_PRESERVING_WOO = 'preserve_woo';
    const PHASE_MERGING_WOO    = 'merge_woo';

    // The snapshot_db and fk_rollback phases above, and the two WooCommerce
    // phases, are reached only by an explicit jump_phase() - snapshot_db and
    // fk_rollback on the foreign-key restore path, preserve_woo and merge_woo
    // when an operator preserves orders - never by the linear next_phase()
    // order, so a plain restore never enters any of them.

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
     * Wall-clock window granted at creation and refreshed on every real
     * progress checkpoint. A task is failed only when it makes no progress for
     * this long (the "cron never fires" stuck case), so a large backup that is
     * actively advancing is never killed for simply taking a while.
     */
    const WALL_CLOCK_WINDOW_SECONDS = ( self::DEFAULT_MAX_RESUMES + 2 ) * self::SLICE_BUDGET_SECONDS * 4;

    /**
     * Reclaim window for a task that is still queued - it has never advanced
     * its first slice, so resume_count is not a reliable staleness signal.
     * The dashboard's continue cron drives the first slice within ~5
     * minutes; 15 minutes leaves 3 cycles of margin before a never-started
     * task is treated as abandoned, well short of the full wall-clock window
     * granted to a task that has actually made progress.
     */
    const QUEUED_STALL_WINDOW_SECONDS = 15 * MINUTE_IN_SECONDS;

    /**
     * Fresh wall-clock budget granted to a foreign-key rollback when a replay
     * is rerouted to it at its deadline, so the recovery has time to finish.
     */
    const FK_ROLLBACK_DEADLINE_SECONDS = 3600;

    /**
     * Largest sub_state JSON stored inline in the column, in bytes. Bigger
     * cursors spill to a sidecar file so writes stay far below
     * max_allowed_packet.
     */
    const SUB_STATE_INLINE_MAX = 49152;

    /**
     * Database schema version for the task table.
     */
    const DB_VERSION = '1.2.0';

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
            active_slot tinyint unsigned DEFAULT NULL,
            restore_id varchar(64) DEFAULT NULL,
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
            UNIQUE KEY uniq_active_task (active_slot),
            UNIQUE KEY uniq_restore_id (restore_id),
            KEY phase (phase)
        ) {$charset_collate};";

        require_once ABSPATH . 'wp-admin/includes/upgrade.php'; // NOSONAR - WP compatible.
        $suppress = $wpdb->suppress_errors( true );
        try {
            dbDelta( $sql );
            if ( $this->repair_restore_id_key() && $this->repair_active_task_key() ) {
                MainWP_Helper::update_option( self::DB_VERSION_OPTION, self::DB_VERSION );
            }
        } finally {
            $wpdb->suppress_errors( $suppress );
        }
    }

    /**
     * The version needs readback because dbDelta can report an ALTER that failed.
     *
     * @return bool Whether the active slot is backfilled and uniquely indexed.
     */
    protected function repair_active_task_key() {
        global $wpdb;
        $table = $this->get_table_name();

        // phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- schema repair uses only the trusted spine table and prepared values.
        $column = $wpdb->get_var( "SHOW COLUMNS FROM {$table} LIKE 'active_slot'" );
        if ( '' !== $wpdb->last_error ) {
            return false;
        }
        if ( empty( $column ) ) {
            $wpdb->query( "ALTER TABLE {$table} ADD COLUMN active_slot tinyint unsigned DEFAULT NULL" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.SchemaChange -- repairs an incomplete upgrade.
            $column = $wpdb->get_var( "SHOW COLUMNS FROM {$table} LIKE 'active_slot'" );
            if ( empty( $column ) || '' !== $wpdb->last_error ) {
                return false;
            }
        }

        for ( $attempt = 0; $attempt < 2; ++$attempt ) {
            $filled = $wpdb->query( "UPDATE {$table} SET active_slot = CASE WHEN phase NOT IN ('completed','failed') THEN 1 ELSE NULL END" );
            $error  = $wpdb->last_error;
            $index  = $wpdb->get_results( "SHOW INDEX FROM {$table} WHERE Key_name = 'uniq_active_task'" );
            if ( '' !== $wpdb->last_error ) {
                return false;
            }
            if ( false !== $filled && empty( $index ) ) {
                $wpdb->query( "ALTER TABLE {$table} ADD UNIQUE KEY uniq_active_task (active_slot)" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.SchemaChange -- the database must enforce admission atomically.
                $error = $wpdb->last_error;
                $index = $wpdb->get_results( "SHOW INDEX FROM {$table} WHERE Key_name = 'uniq_active_task'" );
                if ( '' !== $wpdb->last_error ) {
                    return false;
                }
            }
            $valid_index = 1 === count( (array) $index ) && 0 === (int) $index[0]->Non_unique && 1 === (int) $index[0]->Seq_in_index && 'active_slot' === $index[0]->Column_name;
            if ( false !== $filled && $valid_index ) {
                return true;
            }
            if ( 0 !== $attempt || false === strpos( $error, 'Duplicate entry' ) ) {
                return false;
            }

            // dbDelta may have installed the key before the old rows were backfilled.
            $rows = $wpdb->get_results( "SELECT task_id, version FROM {$table} WHERE phase NOT IN ('completed','failed') ORDER BY started_at DESC, task_id DESC" );
            if ( '' !== $wpdb->last_error || count( (array) $rows ) < 2 ) {
                return false;
            }
            $sub_state = wp_json_encode(
                array(
                    'error' => array(
                        'code'    => MainWP_Backup_Error::ERR_ALREADY_RUNNING,
                        'message' => 'Task failed during active-task uniqueness repair; a newer task remains active.',
                    ),
                )
            );
            foreach ( array_slice( $rows, 1 ) as $row ) {
                $changed = $wpdb->query(
                    $wpdb->prepare(
                        "UPDATE {$table} SET phase = 'failed', active_slot = NULL, sub_state = %s, version = version + 1 WHERE task_id = %s AND version = %d AND phase NOT IN ('completed','failed')",
                        $sub_state,
                        $row->task_id,
                        (int) $row->version
                    )
                );
                if ( 1 !== $changed ) {
                    return false;
                }
            }
        }
        // phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        return false;
    }

    /**
     * A failed ALTER must not leave restore retries able to create another task.
     *
     * @return bool Whether restore identities are backfilled and uniquely indexed.
     */
    protected function repair_restore_id_key() {
        global $wpdb;
        $table = $this->get_table_name();

        // phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- trusted spine table and prepared values.
        $column = $wpdb->get_var( "SHOW COLUMNS FROM {$table} LIKE 'restore_id'" );
        if ( '' !== $wpdb->last_error ) {
            return false;
        }
        if ( empty( $column ) ) {
            $wpdb->query( "ALTER TABLE {$table} ADD COLUMN restore_id varchar(64) DEFAULT NULL" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.SchemaChange -- repairs an incomplete upgrade.
            $column = $wpdb->get_var( "SHOW COLUMNS FROM {$table} LIKE 'restore_id'" );
            if ( empty( $column ) || '' !== $wpdb->last_error ) {
                return false;
            }
        }

        $rows = $wpdb->get_results( "SELECT task_id, restore_id, sub_state FROM {$table}" );
        if ( '' !== $wpdb->last_error ) {
            return false;
        }
        $identities  = array();
        $scratch_dir = null;
        foreach ( $rows as $row ) {
            $state = json_decode( (string) $row->sub_state, true );
            if ( null === $row->restore_id && ! empty( $state['_sidecar'] ) ) {
                try {
                    // Resolved on first use and reused: get_mainwp_dir() stats the
                    // tree and can write its deny files on every call.
                    if ( null === $scratch_dir ) {
                        $dirs        = MainWP_Helper::get_mainwp_dir( 'backup', false );
                        $scratch_dir = $dirs[0];
                    }
                    $state = $this->read_sub_state( $row, $scratch_dir );
                } catch ( \Throwable $e ) {
                    // An unavailable cursor must not block schema upgrades or backups.
                    $state = null;
                }
            }
            $restore_id = $row->restore_id ?? ( $state['params']['restore_id'] ?? null );
            if ( is_string( $restore_id ) && '' !== $restore_id && strlen( $restore_id ) <= 64 ) {
                $identities[ $restore_id ][] = $row;
            }
        }
        foreach ( $identities as $restore_id => $matches ) {
            // Choosing among duplicate legacy IDs could acknowledge the wrong restore.
            if ( 1 !== count( $matches ) || null !== $matches[0]->restore_id ) {
                continue;
            }
            if ( false === $wpdb->query( $wpdb->prepare( "UPDATE {$table} SET restore_id = %s WHERE task_id = %s AND restore_id IS NULL", $restore_id, $matches[0]->task_id ) ) ) {
                return false;
            }
        }

        $index = $wpdb->get_results( "SHOW INDEX FROM {$table} WHERE Key_name = 'uniq_restore_id'" );
        if ( '' !== $wpdb->last_error ) {
            return false;
        }
        if ( empty( $index ) ) {
            $wpdb->query( "ALTER TABLE {$table} ADD UNIQUE KEY uniq_restore_id (restore_id)" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.SchemaChange -- replay identity must remain unique after completion.
            $index = $wpdb->get_results( "SHOW INDEX FROM {$table} WHERE Key_name = 'uniq_restore_id'" );
        }
        // phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        return '' === $wpdb->last_error && 1 === count( (array) $index ) && 0 === (int) $index[0]->Non_unique && 'restore_id' === $index[0]->Column_name && empty( $index[0]->Sub_part );
    }

    /**
     * Create a new backup task in the queued phase.
     *
     * The wall-clock deadline (max_resumes x slice budget, generously
     * padded) is stamped at creation so the Dashboard can fail a task that
     * sits in a non-terminal phase on a low-traffic child whose cron never
     * fires.
     *
     * @param string      $type             Task type (full/db/files/incremental/restore/restore_db).
     * @param array       $sub_state        Initial resume cursor.
     * @param int|null    $max_resumes      Resume cap override (restore tasks
     *                                   span more slices than a backup).
     * @param int|null    $deadline_seconds Wall-clock horizon override, for
     *                                   work that scales with payload size.
     * @param string|null $task_id          Task id reserved by the engine mutex, or null to generate one.
     * @param bool        $require_idle     Refuse creation while any non-terminal task exists.
     *
     * @return string|false|null Task id on success, false on query failure, or null for an occupied slot or restore identity (see wpdb::last_error).
     * @throws MainWP_Exception When the unique active slot is already occupied.
     */
    public function create_task( $type = 'full', $sub_state = array(), $max_resumes = null, $deadline_seconds = null, $task_id = null, $require_idle = false ) {
        global $wpdb;

        $this->install();
        if ( self::DB_VERSION !== get_option( self::DB_VERSION_OPTION ) ) {
            return false;
        }

        $max_resumes = null === $max_resumes ? self::DEFAULT_MAX_RESUMES : (int) $max_resumes;
        $task_id     = null === $task_id ? 'task-' . time() . '-' . strtolower( wp_generate_password( 12, false ) ) : $task_id;
        $deadline    = null === $deadline_seconds ? self::WALL_CLOCK_WINDOW_SECONDS : (int) $deadline_seconds;

        $sql = 'INSERT INTO ' . $this->get_table_name() .
            ' (task_id, phase, type, sub_state, started_at, last_progress_at, max_wall_clock_at, resume_count, max_resumes, version, active_slot, restore_id)';
        if ( $require_idle ) {
            $sql .= ' SELECT %s, %s, %s, %s, NOW(), NOW(), DATE_ADD(NOW(), INTERVAL %d SECOND), 0, %d, 0, 1, NULLIF(%s, \'\')
                FROM DUAL WHERE NOT EXISTS ( SELECT 1 FROM ' . $this->get_table_name() . " WHERE phase NOT IN ('completed','failed') LIMIT 1 )";
        } else {
            $sql .= ' VALUES (%s, %s, %s, %s, NOW(), NOW(), DATE_ADD(NOW(), INTERVAL %d SECOND), 0, %d, 0, 1, NULLIF(%s, \'\'))';
        }

        $suppress = $wpdb->suppress_errors( true );
        try {
            $result = $wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- dedicated spine table.
                $wpdb->prepare(
                    $sql, // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- fixed SQL and trusted table name; values are prepared below.
                    $task_id,
                    self::PHASE_QUEUED,
                    $type,
                    wp_json_encode( $sub_state ),
                    $deadline,
                    $max_resumes,
                    $sub_state['params']['restore_id'] ?? ''
                )
            );
            if ( false === $result && false !== strpos( $wpdb->last_error, 'Duplicate entry' ) && false !== strpos( $wpdb->last_error, 'uniq_active_task' ) ) {
                throw new MainWP_Exception( esc_html( MainWP_Backup_Error::message( MainWP_Backup_Error::ERR_ALREADY_RUNNING ) ), null, MainWP_Backup_Error::ERR_ALREADY_RUNNING );
            }
            if ( false === $result && false !== strpos( $wpdb->last_error, 'Duplicate entry' ) && false !== strpos( $wpdb->last_error, 'uniq_restore_id' ) ) {
                return null;
            }
        } finally {
            $wpdb->suppress_errors( $suppress );
        }

        if ( 1 !== $result ) {
            return $require_idle && 0 === $result ? null : false;
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
     * Restore identity survives terminal phases so a lost response cannot replay it.
     *
     * @param string $restore_id Restore id.
     * @return object|null Task row or null.
     */
    public function get_task_by_restore_id( $restore_id ) {
        global $wpdb;
        return $wpdb->get_row( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- retries need the current task phase.
            $wpdb->prepare(
                'SELECT * FROM ' . $this->get_table_name() . ' WHERE restore_id = %s', // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- trusted table name.
                $restore_id
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
     * Renew only the invocation that still owns a live lease, preserving its CAS version.
     *
     * @param string $task_id     Task id.
     * @param string $owner       Invocation token.
     * @param int    $ttl_seconds Lease TTL in seconds.
     * @return bool Whether the lease remains owned by this invocation.
     */
    public function renew_lease( $task_id, $owner, $ttl_seconds = 180 ) {
        global $wpdb;
        if ( '' === $owner || $ttl_seconds <= 0 ) {
            return false;
        }
        $result = $wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- ownership must be checked atomically.
            $wpdb->prepare(
                'UPDATE ' . $this->get_table_name() . // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- trusted table name.
                " SET lease_expires_at = DATE_ADD(NOW(), INTERVAL %d SECOND)
                 WHERE task_id = %s AND lease_owner = %s AND lease_expires_at >= NOW() AND phase NOT IN ('completed','failed')",
                $ttl_seconds,
                $task_id,
                $owner
            )
        );
        if ( 0 !== $result ) {
            return 1 === $result;
        }
        // A heartbeat in the acquisition second can leave the expiry unchanged.
        return '1' === $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- distinguish an unchanged lease from ownership loss.
            $wpdb->prepare(
                'SELECT 1 FROM ' . $this->get_table_name() . " WHERE task_id = %s AND lease_owner = %s AND lease_expires_at >= NOW() AND phase NOT IN ('completed','failed')", // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- trusted table name.
                $task_id,
                $owner
            )
        );
    }

    /**
     * Transfer a live lease for an old runner during a partial upgrade only.
     *
     * No current caller uses this method. Keep it for requests that loaded
     * the old runner before plugin files were replaced.
     *
     * @param string $task_id    Task id.
     * @param string $from_owner Owner currently holding the lease.
     * @param string $to_owner   Owner receiving the lease.
     * @param int    $version    Version the caller last observed.
     *
     * @return bool True when transferred.
     */
    public function handoff_lease( $task_id, $from_owner, $to_owner, $version ) {
        global $wpdb;
        $result = $wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- CAS write on the spine table.
            $wpdb->prepare(
                'UPDATE ' . $this->get_table_name() . // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- table name from trusted helper.
                ' SET lease_owner = %s, version = version + 1
                 WHERE task_id = %s AND lease_owner = %s AND version = %d AND lease_expires_at >= NOW()',
                $to_owner,
                $task_id,
                $from_owner,
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
     * @param string      $task_id       Task id.
     * @param int         $version       Version the caller last observed.
     * @param array       $fields        Allowed: phase, sub_state (array), resume_count.
     * @param bool        $release_mutex False while the terminal caller still has shared-file cleanup.
     * @param string|null $backup_dir    Cursor sidecar directory, null for the default.
     *
     * @return int|false The new version on success, false when the version was stale.
     */
    public function checkpoint( $task_id, $version, $fields, $release_mutex = true, $backup_dir = null ) {
        global $wpdb;

        $terminal_restore = false;
        if ( isset( $fields['phase'] ) && self::is_terminal_phase( $fields['phase'] ) ) {
            $task = $this->get_task( $task_id );
            if ( null !== $task && self::is_restore_type( $task->type ) ) {
                if ( isset( $fields['sub_state'] ) ) {
                    $task->sub_state = is_string( $fields['sub_state'] ) ? $fields['sub_state'] : wp_json_encode( $fields['sub_state'] );
                }
                if ( null === $backup_dir ) {
                    $dirs       = MainWP_Helper::get_mainwp_dir( 'backup' );
                    $backup_dir = $dirs[0];
                }
                $sub_state = $this->read_sub_state( $task, $backup_dir );
                if ( null === $sub_state ) {
                    return false;
                }
                if ( isset( $sub_state['identity_preserve'] ) ) {
                    $sub_state['identity_preserved'] = true;
                }
                unset( $sub_state['params']['pull_manifest'], $sub_state['identity_preserve'] );
                // Terminal rows outlive the credentials needed to resume; keep the outcome inline when sidecars are removed.
                $fields['sub_state'] = $sub_state;
                $terminal_restore    = true;
            }
        }

        $sets = array( 'last_progress_at = NOW()', 'version = version + 1' );
        $args = array();

        if ( isset( $fields['phase'] ) ) {
            $sets[] = 'phase = %s';
            $args[] = $fields['phase'];
            $sets[] = 'active_slot = ' . ( self::is_terminal_phase( $fields['phase'] ) ? 'NULL' : '1' );
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

        if ( 1 === $result && $terminal_restore ) {
            $this->delete_sidecar( $task_id, $backup_dir );
        }

        if ( 1 === $result && $release_mutex && isset( $fields['phase'] ) && self::is_terminal_phase( $fields['phase'] ) ) {
            $task = $this->get_task( $task_id );
            if ( null !== $task && ! self::is_restore_type( $task->type ) ) {
                MainWP_Child_Backup_Mutex::release( $task_id );
            }
        }

        return ( 1 === $result ) ? $version + 1 : false;
    }

    /**
     * Serialize a sub_state cursor for the column, spilling oversized
     * cursors to a sidecar file.
     *
     * The full cursor goes to
     * <backup_dir>/task_substate-<task_id>-v<version>.json via
     * temp-file-then-rename (readers never see a half-written file) and the
     * column gets only a small pointer. The version stamp keeps a stale slice
     * from overwriting the cursor body a newer slice already committed.
     *
     * Superseded lower-version sidecars are NOT pruned here: a deletion before
     * the caller's compare-and-swap commits could drop a file the row still
     * references (a winner advances its version, then loses the next CAS to a
     * lease steal, leaving the row pointed at the just-deleted file). They are
     * cheap scratch and delete_sidecar() sweeps every version at terminal.
     *
     * @param string $task_id    Task id.
     * @param array  $sub_state  Resume cursor.
     * @param string $backup_dir Scratch directory (the randomized backup dir).
     * @param int    $version    Version the writing slice owns.
     *
     * @return string|false Column value (inline JSON or pointer JSON), false on write failure.
     */
    public function write_sub_state( $task_id, $sub_state, $backup_dir, $version ) {
        $json = wp_json_encode( $sub_state );
        if ( strlen( $json ) <= self::SUB_STATE_INLINE_MAX ) {
            return $json;
        }

        $sidecar = $this->get_sidecar_path( $task_id, $backup_dir, $version );
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
     * Remove a task's sidecar files. Quiet no-op when none exist; terminal
     * transitions call this so finished tasks leave no cursor litter. Sweeps
     * every version stamp for the task plus the legacy unversioned name a task
     * that was mid-flight across the upgrade may still hold. The strict regex
     * keeps a prefix-colliding task id from deleting another task's sidecar.
     *
     * @param string $task_id    Task id.
     * @param string $backup_dir Scratch directory.
     */
    public function delete_sidecar( $task_id, $backup_dir ) {
        $this->delete_superseded_sidecars( $task_id, $backup_dir, '' );
    }

    /**
     * Remove a task's sidecar files except the one its row still points to.
     *
     * @param string $task_id    Task id.
     * @param string $backup_dir Scratch directory.
     * @param string $keep       Sidecar file name to keep, or '' to remove all.
     */
    public function delete_superseded_sidecars( $task_id, $backup_dir, $keep ) {
        $name    = sanitize_file_name( $task_id );
        $dir     = rtrim( $backup_dir, '/' ) . '/';
        $pattern = '/^task_substate-' . preg_quote( $name, '/' ) . '-v(\d+)\.json$/';
        foreach ( (array) glob( $dir . 'task_substate-' . $name . '-v*.json' ) as $file ) {
            if ( preg_match( $pattern, basename( $file ) ) && basename( $file ) !== $keep ) {
                unlink( $file ); // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- scratch cleanup.
            }
        }

        $legacy = $dir . 'task_substate-' . $name . '.json';
        if ( is_file( $legacy ) ) {
            unlink( $legacy ); // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- scratch cleanup.
        }
    }

    /**
     * The per-task sidecar path inside the scratch directory.
     *
     * The owned version is stamped into the filename so a slice that outran
     * its lease (which owns a strictly lower version than the slice that stole
     * it) writes to a different file and can never overwrite the live cursor.
     *
     * @param string $task_id    Task id.
     * @param string $backup_dir Scratch directory.
     * @param int    $version    Version the writing slice owns.
     *
     * @return string Sidecar file path.
     */
    protected function get_sidecar_path( $task_id, $backup_dir, $version ) {
        return rtrim( $backup_dir, '/' ) . '/task_substate-' . sanitize_file_name( $task_id ) . '-v' . (int) $version . '.json';
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
     * Whether a task type is one of the restore family. Restore tasks enter
     * maintenance and create tmp tables, so a forced-terminal restore needs
     * runner cleanup; backup tasks do not.
     *
     * @param string $type Task type.
     *
     * @return bool True for restore, restore_db, or restore_files.
     */
    public static function is_restore_type( $type ) {
        return in_array( $type, array( 'restore', 'restore_db', 'restore_files' ), true );
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

        $task = $this->get_task( $task_id );
        if ( null === $task || self::is_terminal_phase( $task->phase ) ) {
            return false;
        }

        // A foreign-key restore that times out mid-replay is rerouted to roll
        // the half-applied database back instead of failing outright. The
        // foreign-key condition lives in the JSON sub_state, so it is resolved
        // here in PHP and committed with a deadline + phase guarded write:
        // MySQL time still owns the deadline, the version bump makes a
        // still-live runner back off, and the rollback gets a fresh deadline.
        //
        // The reroute waits for the lease to be free (the same gate the runner
        // acquires through), so the rollback that follows can never overlap a
        // replay still writing the live tables, and a replay that has just
        // finished - still holding the lease through its phase transition -
        // is never rerouted into rolling back a restore that already applied.
        // A mid-replay fk restore is therefore never forced to a bare failure
        // that would skip the rollback: it reroutes when the lease frees or a
        // later poll retries.
        if ( self::PHASE_REPLAYING_DB === $task->phase && $this->is_fk_replay_in_progress( $task ) ) {
            $rerouted = $wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- conditional reroute write on the spine table.
                $wpdb->prepare(
                    'UPDATE ' . $this->get_table_name() . // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- table name from trusted helper.
                    ' SET phase = %s, max_wall_clock_at = DATE_ADD(NOW(), INTERVAL %d SECOND), version = version + 1
                     WHERE task_id = %s AND phase = %s AND max_wall_clock_at < NOW()
                       AND ( lease_owner IS NULL OR lease_expires_at < NOW() )',
                    self::PHASE_FK_ROLLBACK,
                    self::FK_ROLLBACK_DEADLINE_SECONDS,
                    $task_id,
                    self::PHASE_REPLAYING_DB
                )
            );
            return 1 === $rerouted;
        }

        // Every other timed-out task fails. A rollback that itself timed out
        // keeps maintenance on (nothing exits it) and reports the
        // rollback-failed code so the cause is clear; everything else reports
        // the wall-clock code. The error is recorded in the same guarded write
        // so the Dashboard poll sees a reason, not a bare "failed".
        $code = ( self::PHASE_FK_ROLLBACK === $task->phase )
            ? MainWP_Backup_Error::ERR_RESTORE_ROLLBACK_FAILED
            : MainWP_Backup_Error::ERR_WALL_CLOCK;

        $sub_state = wp_json_encode(
            array(
                'error' => array(
                    'message' => MainWP_Backup_Error::message( $code ),
                    'code'    => $code,
                ),
            )
        );

        // A still-queued task is failed once it has sat untouched past the
        // shorter stall window, even while its full wall-clock deadline is
        // still in the future - see QUEUED_STALL_WINDOW_SECONDS.
        $result = $wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- conditional terminal write on the spine table.
            $wpdb->prepare(
                'UPDATE ' . $this->get_table_name() . // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- table name from trusted helper.
                " SET phase = 'failed', active_slot = NULL, sub_state = %s, version = version + 1
                 WHERE task_id = %s AND phase NOT IN ('completed','failed')
                   AND ( lease_owner IS NULL OR lease_expires_at < NOW() )
                   AND ( max_wall_clock_at < NOW()
                         OR ( phase = %s AND started_at < DATE_SUB( NOW(), INTERVAL %d SECOND ) ) )",
                $sub_state,
                $task_id,
                self::PHASE_QUEUED,
                self::QUEUED_STALL_WINDOW_SECONDS
            )
        );

        // Only when this call actually failed the task: a forced-terminal
        // restore left tmp tables, the .maintenance flag, and scratch behind
        // (the runner never ran its catch). The fail write above overwrote the
        // cursor, so cleanup reads restore_id from the pre-fail $task (its
        // sidecar survives). FK rollback keeps maintenance on - cleanup skips it.
        if ( 1 === $result && self::is_restore_type( $task->type ) ) {
            MainWP_Child_Restore_Task_Runner::instance()->cleanup_terminal_task( $task );
        }

        // A forced-terminal backup never runs the runner's terminal sweep.
        // Delete the partial artifacts its cursor names - the fail write
        // above overwrote the row, so cleanup reads the pre-fail $task (its
        // sidecar survives until the delete below) - plus the walk-run
        // scratch and the sidecar this path used to leave behind.
        if ( 1 === $result && ! self::is_restore_type( $task->type ) ) {
            try {
                $dirs = MainWP_Helper::get_mainwp_dir( 'backup' );
                MainWP_Child_Backup_Task_Runner::instance()->cleanup_failed_task_artifacts( $task_id, $this->read_sub_state( $task, $dirs[0] ), $dirs[0] );
                $this->delete_sidecar( $task_id, $dirs[0] );
            } finally {
                MainWP_Child_Backup_Mutex::release( $task_id );
            }
        }

        return 1 === $result;
    }

    /**
     * Whether a task is a foreign-key restore that has taken its aside snapshot
     * and is now in the in-place replay, where a timeout must roll the
     * half-applied database back rather than fail outright.
     *
     * @param object $task Task row.
     *
     * @return bool
     */
    protected function is_fk_replay_in_progress( $task ) {
        $dirs      = MainWP_Helper::get_mainwp_dir( 'backup' );
        $sub_state = $this->read_sub_state( $task, $dirs[0] );
        if ( ! is_array( $sub_state ) || empty( $sub_state['db_replay'] ) || ! is_array( $sub_state['db_replay'] ) ) {
            return false;
        }

        $db = $sub_state['db_replay'];
        return ! empty( $db['fk_path'] ) && ! empty( $db['snapshot_done'] );
    }

    /**
     * Reroute a stalled, in-flight foreign-key replay to its rollback instead
     * of failing it outright, so a half-applied live database is rolled back to
     * the aside snapshot rather than left broken.
     *
     * This mirrors the deadline-forced reroute in enforce_wall_clock_deadline()
     * but is gated by the caller's owned version (the watchdog's compare-and-
     * swap), not by the wall clock: the watchdog acts on the resume cap, which
     * can be reached while the deadline is still in the future, so requiring an
     * expired deadline here would skip the rollback. It resets resume_count and
     * grants a fresh deadline so the bounded rollback has budget, and only wins
     * on a free lease so it can never overlap a replay still writing the live
     * tables.
     *
     * @param object $task    Stalled task row.
     * @param int    $version Owned row version (compare-and-swap guard).
     *
     * @return bool True when the task was rerouted to rollback.
     */
    public function reroute_fk_replay_to_rollback( $task, $version ) {
        global $wpdb;

        if ( self::PHASE_REPLAYING_DB !== $task->phase || ! $this->is_fk_replay_in_progress( $task ) ) {
            return false;
        }

        $result = $wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- compare-and-swap reroute write on the spine table.
            $wpdb->prepare(
                'UPDATE ' . $this->get_table_name() . // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- table name from trusted helper.
                ' SET phase = %s, max_wall_clock_at = DATE_ADD(NOW(), INTERVAL %d SECOND), resume_count = 0, last_progress_at = NOW(), version = version + 1
                 WHERE task_id = %s AND version = %d AND phase = %s
                   AND ( lease_owner IS NULL OR lease_expires_at < NOW() )',
                self::PHASE_FK_ROLLBACK,
                self::FK_ROLLBACK_DEADLINE_SECONDS,
                $task->task_id,
                (int) $version,
                self::PHASE_REPLAYING_DB
            )
        );
        return 1 === $result;
    }

    /**
     * Push a running task's wall-clock deadline out by a fixed amount.
     *
     * The foreign-key restore path adds an aside snapshot and a possible
     * rollback replay on top of the in-place replay, so it needs more
     * wall-clock than the replay alone. The extension is monotonic and rebased
     * on NOW() when the old deadline has already passed, so a deadline-forced
     * reroute to rollback still gets real time to finish. It touches only the
     * deadline column - not phase, version, or sub_state - so it does not
     * disturb the CAS state machine and needs no version guard.
     *
     * @param string $task_id       Task id.
     * @param int    $extra_seconds Seconds to add to the deadline.
     *
     * @return bool True when a row was updated.
     */
    public function extend_wall_clock( $task_id, $extra_seconds ) {
        global $wpdb;

        $extra = (int) $extra_seconds;
        if ( $extra <= 0 ) {
            return false;
        }

        $result = $wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- deadline-only write on the spine table.
            $wpdb->prepare(
                'UPDATE ' . $this->get_table_name() . // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- table name from trusted helper.
                " SET max_wall_clock_at = DATE_ADD(GREATEST(max_wall_clock_at, NOW()), INTERVAL %d SECOND)
                 WHERE task_id = %s AND phase NOT IN ('completed','failed')",
                $extra,
                $task_id
            )
        );
        return 1 === $result;
    }

    /**
     * Slide the wall-clock deadline forward on real progress: ensure the
     * horizon is at least NOW + window, never shortening it (a far-future
     * horizon from a larger upfront grant is left alone). Called on every
     * progress checkpoint so an actively-advancing task is failed only when it
     * makes no progress for a whole window, while a truly stuck task (no
     * checkpoints) still goes terminal. Deadline-only, no version churn.
     *
     * @param string $task_id        Task id.
     * @param int    $window_seconds Sliding window length in seconds.
     *
     * @return bool True when the row was updated.
     */
    public function refresh_wall_clock( $task_id, $window_seconds ) {
        global $wpdb;

        $window = (int) $window_seconds;
        if ( $window <= 0 ) {
            return false;
        }

        $result = $wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- deadline-only write on the spine table.
            $wpdb->prepare(
                'UPDATE ' . $this->get_table_name() . // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- table name from trusted helper.
                " SET max_wall_clock_at = GREATEST(max_wall_clock_at, DATE_ADD(NOW(), INTERVAL %d SECOND))
                 WHERE task_id = %s AND phase NOT IN ('completed','failed')",
                $window,
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

        if ( self::is_restore_type( $task->type ) ) {
            // Dashboard diagnostics retain responses, so only polling fields may leave the restore cursor.
            $projected           = array_intersect_key( (array) $sub_state, array_flip( array( 'verification', 'progress', 'error' ) ) );
            $projected['params'] = array();
            if ( ! empty( $sub_state['params']['pull_manifest'] ) ) {
                $projected['params']['pull_manifest'] = true;
            }
            if ( isset( $sub_state['identity_preserve'] ) || ! empty( $sub_state['identity_preserved'] ) ) {
                $projected['identity_preserved'] = true;
            }
            $sub_state = $projected;
        }

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

        // Woo order-storage facts captured at dump time. Optional on the
        // wire: files-only runs and older runners never record them. The
        // dashboard stamps them onto the snapshot so a preserve-orders
        // restore can be refused at initiate time.
        if ( self::PHASE_COMPLETED === $task->phase && ! empty( $sub_state['woo_meta'] ) && is_array( $sub_state['woo_meta'] ) ) {
            $payload['woo'] = $sub_state['woo_meta'];
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
     * @param string                               $task_id        Task id.
     * @param string                               $backup_dir     Backup directory holding the artifacts.
     * @param MainWP_Backup_Db_Restore_Engine|null $restore_engine Engine used to drop a restore's aside tables before the row goes; null skips that step.
     *
     * @return string One of 'pruned', 'active', 'missing'.
     */
    public function prune_task( $task_id, $backup_dir, $restore_engine = null ) {
        $task = $this->get_task( $task_id );
        if ( null === $task ) {
            return 'missing';
        }

        if ( ! self::is_terminal_phase( $task->phase ) ) {
            return 'active';
        }

        $sub_state = $this->read_sub_state( $task, $backup_dir );
        // A file another live task still needs (a rollback about to adopt
        // this safety backup) cannot go yet. The row is the only record of
        // it, so keep the row and its sidecar and let the Dashboard retry.
        if ( ! empty( $this->delete_artifact_files( $sub_state, $backup_dir, $task_id ) ) ) {
            return 'active';
        }

        $this->delete_sidecar( $task_id, $backup_dir );

        // Deleting a restore row removes the only pointer back to its aside
        // tables; drop them now so a prune can never orphan them. Best-effort -
        // the row must be deleted regardless of a housekeeping failure.
        if ( null !== $restore_engine && self::is_restore_type( $task->type )
            && is_array( $sub_state ) && ! empty( $sub_state['params']['restore_id'] ) && is_string( $sub_state['params']['restore_id'] ) ) {
            try {
                $restore_engine->prune_aside_tables( array( (string) $sub_state['params']['restore_id'] ) );
            } catch ( \Throwable $e ) {
                MainWP_Helper::log_debug( 'prune aside-table drop skipped: ' . $e->getMessage() );
            }
        }

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
     * Delete every backup-directory file a task left: what its cursor names
     * (the produced artifact(s), mid-flight db dump fragments, partial archive
     * and closed incremental parts, a spilled parent manifest never consumed)
     * and, given its id, the incremental parts carrying its task token, which
     * a part still open when the run stopped is only findable by. Deletion is
     * only ever through delete_task_files(), so the chain files and anything
     * another task row owns are never touched. Quiet no-op on an unreadable
     * cursor apart from the token parts.
     *
     * @param array|null $sub_state  Task cursor.
     * @param string     $backup_dir Backup directory holding the artifacts.
     * @param string     $task_id    The cursor's own task id, or '' when unknown.
     *
     * @return string[] Names kept because a live task still needs them.
     */
    public function delete_artifact_files( $sub_state, $backup_dir, $task_id = '' ) {
        return $this->delete_task_files( $this->task_file_names( $task_id, $sub_state, $backup_dir ), $backup_dir, $task_id );
    }

    /**
     * Delete files from the backup folder by name, on behalf of one task. The
     * single gate every cleanup by name goes through: the incremental chain
     * files are never deleted, and neither is a file another task row still
     * owns.
     *
     * @param string[] $names      File names.
     * @param string   $backup_dir Backup directory.
     * @param string   $task_id    Task the files belong to; its own row is not an owner.
     *
     * @return string[] Names kept because a live task still needs them.
     */
    public function delete_task_files( $names, $backup_dir, $task_id = '' ) {
        return $this->delete_unclaimed_files( $names, $this->artifact_claims( $backup_dir, $task_id ), $backup_dir );
    }

    /**
     * Every file name a task may have left in the backup folder.
     *
     * @param string     $task_id    Task id, or '' to skip the token parts.
     * @param array|null $sub_state  Task cursor.
     * @param string     $backup_dir Backup directory.
     *
     * @return string[]
     */
    public function task_file_names( $task_id, $sub_state, $backup_dir ) {
        $names = is_array( $sub_state ) ? self::cursor_file_names( $sub_state ) : array();
        // Incremental zip parts fold the task token into their name - the one
        // archive shape safely globbable per task.
        $token = preg_replace( '/[^A-Za-z0-9]/', '', (string) $task_id );
        if ( '' !== $token ) {
            foreach ( (array) glob( rtrim( $backup_dir, '/' ) . '/*.' . $token . '.part*.zip' ) as $part ) {
                $names[] = basename( $part );
            }
        }
        return $names;
    }

    /**
     * Basenames of every file a cursor names: artifacts, mid-flight dump
     * fragments, the partial archive, closed incremental parts and the
     * spilled parent manifest.
     *
     * @param array $sub_state Task cursor.
     *
     * @return string[]
     */
    private static function cursor_file_names( $sub_state ) {
        $names = self::artifact_names( $sub_state );
        if ( ! empty( $sub_state['db_files'] ) && is_array( $sub_state['db_files'] ) ) {
            foreach ( $sub_state['db_files'] as $db_file ) {
                if ( is_string( $db_file ) && '' !== $db_file ) {
                    $names[] = basename( $db_file );
                }
            }
        }
        if ( ! empty( $sub_state['archive_file'] ) && is_string( $sub_state['archive_file'] ) ) {
            $names[] = basename( $sub_state['archive_file'] );
        }
        if ( ! empty( $sub_state['completed_parts'] ) && is_array( $sub_state['completed_parts'] ) ) {
            foreach ( $sub_state['completed_parts'] as $part ) {
                if ( is_array( $part ) && ! empty( $part['file'] ) && is_string( $part['file'] ) ) {
                    $names[] = basename( $part['file'] );
                }
            }
        }
        if ( ! empty( $sub_state['params']['parent_manifest_file'] ) && is_string( $sub_state['params']['parent_manifest_file'] ) ) {
            $names[] = basename( $sub_state['params']['parent_manifest_file'] );
        }
        return $names;
    }

    /**
     * Sweep stale run leftovers from the backup directory. The sweep holds
     * the native backup mutex from reading which task owns what until its
     * last delete, so no backup or restore can be created in between with a
     * claim the sweep never saw; when the mutex is live elsewhere, or a legacy
     * pid is fresh, the whole sweep waits for the next prune or create. A
     * caller that already holds the mutex passes its owner token. An expired
     * or malformed holder does not defer the sweep. Equality at expiry
     * remains live to match acquire()'s strictly-earlier-than-now takeover
     * test.
     *
     * Leftovers no task row tracks (.pid and .done markers, db dump fragments,
     * staged manifests) go once they are past the age threshold. A completed
     * task's artifacts are never treated as leftovers, whatever their age,
     * even when a DB archive matches the dump pattern: they wait for the
     * Dashboard to download (delete_backup) or prune them. The files a failed
     * task still names (a cancel that could not take the mutex) go now, with
     * any sidecar the failed row no longer points to. Those deletions are by
     * name only, and never touch the incremental chain files.
     *
     * @param string      $backup_dir   Backup directory.
     * @param int         $age_seconds  Minimum age before a leftover is stale.
     * @param string|null $caller_owner Caller's mutex owner token, or null when not held.
     */
    public function sweep_stale_markers( $backup_dir, $age_seconds = 10800, $caller_owner = null ) {
        if ( MainWP_Backup::is_pid_lock_fresh( $backup_dir ) ) {
            return;
        }
        if ( null !== $caller_owner ) {
            $holder = MainWP_Child_Backup_Mutex::holder();
            if ( null !== $holder && $holder['expires_at'] >= time() && $holder['owner'] !== $caller_owner ) {
                return;
            }
            $this->sweep_while_fenced( $backup_dir, $age_seconds );
            return;
        }

        $owner = 'sweep-' . strtolower( wp_generate_password( 12, false ) );
        if ( true !== MainWP_Child_Backup_Mutex::acquire( 'native', $owner, 60 ) ) {
            return;
        }
        try {
            $this->sweep_while_fenced( $backup_dir, $age_seconds );
        } finally {
            MainWP_Child_Backup_Mutex::release( $owner );
        }
    }

    /**
     * The sweep body; the caller holds the native backup mutex.
     *
     * @param string $backup_dir  Backup directory.
     * @param int    $age_seconds Minimum age before a leftover is stale.
     */
    private function sweep_while_fenced( $backup_dir, $age_seconds ) {
        $patterns = array( 'backup-*.pid', 'backup-*.done', 'dbBackup-*.sql*', 'manifest-staged-*.list' );
        $cutoff   = time() - $age_seconds;

        // A long dump (>3h on a big site) leaves fragments older than the age
        // cutoff while its task is still running, and age alone cannot tell
        // those apart from a dead run's litter. The claims protect every file
        // an active task's cursor still depends on, and every artifact a
        // completed task still owes the Dashboard.
        $claims = $this->artifact_claims( $backup_dir );
        $this->delete_unclaimed_files( $claims['doomed'], $claims, $backup_dir );
        foreach ( $claims['sidecars'] as $failed_task_id => $keep ) {
            $this->delete_superseded_sidecars( (string) $failed_task_id, $backup_dir, $keep );
        }

        $safe_names    = $claims['names'];
        $safe_prefixes = $claims['prefixes'];

        foreach ( $patterns as $pattern ) {
            $matches = glob( rtrim( $backup_dir, '/' ) . '/' . $pattern );
            if ( ! is_array( $matches ) ) {
                continue;
            }
            foreach ( $matches as $file ) {
                $base = basename( $file );
                if ( isset( $safe_names[ $base ] ) ) {
                    continue;
                }
                foreach ( $safe_prefixes as $prefix ) {
                    if ( '' !== $prefix && 0 === strpos( $base, $prefix ) ) {
                        continue 2;
                    }
                }
                if ( is_file( $file ) && filemtime( $file ) < $cutoff ) {
                    wp_delete_file( $file );
                    clearstatcache( true, $file );
                    if ( ! file_exists( $file ) && preg_match( '/^manifest-staged-([a-z0-9]{16})\.list\z/', $base, $token ) ) {
                        delete_option( MainWP_Child_Backup_Manifest_Chunk_Receiver::OPTION_PREFIX . $token[1] );
                    }
                }
            }
        }
    }

    /**
     * Files and dump prefixes the stale sweep must never delete: everything an
     * active (non-terminal) task's cursor still depends on.
     *
     * Dump fragments are keyed by the task's dump prefix, not by recorded name:
     * a tier-2 dump writes fragments across many slices and only records their
     * names in db_files once the whole dump finishes, so a fragment from a dump
     * still in progress is not yet named anywhere but shares the task's stable
     * db_prefix. Explicitly named cursor files (a finished dump's fragments, an
     * unconsumed parent manifest) are matched by basename.
     *
     * @param string $backup_dir Backup directory (resolves sidecar cursors).
     *
     * @return array { names: array<string,true> basename set, prefixes: string[] basename prefixes }
     */
    private function active_task_sweep_guard( $backup_dir ) {
        $names    = array();
        $prefixes = array();

        foreach ( $this->get_active_tasks() as $task ) {
            $sub_state = $this->read_sub_state( $task, $backup_dir );
            if ( ! is_array( $sub_state ) ) {
                continue;
            }

            if ( ! empty( $sub_state['db_prefix'] ) && is_string( $sub_state['db_prefix'] ) ) {
                $prefixes[] = basename( $sub_state['db_prefix'] );
            }
            if ( ! empty( $sub_state['db_files'] ) && is_array( $sub_state['db_files'] ) ) {
                foreach ( $sub_state['db_files'] as $db_file ) {
                    if ( is_string( $db_file ) && '' !== $db_file ) {
                        $names[ basename( $db_file ) ] = true;
                    }
                }
            }
            if ( ! empty( $sub_state['params']['parent_manifest_file'] ) && is_string( $sub_state['params']['parent_manifest_file'] ) ) {
                $names[ basename( $sub_state['params']['parent_manifest_file'] ) ] = true;
            }
            if ( ! empty( $sub_state['archive_file'] ) && is_string( $sub_state['archive_file'] ) ) {
                $names[ basename( $sub_state['archive_file'] ) ] = true;
            }
            foreach ( self::artifact_names( $sub_state ) as $name ) {
                $names[ $name ] = true;
            }
            // A rollback restore adopts a safety backup from this folder by
            // name, so the archive it is about to copy must not go mid-restore.
            if ( ! empty( $sub_state['params']['artifacts'] ) && is_array( $sub_state['params']['artifacts'] ) ) {
                foreach ( $sub_state['params']['artifacts'] as $entry ) {
                    if ( is_array( $entry ) && ! empty( $entry['file'] ) && is_string( $entry['file'] ) ) {
                        $names[ basename( $entry['file'] ) ] = true;
                    }
                }
            }
        }

        return array(
            'names'    => $names,
            'prefixes' => array_values( array_unique( $prefixes ) ),
        );
    }

    /**
     * Whether a file in the backup folder belongs to the incremental chain
     * rather than to one run. Every manifest-emitting and incremental run
     * names the same fixed files, so deleting one by a run's name would remove
     * the base the next incremental diffs against, or another run's diff.
     * parent-files.list is the inline-pushed parent copy: a running
     * incremental writes it and starts its diff before its cursor names it,
     * so no claim can protect it. Completion deletes its own copy directly,
     * and the next inline run overwrites a leftover.
     *
     * @param string $name File name.
     *
     * @return bool
     */
    public static function is_chain_file( $name ) {
        return in_array(
            basename( (string) $name ),
            array(
                MainWP_Child_Backup_File_Walker::FILES_LIST,
                MainWP_Child_Backup_File_Walker::PATHS_CACHE,
                MainWP_Child_Backup_File_Walker::FILES_LIST . '.writer.json',
                MainWP_Child_Backup_Incremental_Diff::TOMBSTONES_LIST,
                MainWP_Child_Backup_Incremental_Diff::CHANGES_LIST,
                'parent-files.list',
            ),
            true
        );
    }

    /**
     * Basenames of the artifacts a cursor records.
     *
     * @param array $sub_state Task cursor.
     *
     * @return string[]
     */
    private static function artifact_names( $sub_state ) {
        $names = array();
        if ( ! empty( $sub_state['artifact']['file'] ) && is_string( $sub_state['artifact']['file'] ) ) {
            $names[] = basename( $sub_state['artifact']['file'] );
        }
        if ( ! empty( $sub_state['artifacts'] ) && is_array( $sub_state['artifacts'] ) ) {
            foreach ( $sub_state['artifacts'] as $entry ) {
                if ( is_array( $entry ) && ! empty( $entry['file'] ) && is_string( $entry['file'] ) ) {
                    $names[] = basename( $entry['file'] );
                }
            }
        }
        return $names;
    }

    /**
     * Which backup-folder files task rows still own, and which files nothing
     * will collect any more.
     *
     * Owned: everything an active task depends on ("live"), and the artifacts
     * of every completed task, which the Dashboard removes itself by
     * downloading them (delete_backup) or pruning the task. Doomed: every file
     * a failed task still names. A completed cursor also keeps the name of its
     * spilled parent manifest, but completion already deleted that file, so
     * the name is not a claim. Failed rows also report which sidecar they
     * still point to, so the sweep can reclaim the rest.
     *
     * @param string $backup_dir      Backup directory.
     * @param string $exclude_task_id Task whose own row is not an owner (the one being cleaned).
     *
     * @return array { names: array<string,true>, live: array<string,true>, prefixes: string[], doomed: string[], sidecars: array<string,string> }
     */
    private function artifact_claims( $backup_dir, $exclude_task_id = '' ) {
        global $wpdb;

        $claims             = $this->active_task_sweep_guard( $backup_dir );
        $claims['live']     = $claims['names'];
        $claims['doomed']   = array();
        $claims['sidecars'] = array();

        $rows = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- dedicated spine table, always-fresh read.
            'SELECT task_id, phase, sub_state FROM ' . $this->get_table_name() . // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- table name from trusted helper, no user input.
            " WHERE phase IN ('completed','failed') AND type NOT IN ('restore','restore_db','restore_files')"
        );
        foreach ( (array) $rows as $row ) {
            if ( $row->task_id === $exclude_task_id ) {
                continue;
            }
            $sub_state = $this->read_sub_state( $row, $backup_dir );
            if ( self::PHASE_COMPLETED === $row->phase ) {
                foreach ( is_array( $sub_state ) ? self::artifact_names( $sub_state ) : array() as $name ) {
                    $claims['names'][ $name ] = true;
                }
                continue;
            }
            $claims['doomed'] = array_merge( $claims['doomed'], $this->task_file_names( $row->task_id, $sub_state, $backup_dir ) );

            $stored = json_decode( (string) $row->sub_state, true );
            $keep   = is_array( $stored ) && ! empty( $stored['_sidecar'] ) && isset( $stored['file'] ) ? basename( (string) $stored['file'] ) : '';

            $claims['sidecars'][ $row->task_id ] = $keep;
        }

        return $claims;
    }

    /**
     * Delete files by name from the backup folder, skipping the chain files
     * and anything the claims protect.
     *
     * @param string[] $names      File names.
     * @param array    $claims     Claims from artifact_claims().
     * @param string   $backup_dir Backup directory.
     *
     * @return string[] Existing files kept because a live task still needs them.
     */
    private function delete_unclaimed_files( $names, $claims, $backup_dir ) {
        $dir  = rtrim( $backup_dir, '/' ) . '/';
        $kept = array();
        foreach ( array_unique( $names ) as $name ) {
            $name = basename( (string) $name );
            if ( '' === $name || self::is_chain_file( $name ) || ! is_file( $dir . $name ) ) {
                continue;
            }
            $live = isset( $claims['live'][ $name ] );
            foreach ( $claims['prefixes'] as $prefix ) {
                if ( '' !== $prefix && 0 === strpos( $name, $prefix ) ) {
                    $live = true;
                    break;
                }
            }
            if ( $live ) {
                $kept[] = $name;
                continue;
            }
            if ( ! isset( $claims['names'][ $name ] ) ) {
                wp_delete_file( $dir . $name );
            }
        }
        return $kept;
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

    /**
     * Terminal restore workers can still hold a lease while writing temp files.
     *
     * @return object[] Restore rows whose temp files must be preserved.
     */
    public function get_live_restore_tasks() {
        global $wpdb;
        $rows = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- temp reclamation needs current lease state.
            'SELECT * FROM ' . $this->get_table_name() . " WHERE type IN ('restore','restore_db','restore_files') AND (phase NOT IN ('completed','failed') OR lease_expires_at > NOW())" // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- table name from trusted helper, no user input.
        );
        return is_array( $rows ) ? $rows : array();
    }

    /**
     * Drive every active task already past its wall-clock deadline terminal.
     *
     * The single-backup guard refuses a new backup while any non-terminal row
     * exists. On a low/no-traffic child the watchdog cron may never fire to
     * clear an interrupted task, so without this a new backup request would be
     * blocked forever behind the orphaned row. Reclaiming each deadline-expired
     * task here lets the next backup request self-heal with no cron dependency;
     * the work reuses enforce_wall_clock_deadline(), so it only ever clears a
     * task whose deadline has actually passed - a legitimately running backup,
     * still inside its window, is left untouched.
     *
     * @param string|null $backup_dir Cursor sidecar directory, null for the default.
     *
     * @return int Number of tasks driven terminal by this call.
     */
    public function reclaim_expired_tasks( $backup_dir = null ) {
        global $wpdb;

        $reclaimed = 0;
        foreach ( $this->get_active_tasks() as $task ) {
            if ( $this->enforce_wall_clock_deadline( $task->task_id ) ) {
                ++$reclaimed;
            }
        }

        // Older builds retained resume credentials after completion; preserve the rows for restore-id reuse.
        $rows = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- terminal restore cursor cleanup.
            'SELECT * FROM ' . $this->get_table_name() . " WHERE type IN ('restore','restore_db','restore_files') AND phase IN ('completed','failed')" // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- table name from trusted helper, no user input.
        );
        if ( null === $backup_dir ) {
            $dirs       = MainWP_Helper::get_mainwp_dir( 'backup' );
            $backup_dir = $dirs[0];
        }
        foreach ( (array) $rows as $task ) {
            $sub_state = $this->read_sub_state( $task, $backup_dir );
            $stored    = json_decode( $task->sub_state, true );
            if ( is_array( $sub_state ) && ( ! empty( $stored['_sidecar'] ) || array_key_exists( 'identity_preserve', $sub_state )
                || ( isset( $sub_state['params'] ) && is_array( $sub_state['params'] ) && array_key_exists( 'pull_manifest', $sub_state['params'] ) ) ) ) {
                $this->checkpoint(
                    $task->task_id,
                    (int) $task->version,
                    array(
                        'phase'     => $task->phase,
                        'sub_state' => $sub_state,
                    ),
                    true,
                    $backup_dir
                );
            } elseif ( is_array( $sub_state ) ) {
                // Already-scrubbed inline rows may still have older secret-bearing sidecars.
                $this->delete_sidecar( $task->task_id, $backup_dir );
            }
        }
        return $reclaimed;
    }

    /**
     * Collect the restore ids of every restore-type task row, terminal or not,
     * so a caller can drop the aside (`old<id>_`) and staging (`tmp<id>_`)
     * tables those restores left behind.
     *
     * Rows whose sub_state cannot be decoded (a deleted sidecar) carry no
     * readable id and are skipped - a missing id is always safe to ignore.
     * No LIMIT: restore rows are few, and the caller's sweep clears the
     * backlog each run.
     *
     * @param string $backup_dir Backup directory, for sidecar resolution.
     *
     * @return string[] Restore ids.
     */
    public function get_restore_ids( $backup_dir ) {
        global $wpdb;
        $rows = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- dedicated spine table.
            'SELECT task_id, type, sub_state FROM ' . $this->get_table_name() . " WHERE type IN ('restore','restore_db','restore_files')" // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- table name from trusted helper, no user input.
        );
        $ids  = array();
        foreach ( (array) $rows as $row ) {
            $sub_state = $this->read_sub_state( $row, $backup_dir );
            if ( is_array( $sub_state ) && ! empty( $sub_state['params']['restore_id'] ) && is_string( $sub_state['params']['restore_id'] ) ) {
                $ids[] = (string) $sub_state['params']['restore_id'];
            }
        }
        return $ids;
    }
}
