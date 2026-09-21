<?php
/**
 * MainWP Child Maintenance.
 *
 * MainWP Maintenance extension handler.
 * Extension URL: https://mainwp.com/extension/maintenance/
 *
 * @package MainWP\Child
 */

namespace MainWP\Child;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

// phpcs:disable WordPress.WP.AlternativeFunctions -- Required to achieve desired results, pull request solutions appreciated.

/**
 * Class MainWP_Child_Maintenance
 *
 * MainWP Maintenance extension handler.
 */
class MainWP_Child_Maintenance {

    /** Durable bounded v2 operation records. */
    const ABILITIES_V2_OPERATIONS_OPTION = 'mainwp_child_maintenance_v2_operations';

    /** Maximum retained v2 operations. */
    const ABILITIES_V2_MAX_OPERATIONS = 100;

    /** Atomic v2 mutation lock. */
    const ABILITIES_V2_LOCK_OPTION = 'mainwp_child_maintenance_v2_lock';

    /** Revision rows removed per delete statement. */
    const ABILITIES_V2_REVISION_DELETE_BATCH = 500;

    /** Revision parents examined per sweep page. */
    const ABILITIES_V2_REVISION_PARENT_PAGE = 200;

    /** Seconds one revision sweep may spend deleting. */
    const ABILITIES_V2_REVISION_SWEEP_BUDGET = 120;

    /** Seconds a stopped sweep still needs to settle its record and release the lock. */
    const ABILITIES_V2_REVISION_SWEEP_MARGIN = 10;

    /**
     * Current v2 mutation-lock owner.
     *
     * @var string
     */
    private $abilities_v2_lock_owner = '';

    /**
     * Instant this request has to stop destructive work at, once something pinned it.
     *
     * @var int|null
     */
    private $abilities_v2_deadline = null;

    /**
     * Public static variable to hold the single instance of the class.
     *
     * @var mixed Default null
     */
    protected static $instance = null;

    /**
     * Method get_class_name()
     *
     * Get class name.
     *
     * @return string __CLASS__ Class name.
     */
    public static function get_class_name() {
        return __CLASS__;
    }

    /**
     * MainWP_Child_Maintenance constructor.
     *
     * Run any time class is called.
     */
    public function __construct() {
    }

    /**
     * Method get_instance()
     *
     * Create a public static instance.
     *
     * @return mixed Class instance.
     */
    public static function get_instance() {
        if ( null === static::$instance ) {
            static::$instance = new self();
        }
        return static::$instance;
    }

    /**
     * Method maintenance_site()
     *
     * Fire off Child Site maintenance action and get feedback.
     *
     * @uses \MainWP\Child\MainWP_Child_Maintenance::maintenance_action() Triggers action to perform, save_settings, enable_alert or clear_settings.
     * @uses \MainWP\Child\MainWP_Child_Maintenance::maintenance_db() Child site database maintenance.
     * @uses \MainWP\Child\MainWP_Helper::write() Write response data to be sent to the MainWP Dashboard.
     */
    public function maintenance_site() {
        // phpcs:disable WordPress.Security.NonceVerification
        if ( isset( $_POST['action'] ) ) {
            $action = MainWP_System::instance()->validate_params( 'action' );
            if ( 'abilities_v2' === $action ) {
                // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Bounded JSON is decoded and closed-schema validated below.
                $raw_request = isset( $_POST['request'] ) && is_string( $_POST['request'] ) ? wp_unslash( $_POST['request'] ) : '';
                $request     = 4096 >= strlen( $raw_request ) ? json_decode( $raw_request, true ) : null;
                MainWP_Helper::write( $this->abilities_v2( $request ) );
                return;
            }
            $this->maintenance_action( $action ); // exit.
        }

        $maint_options = isset( $_POST['options'] ) ? wp_unslash( $_POST['options'] ) : false; //phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized

        if ( ! is_array( $maint_options ) ) {
            MainWP_Helper::write( array( 'status' => 'FAIL' ) ); // exit.
        }

        $max_revisions = isset( $_POST['revisions'] ) ? intval( wp_unslash( $_POST['revisions'] ) ) : 0;
        // phpcs:enable

        $information = $this->maintenance_db( $maint_options, $max_revisions );

        MainWP_Helper::write( $information );
    }

    /**
     * Negotiate the additive Maintenance abilities protocol.
     *
     * Domain operations remain unavailable until their closed adapters and
     * postcondition tests are implemented.
     *
     * @param mixed $request Decoded request object.
     * @return array<string,mixed> Closed protocol response.
     */
    public function abilities_v2( $request ) {
        $operation = is_array( $request ) && isset( $request['operation'] ) && is_string( $request['operation'] ) ? $request['operation'] : 'unknown';
        if ( ! is_array( $request ) || ! $this->abilities_v2_exact_keys( $request, array( 'protocol', 'operation', 'payload' ) ) || '2' !== $request['protocol'] || ! is_array( $request['payload'] ) ) {
            return $this->abilities_v2_error( 'unknown', 'invalid_request' );
        }

        if ( 'capabilities' === $operation ) {
            // Capabilities is supported; a payload on it is a malformed request, not an unknown operation.
            if ( array() !== $request['payload'] ) {
                return $this->abilities_v2_error( $operation, 'invalid_request' );
            }
            return array(
                'protocol'           => '2',
                'operation'          => 'capabilities',
                'ok'                 => true,
                'operations'         => array( 'ability_maintenance_preview_v2', 'ability_maintenance_execute_v2', 'ability_maintenance_operation_v2' ),
                'mutation_supported' => true,
            );
        }

        if ( 'ability_maintenance_preview_v2' === $operation ) {
            return $this->abilities_v2_preview( $request['payload'] );
        }
        if ( 'ability_maintenance_execute_v2' === $operation ) {
            // The payload is checked before the lock so a request that could never run is answered
            // with invalid_request instead of the lock_busy of a run it was never eligible to start.
            if ( false === $this->abilities_v2_execute_actions( $request['payload'] ) ) {
                return $this->abilities_v2_error( $operation, 'invalid_request' );
            }
            if ( ! $this->abilities_v2_begin_mutation() ) {
                return $this->abilities_v2_error( $operation, 'lock_busy' );
            }
            $result   = $this->abilities_v2_execute( $request['payload'] );
            $released = $this->abilities_v2_end_mutation();
            return $this->abilities_v2_with_lock_release( $result, $released );
        }
        if ( 'ability_maintenance_operation_v2' === $operation ) {
            return $this->abilities_v2_operation( $request['payload'] );
        }

        return $this->abilities_v2_error( $operation, 'unsupported_operation' );
    }

    /**
     * Return a read-only bounded maintenance impact preview.
     *
     * @param array $payload Closed preview payload.
     * @return array<string,mixed> Closed preview response.
     */
    private function abilities_v2_preview( $payload ) {
        $operation = 'ability_maintenance_preview_v2';
        if ( ! $this->abilities_v2_exact_keys( $payload, array( 'actions', 'revision_retention' ) ) || ! is_array( $payload['actions'] ) || ! is_int( $payload['revision_retention'] ) || 0 > $payload['revision_retention'] || 1000 < $payload['revision_retention'] ) {
            return $this->abilities_v2_error( $operation, 'invalid_request' );
        }

        $catalog = array( 'revisions', 'autodraft', 'trashpost', 'spam', 'pending', 'trashcomment', 'tags', 'categories', 'optimize', 'transients_expired', 'transients_all' );
        $count   = count( $payload['actions'] );
        if ( 1 > $count || 11 < $count || array_keys( $payload['actions'] ) !== range( 0, $count - 1 ) ) {
            return $this->abilities_v2_error( $operation, 'invalid_request' );
        }
        $selected = array();
        foreach ( $payload['actions'] as $action ) {
            if ( ! is_string( $action ) || ! in_array( $action, $catalog, true ) || isset( $selected[ $action ] ) ) {
                return $this->abilities_v2_error( $operation, 'invalid_request' );
            }
            $selected[ $action ] = true;
        }
        if ( isset( $selected['transients_expired'], $selected['transients_all'] ) ) {
            return $this->abilities_v2_error( $operation, 'invalid_request' );
        }

        $impacts = array();
        foreach ( $catalog as $action ) {
            if ( ! isset( $selected[ $action ] ) ) {
                continue;
            }
            $impact = $this->abilities_v2_preview_impact( $action, $payload['revision_retention'] );
            if ( ! is_array( $impact ) || ! $this->abilities_v2_exact_keys( $impact, array( 'would_affect', 'capability' ) ) || ! is_int( $impact['would_affect'] ) || 0 > $impact['would_affect'] || ! is_bool( $impact['capability'] ) ) {
                return $this->abilities_v2_error( $operation, 'invalid_snapshot' );
            }
            $impacts[] = array(
                'action'       => $action,
                'would_affect' => min( 1000000, $impact['would_affect'] ),
                'risk'         => $this->abilities_v2_action_risk( $action, $payload['revision_retention'] ),
                'capability'   => $impact['capability'],
            );
        }

        $observed_at = gmdate( 'Y-m-d\TH:i:s\Z' );
        return array(
            'protocol'          => '2',
            'operation'         => $operation,
            'ok'                => true,
            'impacts'           => $impacts,
            'snapshot_revision' => hash( 'sha256', wp_json_encode( array( $payload['revision_retention'], $impacts ) ) ),
            'observed_at'       => $observed_at,
        );
    }

    /**
     * Calculate one read-only maintenance impact.
     *
     * @param string $action             Canonical action.
     * @param int    $revision_retention Revisions retained.
     * @return array<string,int|bool>|false
     */
    protected function abilities_v2_preview_impact( $action, $revision_retention ) { // phpcs:ignore Generic.Metrics.CyclomaticComplexity -- Closed action switch is intentional.
        global $wpdb;

        if ( in_array( $action, array( 'transients_expired', 'transients_all' ), true ) && function_exists( 'wp_using_ext_object_cache' ) && wp_using_ext_object_cache() ) {
            return array(
                'would_affect' => 0,
                'capability'   => false,
            );
        }

        $sql = '';
        if ( 'revisions' === $action ) {
            if ( 0 === $revision_retention ) {
                $sql = "SELECT COUNT(*) FROM $wpdb->posts WHERE post_type = 'revision'";
            } else {
                $sql = $wpdb->prepare( "SELECT COALESCE(SUM(revision_count - %d), 0) FROM (SELECT COUNT(*) revision_count FROM $wpdb->posts WHERE post_type = 'revision' GROUP BY post_parent HAVING COUNT(*) > %d) maintenance_revision_counts", $revision_retention, $revision_retention );
            }
        } elseif ( 'autodraft' === $action ) {
            $sql = "SELECT COUNT(*) FROM $wpdb->posts WHERE post_status = 'auto-draft'";
        } elseif ( 'trashpost' === $action ) {
            $sql = "SELECT COUNT(*) FROM $wpdb->posts WHERE post_status = 'trash'";
        } elseif ( 'spam' === $action ) {
            $sql = "SELECT COUNT(*) FROM $wpdb->comments WHERE comment_approved = 'spam'";
        } elseif ( 'pending' === $action ) {
            $sql = "SELECT COUNT(*) FROM $wpdb->comments WHERE comment_approved = '0'";
        } elseif ( 'trashcomment' === $action ) {
            $sql = "SELECT COUNT(*) FROM $wpdb->comments WHERE comment_approved = 'trash'";
        }
        if ( '' !== $sql ) {
            return $this->abilities_v2_preview_sql_count( $sql );
        }

        if ( 'tags' === $action || 'categories' === $action ) {
            $taxonomy = 'tags' === $action ? 'post_tag' : 'category';
            $terms    = get_terms(
                array(
                    'taxonomy'   => $taxonomy,
                    'hide_empty' => false,
                )
            );
            if ( is_wp_error( $terms ) || ! is_array( $terms ) ) {
                return false;
            }
            $default_category = (int) get_option( 'default_category', 0 );
            $would_affect     = 0;
            foreach ( $terms as $term ) {
                if ( ! is_object( $term ) || ! isset( $term->term_id, $term->count ) ) {
                    return false;
                }
                if ( 0 === (int) $term->count && ( 'category' !== $taxonomy || $default_category !== (int) $term->term_id ) ) {
                    ++$would_affect;
                }
            }
            return array(
                'would_affect' => $would_affect,
                'capability'   => true,
            );
        }

        if ( 'optimize' === $action ) {
            $wpdb->last_error = '';
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Read-only preview of current-blog tables.
            $tables = $wpdb->get_results( 'SHOW TABLE STATUS FROM `' . esc_sql( DB_NAME ) . '`', ARRAY_A );
            if ( '' !== $wpdb->last_error || ! is_array( $tables ) ) {
                return false;
            }
            $would_affect = 0;
            foreach ( $tables as $table ) {
                if ( ! is_array( $table ) || ! isset( $table['Name'] ) || ! is_string( $table['Name'] ) ) {
                    return false;
                }
                if ( 0 === strpos( $table['Name'], $wpdb->prefix ) ) {
                    ++$would_affect;
                }
            }
            return array(
                'would_affect' => $would_affect,
                'capability'   => true,
            );
        }

        if ( 'transients_expired' === $action ) {
            return $this->abilities_v2_preview_transients( true );
        }
        if ( 'transients_all' === $action ) {
            return $this->abilities_v2_preview_transients( false );
        }

        return false;
    }

    /**
     * Return a stable action risk class.
     *
     * @param string $action             Canonical action.
     * @param int    $revision_retention Revisions retained.
     * @return string
     */
    private function abilities_v2_action_risk( $action, $revision_retention ) {
        if ( ( 'revisions' === $action && 0 === $revision_retention ) || in_array( $action, array( 'pending', 'tags', 'categories', 'optimize', 'transients_all' ), true ) ) {
            return 'elevated';
        }
        return 'standard';
    }

    /**
     * Run a checked scalar count query.
     *
     * @param string $sql Read-only count query.
     * @return array<string,int|bool>|false
     */
    private function abilities_v2_preview_sql_count( $sql ) {
        global $wpdb;
        $wpdb->last_error = '';
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- Query is assembled only from fixed statements above.
        $value = $wpdb->get_var( $sql );
        if ( '' !== $wpdb->last_error || ! is_numeric( $value ) || 0 > (int) $value ) {
            return false;
        }
        return array(
            'would_affect' => (int) $value,
            'capability'   => true,
        );
    }

    /**
     * Count unique transient names without deleting cache data.
     *
     * @param bool $expired_only Whether only expired transients are counted.
     * @return array<string,int|bool>|false
     */
    private function abilities_v2_preview_transients( $expired_only ) {
        global $wpdb;
        $now              = time();
        $transient_prefix = $expired_only ? '_transient_timeout_' : '_transient_';
        $site_prefix      = $expired_only ? '_site_transient_timeout_' : '_site_transient_';
        $wpdb->last_error = '';
        // phpcs:disable WordPress.DB.PreparedSQL.NotPrepared -- Placeholder-bearing fixed query structures are prepared in each branch before execution.
        $sql = "SELECT option_name FROM $wpdb->options WHERE option_name LIKE %s";
        if ( $expired_only ) {
            $sql .= ' AND option_value < %d';
            $sql  = $wpdb->prepare( $sql, $wpdb->esc_like( $transient_prefix ) . '%', $now );
        } else {
            $sql = $wpdb->prepare( $sql, $wpdb->esc_like( $transient_prefix ) . '%' );
        }
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Read-only preview of transient keys.
        $names = $wpdb->get_col( $sql );
        if ( '' !== $wpdb->last_error || ! is_array( $names ) ) {
            return false;
        }

        $wpdb->last_error = '';
        $column           = is_multisite() ? 'meta_key' : 'option_name';
        $table            = is_multisite() ? $wpdb->sitemeta : $wpdb->options;
        $value_column     = is_multisite() ? 'meta_value' : 'option_value';
        $site_sql         = "SELECT $column FROM $table WHERE $column LIKE %s";
        if ( $expired_only ) {
            $site_sql .= " AND $value_column < %d";
            $site_sql  = $wpdb->prepare( $site_sql, $wpdb->esc_like( $site_prefix ) . '%', $now );
        } else {
            $site_sql = $wpdb->prepare( $site_sql, $wpdb->esc_like( $site_prefix ) . '%' );
        }
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table and column names are fixed WordPress properties.
        $site_names = $wpdb->get_col( $site_sql );
        if ( '' !== $wpdb->last_error || ! is_array( $site_names ) ) {
            return false;
        }
        // phpcs:enable WordPress.DB.PreparedSQL.NotPrepared

        // Both prefixes are stripped, as the deletion path does: a LIKE '_transient_%' sweep returns
        // the value row and the timeout row of the same transient, and stripping only one of them
        // counts that transient twice.
        $keys = array();
        foreach ( $names as $name ) {
            if ( is_string( $name ) ) {
                $key = $this->transient_name_from_option( $name, false );
                if ( '' !== $key ) {
                    $keys[ 'local:' . $key ] = true;
                }
            }
        }
        foreach ( $site_names as $name ) {
            if ( is_string( $name ) ) {
                $key = $this->transient_name_from_option( $name, true );
                if ( '' !== $key ) {
                    $keys[ 'site:' . $key ] = true;
                }
            }
        }
        return array(
            'would_affect' => count( $keys ),
            'capability'   => true,
        );
    }

    /**
     * Execute one exact maintenance effect with durable replay protection.
     *
     * @param array $payload Closed execute payload.
     * @return array<string,mixed> Closed operation response.
     */
    private function abilities_v2_execute( $payload ) { // phpcs:ignore Generic.Metrics.CyclomaticComplexity -- Explicit durable transition flow.
        $operation = 'ability_maintenance_execute_v2';
        $actions   = $this->abilities_v2_execute_actions( $payload );
        if ( false === $actions ) {
            return $this->abilities_v2_error( $operation, 'invalid_request' );
        }
        $action_hash = hash( 'sha256', wp_json_encode( array( $actions, $payload['revision_retention'] ) ) );
        $effect_hash = hash( 'sha256', wp_json_encode( array( $payload['operation_ref'], $actions, $payload['revision_retention'], $payload['snapshot_revision'], $action_hash ) ) );
        $records     = $this->abilities_v2_read_operations();
        if ( false === $records ) {
            return $this->abilities_v2_error( $operation, 'storage_unavailable' );
        }
        if ( isset( $records[ $payload['operation_ref'] ] ) ) {
            $record = $records[ $payload['operation_ref'] ];
            if ( ! hash_equals( $record['effect_hash'], $effect_hash ) ) {
                return $this->abilities_v2_error( $operation, 'request_conflict' );
            }
            return $this->abilities_v2_project_operation( $record, $operation );
        }

        $preview = $this->abilities_v2_preview(
            array(
                'actions'            => $actions,
                'revision_retention' => $payload['revision_retention'],
            )
        );
        if ( ! isset( $preview['ok'] ) || true !== $preview['ok'] || ! isset( $preview['snapshot_revision'] ) || ! is_string( $preview['snapshot_revision'] ) ) {
            return $this->abilities_v2_error( $operation, 'invalid_snapshot' );
        }
        if ( ! hash_equals( $preview['snapshot_revision'], $payload['snapshot_revision'] ) ) {
            return $this->abilities_v2_error( $operation, 'stale_snapshot' );
        }
        foreach ( $preview['impacts'] as $impact ) {
            if ( ! isset( $impact['capability'] ) || true !== $impact['capability'] ) {
                return $this->abilities_v2_error( $operation, 'unsupported' );
            }
        }

        $now                                  = time();
        $record                               = array(
            'operation_ref'      => $payload['operation_ref'],
            'effect_hash'        => $effect_hash,
            'action_hash'        => $action_hash,
            'snapshot_revision'  => $payload['snapshot_revision'],
            'actions'            => $actions,
            'revision_retention' => $payload['revision_retention'],
            'status'             => 'running',
            'outcomes'           => array(),
            'accepted_at'        => $now,
            'finished_at'        => null,
            'retryable'          => false,
            'report_emitted'     => false,
            'updated_at'         => $now,
        );
        $records[ $payload['operation_ref'] ] = $record;
        if ( ! $this->abilities_v2_write_operations( $records ) ) {
            return $this->abilities_v2_error( $operation, 'storage_unavailable' );
        }

        // Pinned before the first action rather than at the first stop that consults it, because an
        // action list that opens with something other than the sweep would otherwise fix the instant
        // only after that action already spent part of the request on it.
        $this->abilities_v2_deadline();
        $successful = array();
        foreach ( $actions as $action ) {
            // The margin the sweep's deadline reserves settles this record and releases the lock,
            // and it belongs to the request rather than to the action that stopped short of it. An
            // action that already gave up on a spent budget otherwise hands the rest of the list
            // that margin, and the fatal inside the next destructive action leaves this record
            // running with the lock held: the failure the deadline exists to prevent, one level up.
            // Ahead of the renewal, which is itself an option write. The first action is exempt
            // because no margin has been reserved yet and a budget-aware action declines its own
            // destructive work with a truthful count; refusing before anything ran would turn a
            // request that arrives slow into an operation that never does work at all.
            if ( ! empty( $record['outcomes'] ) && $this->abilities_v2_budget_spent() ) {
                break;
            }
            if ( ! $this->abilities_v2_renew_mutation() ) {
                return $this->abilities_v2_error( $operation, 'outcome_unknown' );
            }
            $outcome = $this->abilities_v2_execute_action( $action, $payload['revision_retention'] );
            if ( ! $this->abilities_v2_valid_action_outcome( $outcome ) ) {
                $outcome = array(
                    'status'     => 'unknown',
                    'affected'   => null,
                    'error_code' => 'outcome_unknown',
                );
            }
            $record['outcomes'][] = array(
                'action'     => $action,
                'status'     => $outcome['status'],
                'affected'   => $outcome['affected'],
                'error_code' => $outcome['error_code'],
            );
            if ( 'succeeded' === $outcome['status'] ) {
                $successful[] = $action;
            }
            $record['updated_at']                 = time();
            $records[ $payload['operation_ref'] ] = $record;
            if ( ! $this->abilities_v2_write_operations( $records ) ) {
                return $this->abilities_v2_error( $operation, 'outcome_unknown' );
            }
        }

        if ( ! empty( $successful ) ) {
            $this->abilities_v2_emit_report( $successful, $payload['revision_retention'] );
            $record['report_emitted'] = true;
        }
        $statuses  = array_column( $record['outcomes'], 'status' );
        $succeeded = count(
            array_filter(
                $statuses,
                static function ( $status ) {
                    return 'succeeded' === $status;
                }
            )
        );
        // A run that stopped between actions carries no outcome for what it never started, so the
        // statuses it does have describe part of the list: deriving from them alone reports the
        // whole operation as succeeded on the strength of the actions that ran before the stop.
        $record['status']                     = count( $record['outcomes'] ) !== count( $actions ) || in_array( 'unknown', $statuses, true ) ? 'unknown' : ( count( $statuses ) === $succeeded ? 'succeeded' : ( 0 < $succeeded ? 'partial' : 'failed' ) );
        $record['finished_at']                = time();
        $record['updated_at']                 = $record['finished_at'];
        $record['retryable']                  = 'failed' === $record['status'];
        $records[ $payload['operation_ref'] ] = $record;
        if ( ! $this->abilities_v2_write_operations( $records ) ) {
            return $this->abilities_v2_error( $operation, 'outcome_unknown' );
        }

        return $this->abilities_v2_project_operation( $record, $operation );
    }

    /**
     * Validate an execute payload and return its canonical action set.
     *
     * Runs before the mutation lock is taken as well as inside the execute path, so the same
     * malformed payload gets the same invalid_request whether or not another run holds the lock.
     *
     * @param mixed $payload Closed execute payload.
     * @return array<int,string>|false
     */
    private function abilities_v2_execute_actions( $payload ) {
        if ( ! is_array( $payload ) || ! $this->abilities_v2_exact_keys( $payload, array( 'operation_ref', 'actions', 'revision_retention', 'snapshot_revision', 'action_hash' ) ) || ! $this->abilities_v2_valid_uuid( $payload['operation_ref'] ) || ! $this->abilities_v2_hash( $payload['snapshot_revision'] ) || ! $this->abilities_v2_hash( $payload['action_hash'] ) || ! is_int( $payload['revision_retention'] ) || 0 > $payload['revision_retention'] || 1000 < $payload['revision_retention'] ) {
            return false;
        }
        $actions = $this->abilities_v2_canonical_actions( $payload['actions'] );
        if ( false === $actions ) {
            return false;
        }
        return hash_equals( hash( 'sha256', wp_json_encode( array( $actions, $payload['revision_retention'] ) ) ), $payload['action_hash'] ) ? $actions : false;
    }

    /**
     * Report a failed lock release without rewriting the outcome it accompanies.
     *
     * The effect and its durable record are both committed by the time the lock is released, so a
     * failed release cannot make any of that untrue. The lock expires on its own 300s TTL, and the
     * caller is told about the release through an advisory warning instead of being handed an
     * outcome_unknown for a run whose outcome is known.
     *
     * @param mixed $result   Result produced under the lock.
     * @param bool  $released Whether the lock release succeeded.
     * @return mixed
     */
    private function abilities_v2_with_lock_release( $result, $released ) {
        if ( $released || ! is_array( $result ) ) {
            return $result;
        }
        $warnings                = isset( $result['warning_codes'] ) && is_array( $result['warning_codes'] ) ? $result['warning_codes'] : array();
        $warnings[]              = 'lock_release_failed';
        $result['warning_codes'] = array_values( array_unique( $warnings ) );
        return $result;
    }

    /**
     * Read one durable maintenance operation.
     *
     * @param array $payload Closed status payload.
     * @return array<string,mixed> Closed operation response.
     */
    private function abilities_v2_operation( $payload ) {
        $operation = 'ability_maintenance_operation_v2';
        if ( ! is_array( $payload ) || ! $this->abilities_v2_exact_keys( $payload, array( 'operation_ref' ) ) || ! $this->abilities_v2_valid_uuid( $payload['operation_ref'] ) ) {
            return $this->abilities_v2_error( $operation, 'invalid_request' );
        }
        $records = $this->abilities_v2_read_operations();
        if ( false === $records ) {
            return $this->abilities_v2_error( $operation, 'storage_unavailable' );
        }
        if ( ! isset( $records[ $payload['operation_ref'] ] ) ) {
            return $this->abilities_v2_error( $operation, 'operation_not_found' );
        }
        return $this->abilities_v2_project_operation( $records[ $payload['operation_ref'] ], $operation );
    }

    /**
     * Execute one checked cleanup action.
     *
     * @param string $action             Canonical action.
     * @param int    $revision_retention Revisions retained.
     * @return array<string,int|string|null>|false
     */
    protected function abilities_v2_execute_action( $action, $revision_retention ) { // phpcs:ignore Generic.Metrics.CyclomaticComplexity -- Closed action switch.
        if ( in_array( $action, array( 'transients_expired', 'transients_all' ), true ) && function_exists( 'wp_using_ext_object_cache' ) && wp_using_ext_object_cache() ) {
            return $this->abilities_v2_failed_outcome( 'unsupported' );
        }
        if ( 'revisions' === $action ) {
            return $this->abilities_v2_delete_revisions( $revision_retention );
        }
        $sql_actions = array(
            'autodraft'    => array( 'posts', "post_status = 'auto-draft'" ),
            'trashpost'    => array( 'posts', "post_status = 'trash'" ),
            'spam'         => array( 'comments', "comment_approved = 'spam'" ),
            'pending'      => array( 'comments', "comment_approved = '0'" ),
            'trashcomment' => array( 'comments', "comment_approved = 'trash'" ),
        );
        if ( isset( $sql_actions[ $action ] ) ) {
            return $this->abilities_v2_delete_rows( $sql_actions[ $action ][0], $sql_actions[ $action ][1] );
        }
        if ( 'tags' === $action || 'categories' === $action ) {
            return $this->abilities_v2_delete_terms( 'tags' === $action ? 'post_tag' : 'category' );
        }
        if ( 'optimize' === $action ) {
            return $this->abilities_v2_optimize_tables();
        }
        if ( 'transients_expired' === $action || 'transients_all' === $action ) {
            return $this->abilities_v2_delete_transients( 'transients_expired' === $action );
        }
        return false;
    }

    /**
     * Read, validate and prune durable operation records.
     *
     * @return array<string,array>|false
     */
    protected function abilities_v2_read_operations() {
        $records = get_option( self::ABILITIES_V2_OPERATIONS_OPTION, array() );
        if ( ! is_array( $records ) || self::ABILITIES_V2_MAX_OPERATIONS < count( $records ) ) {
            return false;
        }
        foreach ( $records as $operation_ref => $record ) {
            if ( ! is_string( $operation_ref ) || ! $this->abilities_v2_valid_operation_record( $record ) || ! hash_equals( $operation_ref, $record['operation_ref'] ) ) {
                return false;
            }
        }
        return $records;
    }

    /**
     * Persist and exactly read back durable operation records.
     *
     * @param array $records Operation records.
     * @return bool
     */
    protected function abilities_v2_write_operations( $records ) {
        if ( ! is_array( $records ) ) {
            return false;
        }
        $cutoff = time() - ( 7 * DAY_IN_SECONDS );
        foreach ( $records as $operation_ref => $record ) {
            if ( ! $this->abilities_v2_valid_operation_record( $record ) || ! hash_equals( $operation_ref, $record['operation_ref'] ) ) {
                return false;
            }
            $record                    = $this->abilities_v2_settle_stale_operation( $record );
            $records[ $operation_ref ] = $record;
            if ( null !== $record['finished_at'] && $record['finished_at'] < $cutoff ) {
                unset( $records[ $operation_ref ] );
            }
        }
        // A record inside the retention window is the proof that makes a Dashboard retry safe: the
        // execute payload carries no expiry, so every stored record is still replayable, and a retry
        // that lands on a forgotten one re-runs the same destructive actions against rows the first
        // run never saw. Refusing is loud, costs the caller a storage_unavailable, and clears itself
        // once the settle above turns the oldest run terminal and the prune ages it out; freeing a
        // slot by dropping a receipt is silent and costs data.
        if ( self::ABILITIES_V2_MAX_OPERATIONS < count( $records ) ) {
            return false;
        }
        update_option( self::ABILITIES_V2_OPERATIONS_OPTION, $records, false );
        return get_option( self::ABILITIES_V2_OPERATIONS_OPTION, null ) === $records;
    }

    /**
     * Settle a running record that nothing has advanced for a day.
     *
     * A run only writes to its record while it holds the 300s mutation lock and renews that lock
     * between actions, so a record still marked running a day after its last write has no process
     * behind it. Leaving it there is both a false claim and a leak: running records carry no
     * finished_at, the prune above only evicts finished ones, and 100 abandoned records make every
     * later execute fail on the record cap for good. finished_at is stamped with the last time the
     * Child actually observed the run rather than with now, because settling is not an observation.
     *
     * @param array $record Validated durable record.
     * @return array
     */
    private function abilities_v2_settle_stale_operation( $record ) {
        if ( 'running' !== $record['status'] || $record['updated_at'] >= time() - DAY_IN_SECONDS ) {
            return $record;
        }
        foreach ( array_slice( $record['actions'], count( $record['outcomes'] ) ) as $action ) {
            $record['outcomes'][] = array(
                'action'     => $action,
                'status'     => 'unknown',
                'affected'   => null,
                'error_code' => 'outcome_unknown',
            );
        }
        $record['status']      = 'unknown';
        $record['finished_at'] = $record['updated_at'];
        $record['retryable']   = false;
        return $record;
    }

    /**
     * Emit one legacy report event for a durable successful action set.
     *
     * @param array $actions            Successful actions.
     * @param int   $revision_retention Revisions retained.
     * @return void
     */
    protected function abilities_v2_emit_report( $actions, $revision_retention ) {
        if ( has_action( 'mainwp_reports_maintenance' ) ) {
            $details = array_map(
                static function ( $action ) use ( $revision_retention ) {
                    return 'revisions' === $action && 0 < $revision_retention ? 'revisions_max' : $action;
                },
                $actions
            );
            do_action( 'mainwp_reports_maintenance', 'Maintenance Performed', time(), implode( ',', $details ), 'Maintenance Performed', $revision_retention );
        }
    }

    /** Acquire the exact Child mutation lock. */
    protected function abilities_v2_begin_mutation() {
        $now      = time();
        $existing = get_option( self::ABILITIES_V2_LOCK_OPTION, null );
        if ( is_array( $existing ) && $this->abilities_v2_exact_keys( $existing, array( 'owner', 'expires_at' ) ) && is_string( $existing['owner'] ) && is_int( $existing['expires_at'] ) && $existing['expires_at'] >= $now ) {
            return false;
        }
        if ( null !== $existing ) {
            delete_option( self::ABILITIES_V2_LOCK_OPTION );
            if ( null !== get_option( self::ABILITIES_V2_LOCK_OPTION, null ) ) {
                return false;
            }
        }
        $owner = wp_generate_uuid4();
        $lock  = array(
            'owner'      => $owner,
            'expires_at' => $now + 300,
        );
        if ( ! add_option( self::ABILITIES_V2_LOCK_OPTION, $lock, '', false ) || get_option( self::ABILITIES_V2_LOCK_OPTION, null ) !== $lock ) {
            return false;
        }
        $this->abilities_v2_lock_owner = $owner;
        return true;
    }

    /** Renew and verify the exact Child mutation lock. */
    protected function abilities_v2_renew_mutation() {
        $lock = get_option( self::ABILITIES_V2_LOCK_OPTION, null );
        if ( '' === $this->abilities_v2_lock_owner || ! is_array( $lock ) || ! $this->abilities_v2_exact_keys( $lock, array( 'owner', 'expires_at' ) ) || ! is_string( $lock['owner'] ) || ! hash_equals( $this->abilities_v2_lock_owner, $lock['owner'] ) ) {
            return false;
        }
        $renewed = array(
            'owner'      => $lock['owner'],
            'expires_at' => time() + 300,
        );
        update_option( self::ABILITIES_V2_LOCK_OPTION, $renewed, false );
        return get_option( self::ABILITIES_V2_LOCK_OPTION, null ) === $renewed;
    }

    /** Release and verify the exact Child mutation lock. */
    protected function abilities_v2_end_mutation() {
        $lock = get_option( self::ABILITIES_V2_LOCK_OPTION, null );
        if ( '' === $this->abilities_v2_lock_owner || ! is_array( $lock ) || ! isset( $lock['owner'] ) || ! is_string( $lock['owner'] ) || ! hash_equals( $this->abilities_v2_lock_owner, $lock['owner'] ) ) {
            $this->abilities_v2_lock_owner = '';
            return false;
        }
        delete_option( self::ABILITIES_V2_LOCK_OPTION );
        $this->abilities_v2_lock_owner = '';
        return null === get_option( self::ABILITIES_V2_LOCK_OPTION, null );
    }

    /**
     * Canonicalize a closed action set.
     *
     * @param mixed $actions Requested actions.
     * @return array<int,string>|false
     */
    private function abilities_v2_canonical_actions( $actions ) {
        $catalog = array( 'revisions', 'autodraft', 'trashpost', 'spam', 'pending', 'trashcomment', 'tags', 'categories', 'optimize', 'transients_expired', 'transients_all' );
        if ( ! is_array( $actions ) || empty( $actions ) || 11 < count( $actions ) || array_keys( $actions ) !== range( 0, count( $actions ) - 1 ) ) {
            return false;
        }
        $selected = array();
        foreach ( $actions as $action ) {
            if ( ! is_string( $action ) || ! in_array( $action, $catalog, true ) || isset( $selected[ $action ] ) ) {
                return false;
            }
            $selected[ $action ] = true;
        }
        if ( isset( $selected['transients_expired'], $selected['transients_all'] ) ) {
            return false;
        }
        return array_values(
            array_filter(
                $catalog,
                static function ( $action ) use ( $selected ) {
                    return isset( $selected[ $action ] );
                }
            )
        );
    }

    /**
     * Validate a UUID-valued operation reference.
     *
     * @param mixed $value Candidate value.
     * @return bool
     */
    private function abilities_v2_valid_uuid( $value ) {
        return is_string( $value ) && 1 === preg_match( '/^[a-f0-9]{8}-[a-f0-9]{4}-[1-5][a-f0-9]{3}-[89ab][a-f0-9]{3}-[a-f0-9]{12}$/D', $value );
    }

    /**
     * Validate a SHA-256 value.
     *
     * @param mixed $value Candidate value.
     * @return bool
     */
    private function abilities_v2_hash( $value ) {
        return is_string( $value ) && 1 === preg_match( '/^[a-f0-9]{64}$/D', $value );
    }

    /**
     * Validate one protected action outcome.
     *
     * @param mixed $outcome Outcome.
     * @return bool
     */
    private function abilities_v2_valid_action_outcome( $outcome ) {
        if ( ! is_array( $outcome ) || ! $this->abilities_v2_exact_keys( $outcome, array( 'status', 'affected', 'error_code' ) ) || ! in_array( $outcome['status'], array( 'succeeded', 'failed', 'unknown' ), true ) ) {
            return false;
        }
        if ( 'succeeded' === $outcome['status'] ) {
            return is_int( $outcome['affected'] ) && 0 <= $outcome['affected'] && null === $outcome['error_code'];
        }
        // An action that stopped part way through still destroyed rows, so a failed or unknown
        // outcome may carry the count it did affect; null keeps meaning the count is not known.
        return ( null === $outcome['affected'] || ( is_int( $outcome['affected'] ) && 0 <= $outcome['affected'] ) ) && in_array( $outcome['error_code'], array( 'mutation_failed', 'unsupported', 'outcome_unknown' ), true );
    }

    /**
     * Validate one durable operation record.
     *
     * @param mixed $record Candidate record.
     * @return bool
     */
    private function abilities_v2_valid_operation_record( $record ) { // phpcs:ignore Generic.Metrics.CyclomaticComplexity -- Closed durable row validator.
        $keys = array( 'operation_ref', 'effect_hash', 'action_hash', 'snapshot_revision', 'actions', 'revision_retention', 'status', 'outcomes', 'accepted_at', 'finished_at', 'retryable', 'report_emitted', 'updated_at' );
        if ( ! is_array( $record ) || array_keys( $record ) !== $keys || ! $this->abilities_v2_valid_uuid( $record['operation_ref'] ) || ! $this->abilities_v2_hash( $record['effect_hash'] ) || ! $this->abilities_v2_hash( $record['action_hash'] ) || ! $this->abilities_v2_hash( $record['snapshot_revision'] ) || false === $this->abilities_v2_canonical_actions( $record['actions'] ) || ! is_int( $record['revision_retention'] ) || 0 > $record['revision_retention'] || 1000 < $record['revision_retention'] || ! in_array( $record['status'], array( 'running', 'succeeded', 'partial', 'failed', 'unknown' ), true ) || ! is_array( $record['outcomes'] ) || 11 < count( $record['outcomes'] ) || ! is_int( $record['accepted_at'] ) || 0 >= $record['accepted_at'] || ! is_bool( $record['retryable'] ) || ! is_bool( $record['report_emitted'] ) || ! is_int( $record['updated_at'] ) || $record['updated_at'] < $record['accepted_at'] ) {
            return false;
        }
        $expected_action_hash = hash( 'sha256', wp_json_encode( array( $record['actions'], $record['revision_retention'] ) ) );
        $expected_effect_hash = hash( 'sha256', wp_json_encode( array( $record['operation_ref'], $record['actions'], $record['revision_retention'], $record['snapshot_revision'], $expected_action_hash ) ) );
        if ( ! hash_equals( $expected_action_hash, $record['action_hash'] ) || ! hash_equals( $expected_effect_hash, $record['effect_hash'] ) ) {
            return false;
        }
        if ( null !== $record['finished_at'] && ( ! is_int( $record['finished_at'] ) || $record['finished_at'] < $record['accepted_at'] ) ) {
            return false;
        }
        // This store is untrusted input - a hand-edited option or a partial restore reaches here -
        // and nothing else pins the outcome keys down. The loop below matches each outcome against
        // actions[$index] by its own key, so a list that starts at 1 or skips an index passes it
        // while every reader treats the outcome count as a prefix of the action list: the short
        // terminal record allowed below then projects one action dropped, another duplicated, and
        // integer keys that encode as a JSON object instead of an array. Refused, not repaired.
        if ( 0 < count( $record['outcomes'] ) && array_keys( $record['outcomes'] ) !== range( 0, count( $record['outcomes'] ) - 1 ) ) {
            return false;
        }
        foreach ( $record['outcomes'] as $index => $outcome ) {
            if ( ! is_array( $outcome ) || ! $this->abilities_v2_exact_keys( $outcome, array( 'action', 'status', 'affected', 'error_code' ) ) || ! isset( $record['actions'][ $index ] ) || $outcome['action'] !== $record['actions'][ $index ] || ! $this->abilities_v2_valid_action_outcome(
                array(
                    'status'     => $outcome['status'],
                    'affected'   => $outcome['affected'],
                    'error_code' => $outcome['error_code'],
                )
            ) ) {
                return false;
            }
        }
        if ( 'running' === $record['status'] ) {
            return null === $record['finished_at'] && false === $record['retryable'];
        }
        $successful = count(
            array_filter(
                array_column( $record['outcomes'], 'status' ),
                static function ( $status ) {
                    return 'succeeded' === $status;
                }
            )
        );
        // Only an unknown record may hold fewer outcomes than actions: that is a run which stopped
        // between actions and says so by carrying nothing for the ones it never started, and the
        // loop above already pins every outcome it does hold to the action at its own index. Any
        // other terminal status with a short list is claiming a whole-operation result from part of
        // it. Readers pad the missing entries, so the projected response still covers every action.
        if ( null === $record['finished_at'] || ( 'unknown' !== $record['status'] && count( $record['actions'] ) !== count( $record['outcomes'] ) ) ) {
            return false;
        }
        // A settled-stale record can hold successful outcomes whose report was never emitted: the
        // run died before it reached the emit step, and settling it is no reason to emit one now.
        return 'unknown' === $record['status'] || ( 0 < $successful ) === $record['report_emitted'];
    }

    /**
     * Project a durable record into a closed response.
     *
     * @param array  $record    Durable record.
     * @param string $operation Response operation.
     * @return array<string,mixed>
     */
    private function abilities_v2_project_operation( $record, $operation ) {
        $status   = 'running' === $record['status'] && $record['updated_at'] < time() - 300 ? 'unknown' : $record['status'];
        $outcomes = $record['outcomes'];
        if ( 'unknown' === $status ) {
            foreach ( array_slice( $record['actions'], count( $outcomes ) ) as $action ) {
                $outcomes[] = array(
                    'action'     => $action,
                    'status'     => 'unknown',
                    'affected'   => null,
                    'error_code' => 'outcome_unknown',
                );
            }
        }
        return array(
            'protocol'      => '2',
            'operation'     => $operation,
            'ok'            => true,
            'operation_ref' => $record['operation_ref'],
            'status'        => $status,
            'outcomes'      => $outcomes,
            'accepted_at'   => gmdate( 'Y-m-d\TH:i:s\Z', $record['accepted_at'] ),
            'finished_at'   => null === $record['finished_at'] ? null : gmdate( 'Y-m-d\TH:i:s\Z', $record['finished_at'] ),
            'retryable'     => $record['retryable'],
        );
    }

    /**
     * Return a stable failed action outcome.
     *
     * @param string   $code     Stable error code.
     * @param int|null $affected Rows the action destroyed before it stopped, or null when unknown.
     * @return array<string,int|string|null>
     */
    private function abilities_v2_failed_outcome( $code = 'mutation_failed', $affected = null ) {
        return array(
            'status'     => 'failed',
            'affected'   => $affected,
            'error_code' => $code,
        );
    }

    /**
     * Return the outcome of a destructive action the budget stopped.
     *
     * Nothing can interrupt a single DELETE or one OPTIMIZE TABLE once it is issued, so declining
     * to start is the only bound at the entry: a request with nothing left of its limit would spend
     * the settle margin inside one and die with the record running and the lock held. The loops are
     * interruptible between iterations, and stopping there is the same stop with a different count,
     * so both report through here. What the action already destroyed travels back, because stopping
     * does not put it back; what it had not reached yet is not known, which is what the status says.
     *
     * @param int $affected Rows, terms or entries this run destroyed before it stopped.
     * @return array<string,int|string|null>
     */
    private function abilities_v2_budget_declined_outcome( $affected = 0 ) {
        return array(
            'status'     => 'unknown',
            'affected'   => $affected,
            'error_code' => 'outcome_unknown',
        );
    }

    /**
     * Instant a revision sweep has to stop deleting at.
     *
     * @return int Unix timestamp.
     */
    private function abilities_v2_revision_sweep_deadline() {
        // PHP's execution limit is what actually ends an unbounded sweep, and it ends it with a
        // fatal that leaves the record unsettled and the lock held. Stopping short of it keeps the
        // margin needed to write the outcome and release the lock. A limit of 0 is CLI or an
        // explicitly unlimited request, where only the budget applies.
        $limit = (int) ini_get( 'max_execution_time' );
        if ( 0 >= $limit ) {
            return time() + self::ABILITIES_V2_REVISION_SWEEP_BUDGET;
        }
        global $timestart;
        // The limit bounds the whole request, not this method: request validation, the preview and
        // any earlier action already spent part of it. Granting the sweep the full limit puts the
        // deadline behind the fatal it exists to prevent, so what is left of the request is what it
        // gets. WordPress stamps the request start into $timestart as a float; a value that is
        // missing, not numeric, not positive or in the future is a host that moved the clock or
        // never loaded wp-settings, and that is no reason to refuse a sweep - elapsed counts as
        // nothing there and the deadline is the whole limit again, as it was before.
        $now     = microtime( true );
        $elapsed = 0;
        if ( is_numeric( $timestart ) && 0 < $timestart && $timestart <= $now ) {
            $elapsed = $now - (float) $timestart;
        }
        // What is left of the request is all the sweep gets, and when nothing is left it gets no
        // work: this goes negative or zero on a request that already spent its limit, so the
        // deadline lands in the past and the first check stops the sweep before any page query or
        // 500-row delete. Granting a token second there would start exactly the batch whose fatal
        // this bound exists to prevent; an unknown with nothing destroyed is truthful and costs
        // only a retry, and the next request arrives with the whole limit again.
        return time() + (int) min( self::ABILITIES_V2_REVISION_SWEEP_BUDGET, $limit - $elapsed - self::ABILITIES_V2_REVISION_SWEEP_MARGIN );
    }

    /**
     * The instant this request stops destructive work at, fixed the first time it is asked for.
     *
     * @return int Unix timestamp.
     */
    private function abilities_v2_deadline() {
        // The arithmetic above answers "what is left of the request from here", so re-deriving it
        // after an action hands that action's own spending back: the stop slides forward by however
        // long the work took and never arrives. With no execution limit it slides by the whole
        // budget every time, so a caller that re-derives can never see the budget as spent at all.
        // Pinned once and shared, so the sweep's internal checks and the operation-level stop are
        // the same instant instead of two that drift apart. Reached through a getter rather than a
        // parameter because the sweep hangs off an action dispatch whose signature subclasses
        // override, and because the sweep is also entered directly, without an action loop above it.
        if ( null === $this->abilities_v2_deadline ) {
            $this->abilities_v2_deadline = $this->abilities_v2_revision_sweep_deadline();
        }
        return $this->abilities_v2_deadline;
    }

    /**
     * Whether the request has nothing left to spend on destructive work.
     *
     * @return bool
     */
    private function abilities_v2_budget_spent() {
        return time() >= $this->abilities_v2_deadline();
    }

    /**
     * Delete revisions with exact retention.
     *
     * @param int $revision_retention Revisions retained per parent.
     * @return array<string,int|string|null>
     */
    private function abilities_v2_delete_revisions( $revision_retention ) {
        global $wpdb;
        $deadline = $this->abilities_v2_deadline();
        // Decided ahead of the retention-zero path below, which is one DELETE over every revision on
        // the site and the largest statement in this file. Nothing can interrupt a statement once it
        // is issued, so declining to start it is the only bound there is: a request with nothing
        // left of its limit would spend the settle margin inside it and die with the record running
        // and the lock held. Reported as the paged path reports an exhausted budget, because it is
        // the same stop: nothing was destroyed and what is left over retention is not known.
        if ( time() >= $deadline ) {
            return $this->abilities_v2_budget_declined_outcome();
        }
        if ( 0 === $revision_retention ) {
            return $this->abilities_v2_delete_rows( 'posts', "post_type = 'revision'" );
        }
        $affected = 0;
        while ( true ) {
            // Renewal re-arms the lock TTL, so it never ends this loop on a site whose editors keep
            // creating revisions: every page still finds parents over retention and still deletes
            // something, so the progress invariant stays satisfied and only the execution limit is
            // left to stop the run. The wall clock stops it first, before the page rather than
            // after, so the margin is still there to settle the record. Ahead of the renewal too,
            // because renewing is itself an option write: a request already out of time must not
            // spend any on the way to noticing.
            if ( time() >= $deadline ) {
                return array(
                    'status'     => 'unknown',
                    'affected'   => $affected,
                    'error_code' => 'outcome_unknown',
                );
            }
            // A site with enough parents keeps paging past the 300s lock TTL, so the lock is renewed
            // and re-verified before every page. Once this run can no longer prove it owns the lock
            // it stops deleting and reports the rows it already destroyed alongside the unknown,
            // rather than claiming a sweep it could not finish under the lock.
            if ( ! $this->abilities_v2_renew_mutation() ) {
                return array(
                    'status'     => 'unknown',
                    'affected'   => $affected,
                    'error_code' => 'outcome_unknown',
                );
            }
            $wpdb->last_error = '';
            // A parent drops out of this result set once its surplus is gone, so the same first
            // page is re-read until nothing is over retention; an OFFSET would step over parents.
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Bounded page of parents holding surplus revisions.
            $parents = $wpdb->get_col( $wpdb->prepare( "SELECT post_parent FROM $wpdb->posts WHERE post_type = 'revision' GROUP BY post_parent HAVING COUNT(*) > %d LIMIT %d", $revision_retention, self::ABILITIES_V2_REVISION_PARENT_PAGE ) );
            if ( '' !== $wpdb->last_error || ! is_array( $parents ) ) {
                return $this->abilities_v2_failed_outcome( 'mutation_failed', $affected );
            }
            if ( empty( $parents ) ) {
                return array(
                    'status'     => 'succeeded',
                    'affected'   => $affected,
                    'error_code' => null,
                );
            }
            // The page query is the unbounded half of an iteration: it groups the whole revision set
            // on a site large enough to need paging, so it can eat the rest of the budget on its own
            // and hand back a perfectly valid page. Checking only at the top of the loop would spend
            // that page on deletes the margin no longer covers, which is the fatal-with-an-unsettled
            // -record this bound exists to prevent. A read is free to abandon - nothing was destroyed,
            // so the count already gathered stays true.
            if ( time() >= $deadline ) {
                return array(
                    'status'     => 'unknown',
                    'affected'   => $affected,
                    'error_code' => 'outcome_unknown',
                );
            }
            $page_affected = 0;
            foreach ( $parents as $parent ) {
                if ( ! is_numeric( $parent ) ) {
                    return $this->abilities_v2_failed_outcome( 'mutation_failed', $affected );
                }
                $removed        = $this->abilities_v2_delete_parent_revisions( (int) $parent, $revision_retention, $deadline );
                $affected      += $removed['deleted'];
                $page_affected += $removed['deleted'];
                // Two different reasons to stop mid-parent, one truthful report: the rows are gone
                // either way and what is left over retention is not known from here.
                if ( 'lock_lost' === $removed['status'] || 'out_of_time' === $removed['status'] ) {
                    return array(
                        'status'     => 'unknown',
                        'affected'   => $affected,
                        'error_code' => 'outcome_unknown',
                    );
                }
                if ( 'completed' !== $removed['status'] ) {
                    return $this->abilities_v2_failed_outcome( 'mutation_failed', $affected );
                }
            }
            if ( 0 === $page_affected ) {
                // Parents still report surplus revisions that no delete removed: stop instead of spinning.
                return $this->abilities_v2_failed_outcome( 'mutation_failed', $affected );
            }
        }
    }

    /**
     * Delete one parent's surplus revisions in bounded batches.
     *
     * @param int $parent_id          Parent post ID.
     * @param int $revision_retention Revisions retained for this parent.
     * @param int $deadline           Instant the sweep has to stop deleting at.
     * @return array<string,int|string> Rows deleted, and whether this parent completed, hit a failed statement, lost the lock, or ran out of time.
     */
    private function abilities_v2_delete_parent_revisions( $parent_id, $revision_retention, $deadline ) {
        global $wpdb;
        $deleted = 0;
        while ( true ) {
            $wpdb->last_error = '';
            // The retained revisions stay at the top of this ordering, so the same OFFSET keeps
            // returning the next surplus batch as earlier batches are removed.
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Bounded batch of surplus revision IDs.
            $ids = $wpdb->get_col( $wpdb->prepare( "SELECT ID FROM $wpdb->posts WHERE post_type = 'revision' AND post_parent = %d ORDER BY post_modified DESC, ID DESC LIMIT %d OFFSET %d", $parent_id, self::ABILITIES_V2_REVISION_DELETE_BATCH, $revision_retention ) );
            if ( '' !== $wpdb->last_error || ! is_array( $ids ) ) {
                return array(
                    'deleted' => $deleted,
                    'status'  => 'failed',
                );
            }
            if ( empty( $ids ) ) {
                return array(
                    'deleted' => $deleted,
                    'status'  => 'completed',
                );
            }
            // Same reason the pages re-check after their read: an ORDER BY over one parent's
            // revisions can outlast whatever budget was left and still return its batch, and the
            // delete underneath is what must not start without margin to settle the record. Stopping
            // on a completed read destroys nothing, so $deleted is still exactly what left the table.
            if ( time() >= $deadline ) {
                return array(
                    'deleted' => $deleted,
                    'status'  => 'out_of_time',
                );
            }
            foreach ( $ids as $id ) {
                if ( ! is_numeric( $id ) ) {
                    return array(
                        'deleted' => $deleted,
                        'status'  => 'failed',
                    );
                }
            }
            $ids              = array_map( 'intval', $ids );
            $placeholders     = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
            $wpdb->last_error = '';
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- Placeholder list is built from the row count and prepared with the IDs.
            $removed = $wpdb->query( $wpdb->prepare( "DELETE FROM $wpdb->posts WHERE post_type = 'revision' AND ID IN ($placeholders)", $ids ) );
            if ( '' !== $wpdb->last_error || ! is_int( $removed ) || count( $ids ) !== $removed ) {
                return array(
                    'deleted' => $deleted,
                    'status'  => 'failed',
                );
            }
            $deleted += $removed;
            // Same wall clock as the pages, because one parent's surplus alone can outlast the
            // request, and ahead of the renewal for the same reason: renewing writes an option, and
            // a request already out of time must not spend any on the way to noticing. Reporting
            // this as 'lock_lost' would be a lie: the lock is held and the caller turns both into
            // the same unknown anyway.
            if ( time() >= $deadline ) {
                return array(
                    'deleted' => $deleted,
                    'status'  => 'out_of_time',
                );
            }
            // One parent can hold enough surplus to page past the 300s lock TTL on its own, so ownership is
            // renewed and re-verified between batches and not only between pages: no batch is ever more than
            // one batch of work away from a lock this run could prove it held. The rows already destroyed
            // travel back with the failure, because stopping does not put them back.
            if ( ! $this->abilities_v2_renew_mutation() ) {
                return array(
                    'deleted' => $deleted,
                    'status'  => 'lock_lost',
                );
            }
        }
    }

    /**
     * Delete a fixed closed row class.
     *
     * @param string $table_kind Closed table selector.
     * @param string $where      Closed SQL predicate.
     * @return array<string,int|string|null>
     */
    private function abilities_v2_delete_rows( $table_kind, $where ) {
        global $wpdb;
        if ( $this->abilities_v2_budget_spent() ) {
            return $this->abilities_v2_budget_declined_outcome();
        }
        $table            = 'posts' === $table_kind ? $wpdb->posts : $wpdb->comments;
        $wpdb->last_error = '';
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table and predicate are selected only from the closed action map.
        $affected = $wpdb->query( "DELETE FROM $table WHERE $where" );
        if ( false === $affected || '' !== $wpdb->last_error || ! is_int( $affected ) || 0 > $affected ) {
            return $this->abilities_v2_failed_outcome();
        }
        return array(
            'status'     => 'succeeded',
            'affected'   => $affected,
            'error_code' => null,
        );
    }

    /**
     * Delete empty terms in one exact taxonomy.
     *
     * @param string $taxonomy Exact taxonomy.
     * @return array<string,int|string|null>
     */
    private function abilities_v2_delete_terms( $taxonomy ) {
        if ( $this->abilities_v2_budget_spent() ) {
            return $this->abilities_v2_budget_declined_outcome();
        }
        $terms = get_terms(
            array(
                'taxonomy'   => $taxonomy,
                'hide_empty' => false,
            )
        );
        if ( is_wp_error( $terms ) || ! is_array( $terms ) ) {
            return $this->abilities_v2_failed_outcome();
        }
        $default_category = (int) get_option( 'default_category', 0 );
        $affected         = 0;
        foreach ( $terms as $term ) {
            if ( ! is_object( $term ) || ! isset( $term->term_id, $term->count ) ) {
                return $this->abilities_v2_failed_outcome( 'mutation_failed', $affected );
            }
            if ( 0 !== (int) $term->count || ( 'category' === $taxonomy && $default_category === (int) $term->term_id ) ) {
                continue;
            }
            // A taxonomy with thousands of empty terms outlasts the request on its own, and every
            // wp_delete_term() is a write. Checked here rather than at the top of the iteration so
            // the terms this run skips cost it nothing.
            if ( $this->abilities_v2_budget_spent() ) {
                return $this->abilities_v2_budget_declined_outcome( $affected );
            }
            $deleted = wp_delete_term( (int) $term->term_id, $taxonomy );
            if ( false === $deleted || is_wp_error( $deleted ) ) {
                return $this->abilities_v2_failed_outcome( 'mutation_failed', $affected );
            }
            ++$affected;
        }
        return array(
            'status'     => 'succeeded',
            'affected'   => $affected,
            'error_code' => null,
        );
    }

    /** Optimize only exact current-blog-prefix tables. */
    private function abilities_v2_optimize_tables() {
        global $wpdb;
        if ( $this->abilities_v2_budget_spent() ) {
            return $this->abilities_v2_budget_declined_outcome();
        }
        $wpdb->last_error = '';
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Reads current database table status for checked optimization.
        $tables = $wpdb->get_results( 'SHOW TABLE STATUS FROM `' . esc_sql( DB_NAME ) . '`', ARRAY_A );
        if ( '' !== $wpdb->last_error || ! is_array( $tables ) ) {
            return $this->abilities_v2_failed_outcome();
        }
        $affected = 0;
        foreach ( $tables as $table ) {
            if ( ! is_array( $table ) || ! isset( $table['Name'] ) || ! is_string( $table['Name'] ) ) {
                return $this->abilities_v2_failed_outcome( 'mutation_failed', $affected );
            }
            if ( 0 !== strpos( $table['Name'], $wpdb->prefix ) ) {
                continue;
            }
            // One OPTIMIZE TABLE cannot be interrupted, but the series can be, and a site's prefixed
            // tables are enough of them to outlast the request. Checked per table, after the prefix
            // filter, so tables this run never touches cost it nothing.
            if ( $this->abilities_v2_budget_spent() ) {
                return $this->abilities_v2_budget_declined_outcome( $affected );
            }
            $identifier       = str_replace( '`', '``', $table['Name'] );
            $wpdb->last_error = '';
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Identifier comes from SHOW TABLE STATUS and is quoted.
            $optimized = $wpdb->query( "OPTIMIZE TABLE `$identifier`" );
            if ( false === $optimized || '' !== $wpdb->last_error ) {
                return $this->abilities_v2_failed_outcome( 'mutation_failed', $affected );
            }
            ++$affected;
        }
        return array(
            'status'     => 'succeeded',
            'affected'   => $affected,
            'error_code' => null,
        );
    }

    /**
     * Delete checked local and site transients.
     *
     * @param bool $expired_only Delete expired entries only.
     * @return array<string,int|string|null>
     */
    private function abilities_v2_delete_transients( $expired_only ) {
        if ( $this->abilities_v2_budget_spent() ) {
            return $this->abilities_v2_budget_declined_outcome();
        }
        $names = $this->abilities_v2_transient_names( $expired_only );
        if ( false === $names ) {
            return $this->abilities_v2_failed_outcome();
        }
        $affected = 0;
        $refused  = 0;
        $scopes   = array(
            'local' => false,
            'site'  => true,
        );
        foreach ( $scopes as $scope => $site ) {
            foreach ( $names[ $scope ] as $name ) {
                // Every name is at least one option write and the list is as long as the site made
                // it, so the walk is bounded per name rather than only at its start.
                if ( $this->abilities_v2_budget_spent() ) {
                    return $this->abilities_v2_budget_declined_outcome( $affected );
                }
                if ( $site ? delete_site_transient( $name ) : delete_transient( $name ) ) {
                    ++$affected;
                    continue;
                }
                $settled = $this->abilities_v2_settle_orphaned_transient( $name, $site );
                if ( null === $settled ) {
                    ++$refused;
                    continue;
                }
                $affected += $settled;
            }
        }
        // A transient the delete API would not take, whose value option is still there, says
        // nothing about the rest, so the run finishes the list and reports both the deletions it
        // made and the fact that it did not clear everything it listed.
        if ( 0 < $refused ) {
            return $this->abilities_v2_failed_outcome( 'mutation_failed', $affected );
        }
        return array(
            'status'     => 'succeeded',
            'affected'   => $affected,
            'error_code' => null,
        );
    }

    /**
     * Settle one transient the delete API refused.
     *
     * The delete API answers false when the value option is already gone, which means the transient
     * is deleted rather than that the Child was refused; what can be left behind is the orphaned
     * timeout row the sweep listed it from. Removing that row is the deletion this run performs, so
     * it is counted. A value option that is still there is a real failure.
     *
     * @param string $name Transient name.
     * @param bool   $site Whether the name is a site transient.
     * @return int|null Rows this run removed, or null when the value option survives.
     */
    private function abilities_v2_settle_orphaned_transient( $name, $site ) {
        $absent = '__mainwp_child_transient_absent__';
        $value  = $site ? get_site_option( '_site_transient_' . $name, $absent ) : get_option( '_transient_' . $name, $absent );
        if ( $absent !== $value ) {
            return null;
        }
        $removed = $site ? delete_site_option( '_site_transient_timeout_' . $name ) : delete_option( '_transient_timeout_' . $name );
        return $removed ? 1 : 0;
    }

    /**
     * Read exact transient names for checked deletion.
     *
     * @param bool $expired_only Read expired entries only.
     * @return array<string,array<int,string>>|false
     */
    private function abilities_v2_transient_names( $expired_only ) {
        global $wpdb;
        $now          = time();
        $local_prefix = $expired_only ? '_transient_timeout_' : '_transient_';
        $site_prefix  = $expired_only ? '_site_transient_timeout_' : '_site_transient_';
        $local_sql    = "SELECT option_name FROM $wpdb->options WHERE option_name LIKE %s";
        // phpcs:disable WordPress.DB.PreparedSQL.NotPrepared -- Placeholder-bearing fixed query structures are prepared before execution.
        if ( $expired_only ) {
            $local_sql .= ' AND option_value < %d';
            $local_sql  = $wpdb->prepare( $local_sql, $wpdb->esc_like( $local_prefix ) . '%', $now );
        } else {
            $local_sql = $wpdb->prepare( $local_sql, $wpdb->esc_like( $local_prefix ) . '%' );
        }
        $wpdb->last_error = '';
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- Fixed prepared transient query.
        $local = $wpdb->get_col( $local_sql );
        if ( '' !== $wpdb->last_error || ! is_array( $local ) ) {
            return false;
        }
        $column       = is_multisite() ? 'meta_key' : 'option_name';
        $value_column = is_multisite() ? 'meta_value' : 'option_value';
        $table        = is_multisite() ? $wpdb->sitemeta : $wpdb->options;
        $site_sql     = "SELECT $column FROM $table WHERE $column LIKE %s";
        if ( $expired_only ) {
            $site_sql .= " AND $value_column < %d";
            $site_sql  = $wpdb->prepare( $site_sql, $wpdb->esc_like( $site_prefix ) . '%', $now );
        } else {
            $site_sql = $wpdb->prepare( $site_sql, $wpdb->esc_like( $site_prefix ) . '%' );
        }
        // phpcs:enable WordPress.DB.PreparedSQL.NotPrepared
        $wpdb->last_error = '';
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Fixed prepared transient query with WordPress table properties.
        $site = $wpdb->get_col( $site_sql );
        if ( '' !== $wpdb->last_error || ! is_array( $site ) ) {
            return false;
        }
        $result = array(
            'local' => array(),
            'site'  => array(),
        );
        foreach ( $local as $name ) {
            if ( ! is_string( $name ) ) {
                return false;
            }
            $key = $this->transient_name_from_option( $name, false );
            if ( '' !== $key ) {
                $result['local'][ $key ] = $key;
            }
        }
        foreach ( $site as $name ) {
            if ( ! is_string( $name ) ) {
                return false;
            }
            $key = $this->transient_name_from_option( $name, true );
            if ( '' !== $key ) {
                $result['site'][ $key ] = $key;
            }
        }
        $result['local'] = array_values( $result['local'] );
        $result['site']  = array_values( $result['site'] );
        return $result;
    }

    /**
     * Read the transient name out of one stored option or meta name.
     *
     * The prefix is a prefix, not a substring: a transient named `_transient_ghost` is stored as
     * `_transient__transient_ghost`, and stripping every occurrence yields `ghost`, which is a
     * different transient the delete path would then destroy instead. Preview and execute share
     * this so the two can never disagree about which names a run covers.
     *
     * @param string $option_name Stored option or meta name.
     * @param bool   $site        Whether the name belongs to a site transient.
     * @return string Transient name, or '' when the name carries no matching prefix.
     */
    private function transient_name_from_option( $option_name, $site ) {
        $prefixes = $site ? array( '_site_transient_timeout_', '_site_transient_' ) : array( '_transient_timeout_', '_transient_' );
        foreach ( $prefixes as $prefix ) {
            if ( 0 === strpos( $option_name, $prefix ) ) {
                return substr( $option_name, strlen( $prefix ) );
            }
        }
        return '';
    }

    /**
     * Check an exact associative-key set.
     *
     * @param array $value Input object.
     * @param array $keys  Expected keys.
     * @return bool
     */
    private function abilities_v2_exact_keys( $value, $keys ) {
        $actual = array_keys( $value );
        sort( $actual, SORT_STRING );
        sort( $keys, SORT_STRING );

        return $actual === $keys;
    }

    /**
     * Build a closed abilities protocol error.
     *
     * @param string $operation Protocol operation.
     * @param string $code      Stable error code.
     * @return array<string,string|bool>
     */
    private function abilities_v2_error( $operation, $code ) {
        return array(
            'protocol'  => '2',
            'operation' => $operation,
            'ok'        => false,
            'code'      => $code,
        );
    }

    /**
     * Method maintenance_db()
     *
     * Child site database maintenance.
     *
     * @param array $maint_options An array containing selected maintenance options.
     * @param int   $max_revisions Maximum revisions to keep.
     *
     * @uses MainWP_Child_Maintenance::maintenance_get_revisions() Get child sites post revisions.
     * @uses MainWP_Child_Maintenance::maintenance_delete_revisions()
     * @uses MainWP_Child_Maintenance::maintenance_optimize()
     *
     * @uses get_terms() Retrieve the terms in a given taxonomy or list of taxonomies.
     * @see https://developer.wordpress.org/reference/functions/get_terms/
     *
     * @uses wp_delete_term() Removes a term from the database.
     * @see https://developer.wordpress.org/reference/functions/wp_delete_term/
     *
     * @used-by MainWP_Child_Maintenance::maintenance_site() Fire off Child Site maintenance action and get feedback.
     *
     * @return array An array containing action feedback.
     */
    private function maintenance_db( $maint_options, $max_revisions ) { //phpcs:ignore -- NOSONAR - complex.

        /**
         * WordPress Database instance.
         *
         * @global object $wpdb
         */
        global $wpdb;

        $performed_what = array();

        if ( in_array( 'revisions', $maint_options ) ) {
            if ( empty( $max_revisions ) ) {
                $sql_clean = "DELETE FROM $wpdb->posts WHERE post_type = 'revision'";
                $wpdb->query( $sql_clean ); // phpcs:ignore -- safe sql query. required to achieve desired results, pull request solutions appreciated.
                // to fix issue of meta_value short length.
                $performed_what[] = 'revisions'; // 'Posts revisions deleted'.
            } else {
                $results = $this->maintenance_get_revisions( $max_revisions );
                $this->maintenance_delete_revisions( $results, $max_revisions );
                $performed_what[] = 'revisions_max'; // 'Posts revisions deleted'.
            }
        }

        $maint_sqls = array(
            'autodraft'    => "DELETE FROM $wpdb->posts WHERE post_status = 'auto-draft'",
            'trashpost'    => "DELETE FROM $wpdb->posts WHERE post_status = 'trash'",
            'spam'         => "DELETE FROM $wpdb->comments WHERE comment_approved = 'spam'",
            'pending'      => "DELETE FROM $wpdb->comments WHERE comment_approved = '0'",
            'trashcomment' => "DELETE FROM $wpdb->comments WHERE comment_approved = 'trash'",
        );

        foreach ( $maint_sqls as $act => $sql_clean ) {
            if ( in_array( $act, $maint_options ) ) {
                $wpdb->query( $sql_clean ); // phpcs:ignore -- safe sql query. required to achieve desired results, pull request solutions appreciated.
                $performed_what[] = $act; // 'Auto draft posts deleted'.
            }
        }

        if ( in_array( 'tags', $maint_options ) ) {
            $post_tags = get_terms(
                array(
                    'taxonomy'   => 'category',
                    'hide_empty' => false,
                )
            );
            if ( is_array( $post_tags ) ) {
                foreach ( $post_tags as $tag ) {
                    if ( 0 === (int) $tag->count ) {
                        wp_delete_term( $tag->term_id, 'post_tag' );
                    }
                }
            }
            $performed_what[] = 'tags'; // 'Tags with 0 posts associated deleted'.
        }

        if ( in_array( 'categories', $maint_options ) ) {
            $post_cats = get_terms(
                array(
                    'taxonomy'   => 'category',
                    'hide_empty' => false,
                )
            );

            if ( is_array( $post_cats ) ) {
                foreach ( $post_cats as $cat ) {
                    if ( 0 === (int) $cat->count ) {
                        wp_delete_term( $cat->term_id, 'category' );
                    }
                }
            }
            $performed_what[] = 'categories'; // 'Categories with 0 posts associated deleted'.
        }

        if ( in_array( 'optimize', $maint_options ) ) {
            $this->maintenance_optimize();
            $performed_what[] = 'optimize'; // 'Database optimized'.
        }

        if ( in_array( 'transients_all', $maint_options ) ) {
            $this->maintenance_delete_all_transients();
            $performed_what[] = 'transients_all';
        } elseif ( in_array( 'transients_expired', $maint_options ) ) {
            $this->maintenance_delete_expired_transients();
            $performed_what[] = 'transients_expired';
        }

        if ( ! empty( $performed_what ) && has_action( 'mainwp_reports_maintenance' ) ) {
            $details  = implode( ',', $performed_what );
            $log_time = time();
            $message  = 'Maintenance Performed';
            $result   = 'Maintenance Performed';
            do_action( 'mainwp_reports_maintenance', $message, $log_time, $details, $result, $max_revisions );
        }

        return array( 'status' => 'SUCCESS' );
    }

    /**
     * Method maintenance_get_revisions()
     *
     * Get child sites post revisions.
     *
     * @param int $max_revisions Maximum revisions to keep.
     *
     * @uses wpdb::get_results() Retrieve an entire SQL result set from the database.
     * @see https://developer.wordpress.org/reference/classes/wpdb/get_results/
     *
     * @used-by MainWP_Child_Maintenance::maintenance_db() Child site database maintenance.
     *
     * @return array|object|null Database query results.
     */
    protected function maintenance_get_revisions( $max_revisions ) {

        /**
         * WordPress Database instance.
         *
         * @global object $wpdb
         */
        global $wpdb;

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Fetches transient revision data for immediate cleanup; not reused or cacheable.
        return $wpdb->get_results( $wpdb->prepare( " SELECT `post_parent`, COUNT(*) cnt FROM $wpdb->posts WHERE `post_type` = 'revision' GROUP BY `post_parent` HAVING COUNT(*) > %d ", $max_revisions ) );
    }

    /**
     * Method maintenance_delete_revisions()
     *
     * Delete child site post revisions.
     *
     * @param array|object $results       Database query results.
     * @param int          $max_revisions Maximum revisions to keep.
     *
     * @uses wpdb::get_results() Retrieve an entire SQL result set from the database.
     * @see https://developer.wordpress.org/reference/classes/wpdb/get_results/
     *
     * @used-by MainWP_Child_Maintenance::maintenance_db() Child site database maintenance.
     *
     * @return int Return number of revisions deleted.
     */
    private function maintenance_delete_revisions( $results, $max_revisions ) {

        /**
         * WordPress Database instance.
         *
         * @global object $wpdb
         */
        global $wpdb;

        if ( ! is_array( $results ) || empty( $results ) ) {
            return 0;
        }
        $count_deleted  = 0;
        $results_length = count( $results );
        for ( $i = 0; $i < $results_length; $i++ ) {
            $number_to_delete = $results[ $i ]->cnt - $max_revisions;
            $count_deleted   += $number_to_delete;
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Fetches specific revision IDs for immediate deletion; not reused or cacheable.
            $results_posts = $wpdb->get_results( $wpdb->prepare( "SELECT `ID`, `post_modified` FROM  $wpdb->posts WHERE `post_parent`= %d AND `post_type`='revision' ORDER BY `post_modified` ASC", $results[ $i ]->post_parent ) );
            $delete_ids    = array();
            if ( is_array( $results_posts ) && ! empty( $results_posts ) ) {
                for ( $j = 0; $j < $number_to_delete; $j++ ) {
                    $delete_ids[] = $results_posts[ $j ]->ID;
                }
            }

            if ( ! empty( $delete_ids ) ) {
                $sql_delete = " DELETE FROM $wpdb->posts WHERE `ID` IN (" . implode( ',', $delete_ids ) . ")"; // phpcs:ignore -- safe
                $wpdb->get_results( $sql_delete ); // phpcs:ignore -- safe
            }
        }

        return $count_deleted;
    }

    /**
     * Method maintenance_optimize()
     *
     * Optimize Child database.
     *
     * @uses MainWP_Child_DB::to_query() Get the size of the DB.
     * @uses MainWP_Child_DB::num_rows() Count the number of rows.
     * @uses MainWP_Child_DB::is_result() Check if $result is an Instantiated object of \mysqli.
     * @uses MainWP_Child_DB::fetch_array() Fetch an array.
     * @uses \MainWP\Child\MainWP_Child_DB::to_query()
     * @uses \MainWP\Child\MainWP_Child_DB::num_rows()
     * @uses \MainWP\Child\MainWP_Child_DB::fetch_array()
     *
     * @used-by MainWP_Child_Maintenance::maintenance_db() Child site database maintenance.
     */
    private function maintenance_optimize() {

        /**
         * Object, providing access to the WordPress database.
         *
         * @global object $wpdb WordPress Database instance.
         */
        global $wpdb;

        /**
         * WordPress DB table prefix.
         *
         * @global string $table_prefix WordPress DB table prefix.
         */
        global $table_prefix;

        $sql    = 'SHOW TABLE STATUS FROM `' . DB_NAME . '`';
        $result = MainWP_Child_DB::to_query( $sql, $wpdb->dbh );
        if ( MainWP_Child_DB::num_rows( $result ) && MainWP_Child_DB::is_result( $result ) ) {
            while ( $row = MainWP_Child_DB::fetch_array( $result ) ) {
                if ( strpos( $row['Name'], $table_prefix ) !== false ) {
                    $sql = 'OPTIMIZE TABLE ' . $row['Name'];
                    MainWP_Child_DB::to_query( $sql, $wpdb->dbh );
                }
            }
        }
    }

    /**
     * Method maintenance_delete_expired_transients()
     *
     * Delete expired transients and site transients.
     *
     * @used-by MainWP_Child_Maintenance::maintenance_db()
     */
    private function maintenance_delete_expired_transients() {

        /**
         * WordPress Database instance.
         *
         * @global object $wpdb
         */
        global $wpdb;

        $now = time();

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Fetches transient keys for cleanup; not reused or cacheable.
        $expired_transients = $wpdb->get_col(
            $wpdb->prepare(
                "SELECT option_name FROM $wpdb->options WHERE option_name LIKE %s AND option_value < %d",
                $wpdb->esc_like( '_transient_timeout_' ) . '%',
                $now
            )
        );

        if ( ! empty( $expired_transients ) ) {
            foreach ( $expired_transients as $option_name ) {
                $transient = $this->transient_name_from_option( $option_name, false );
                if ( '' !== $transient ) {
                    delete_transient( $transient );
                }
            }
        }

        if ( is_multisite() ) {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Fetches site transient keys for cleanup; not reused or cacheable.
            $expired_site_transients = $wpdb->get_col(
                $wpdb->prepare(
                    "SELECT meta_key FROM $wpdb->sitemeta WHERE meta_key LIKE %s AND meta_value < %d",
                    $wpdb->esc_like( '_site_transient_timeout_' ) . '%',
                    $now
                )
            );
        } else {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Fetches site transient keys for cleanup; not reused or cacheable.
            $expired_site_transients = $wpdb->get_col(
                $wpdb->prepare(
                    "SELECT option_name FROM $wpdb->options WHERE option_name LIKE %s AND option_value < %d",
                    $wpdb->esc_like( '_site_transient_timeout_' ) . '%',
                    $now
                )
            );
        }

        if ( ! empty( $expired_site_transients ) ) {
            foreach ( $expired_site_transients as $option_name ) {
                $transient = $this->transient_name_from_option( $option_name, true );
                if ( '' !== $transient ) {
                    delete_site_transient( $transient );
                }
            }
        }
    }

    /**
     * Method maintenance_delete_all_transients()
     *
     * Delete all transients and site transients.
     *
     * @used-by MainWP_Child_Maintenance::maintenance_db()
     */
    private function maintenance_delete_all_transients() { //phpcs:ignore --NOSONAR -complex.

        /**
         * WordPress Database instance.
         *
         * @global object $wpdb
         */
        global $wpdb;

        $transients = array();

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Fetches transient keys for cleanup; not reused or cacheable.
        $transient_names = $wpdb->get_col(
            $wpdb->prepare(
                "SELECT option_name FROM $wpdb->options WHERE option_name LIKE %s",
                $wpdb->esc_like( '_transient_' ) . '%'
            )
        );

        if ( ! empty( $transient_names ) ) {
            foreach ( $transient_names as $option_name ) {
                $transients[] = $this->transient_name_from_option( $option_name, false );
            }
        }

        $transients = array_unique( array_filter( $transients ) );
        foreach ( $transients as $transient ) {
            delete_transient( $transient );
        }

        $site_transients = array();

        if ( is_multisite() ) {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Fetches site transient keys for cleanup; not reused or cacheable.
            $site_names = $wpdb->get_col(
                $wpdb->prepare(
                    "SELECT meta_key FROM $wpdb->sitemeta WHERE meta_key LIKE %s",
                    $wpdb->esc_like( '_site_transient_' ) . '%'
                )
            );
        } else {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Fetches site transient keys for cleanup; not reused or cacheable.
            $site_names = $wpdb->get_col(
                $wpdb->prepare(
                    "SELECT option_name FROM $wpdb->options WHERE option_name LIKE %s",
                    $wpdb->esc_like( '_site_transient_' ) . '%'
                )
            );
        }

        if ( ! empty( $site_names ) ) {
            foreach ( $site_names as $option_name ) {
                $site_transients[] = $this->transient_name_from_option( $option_name, true );
            }
        }

        $site_transients = array_unique( array_filter( $site_transients ) );
        foreach ( $site_transients as $transient ) {
            delete_site_transient( $transient );
        }
    }

    /**
     * Method maintenance_action()
     *
     * Triggers action to perform, save_settings, enable_alert or clear_settings.
     *
     * @param string $action Action to perform.
     *
     * @uses delete_option() Removes option by name. Prevents removal of protected WordPress options.
     * @see https://developer.wordpress.org/reference/functions/delete_option/
     *
     * @uses \MainWP\Child\MainWP_Helper::write() Write response data to be sent to the MainWP Dashboard.
     * @uses \MainWP\Child\MainWP_Helper::update_option() Update option by name.
     *
     * @used-by \MainWP\Child\MainWP_Child_Maintenance::maintenance_site() Fire off Child Site maintenance action and get feedback.
     */
    private function maintenance_action( $action ) {
        $information = array();
        // phpcs:disable WordPress.Security.NonceVerification
        if ( 'save_settings' === $action ) {
            if ( isset( $_POST['enable_alert'] ) && '1' === $_POST['enable_alert'] ) {
                MainWP_Helper::update_option( 'mainwp_maintenance_opt_alert_404', 1, 'yes' );
            } else {
                delete_option( 'mainwp_maintenance_opt_alert_404' );
            }
            $email = ! empty( $_POST['email'] ) ? sanitize_email( wp_unslash( $_POST['email'] ) ) : '';
            if ( ! empty( $email ) ) {
                MainWP_Helper::update_option( 'mainwp_maintenance_opt_alert_404_email', $email, 'yes' );
            } else {
                delete_option( 'mainwp_maintenance_opt_alert_404_email' );
            }
            $information['result'] = 'SUCCESS';
            MainWP_Helper::write( $information );

            return;
        } elseif ( 'clear_settings' === $action ) {
            delete_option( 'mainwp_maintenance_opt_alert_404' );
            delete_option( 'mainwp_maintenance_opt_alert_404_email' );
            $information['result'] = 'SUCCESS';
            MainWP_Helper::write( $information );
        }
        // phpcs:enable
        MainWP_Helper::write( $information );
    }
}
