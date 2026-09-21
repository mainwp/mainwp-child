<?php
/**
 * MainWP Child Comments
 *
 * This file handles all Child Site comment actions.
 *
 * @package MainWP\Child
 */

namespace MainWP\Child;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Class MainWP_Child_Comments
 *
 * Handles all Child Site comment actions.
 */
class MainWP_Child_Comments {

    /**
     * Private holding value a v2 mutation swaps a comment into to claim it.
     *
     * @var string
     */
    private const CLAIM_STATUS = 'mainwp-claim';

    /**
     * Comment meta key holding the status a live claim swapped out, and when it did.
     *
     * @var string
     */
    private const CLAIM_META = '_mainwp_v2_claim';

    /**
     * Seconds a claim is left alone before it counts as abandoned rather than in progress.
     *
     * @var int
     */
    private const CLAIM_GRACE = 300;

    /**
     * Public static variable to hold the single instance of the class.
     *
     * @var mixed Default null
     */
    protected static $instance = null;

    /**
     * Comments and clauses.
     *
     * @var string Comments and clauses.
     */
    private $comments_and_clauses;

    /**
     * Get Class Name.
     *
     * @return string
     */
    public static function get_class_name() {
        return __CLASS__;
    }

    /**
     * MainWP_Child_Comments constructor.
     *
     * Run any time class is called.
     */
    public function __construct() {
        $this->comments_and_clauses = '';
    }

    /**
     * Create a public static instance of ainWP_Child_Comments.
     *
     * @return MainWP_Child_Comments|null
     */
    public static function get_instance() {
        if ( null === static::$instance ) {
            static::$instance = new self();
        }
        return static::$instance;
    }

    /**
     * MainWP Child Comment actions: approve, unapprove, spam, unspam, trash, restore, delete.
     *
     * @uses \MainWP\Child\MainWP_Child_Links_Checker::get_class_name()
     * @uses \MainWP\Child\MainWP_Helper::write()
     */
    public function comment_action() {
        $action = MainWP_System::instance()->validate_params( 'action' );
        // phpcs:disable WordPress.Security.NonceVerification
        $commentId = isset( $_POST['id'] ) ? sanitize_text_field( wp_unslash( $_POST['id'] ) ) : '';

        if ( 'approve' === $action ) {
            wp_set_comment_status( $commentId, 'approve' );
        } elseif ( 'unapprove' === $action ) {
            wp_set_comment_status( $commentId, 'hold' );
        } elseif ( 'spam' === $action ) {
            wp_spam_comment( $commentId );
        } elseif ( 'unspam' === $action ) {
            wp_unspam_comment( $commentId );
        } elseif ( 'trash' === $action ) {
            add_action( 'trashed_comment', array( MainWP_Child_Links_Checker::get_class_name(), 'hook_trashed_comment' ), 10, 1 );
            wp_trash_comment( $commentId );
        } elseif ( 'restore' === $action ) {
            wp_untrash_comment( $commentId );
        } elseif ( 'delete' === $action ) {
            wp_delete_comment( $commentId, true );
        } else {
            $information['status'] = 'FAIL';
        }

        if ( ! isset( $information['status'] ) ) {
            $information['status'] = 'SUCCESS';
        }
        // phpcs:enable
        MainWP_Helper::write( $information );
    }

    /**
     * MainWP Child Bulk Comment actions: approve, unapprove, spam, unspam, trash, restore, delete.
     *
     * @uses \MainWP\Child\MainWP_Helper::write()
     */
    public function comment_bulk_action() {
        if ( $this->has_contract_selector() ) {
            if ( $this->is_v2_request() ) {
                $this->comment_bulk_action_v2();
            } else {
                MainWP_Helper::write( $this->v2_error( 'unknown' ) );
            }
            return;
        }

        $action = MainWP_System::instance()->validate_params( 'action' );
        // phpcs:disable WordPress.Security.NonceVerification
        $commentIds = isset( $_POST['ids'] ) ? explode( ',', sanitize_text_field( wp_unslash( $_POST['ids'] ) ) ) : array();
        // phpcs:enable
        $information['success'] = 0;
        foreach ( $commentIds as $commentId ) {
            if ( $commentId ) {
                ++$information['success'];
                if ( 'approve' === $action ) {
                    wp_set_comment_status( $commentId, 'approve' );
                } elseif ( 'unapprove' === $action ) {
                    wp_set_comment_status( $commentId, 'hold' );
                } elseif ( 'spam' === $action ) {
                    wp_spam_comment( $commentId );
                } elseif ( 'unspam' === $action ) {
                    wp_unspam_comment( $commentId );
                } elseif ( 'trash' === $action ) {
                    wp_trash_comment( $commentId );
                } elseif ( 'restore' === $action ) {
                    wp_untrash_comment( $commentId );
                } elseif ( 'delete' === $action ) {
                    wp_delete_comment( $commentId, true );
                } else {
                    --$information['success'];
                }
            }
        }
        MainWP_Helper::write( $information );
    }

    /**
     * Comment WHERE Clauses.
     *
     * @param array $clauses MySQL WHERE Clause.
     *
     * @return array $clauses, Array of MySQL WHERE Clauses.
     */
    public function comments_clauses( $clauses ) {
        if ( $this->comments_and_clauses ) {
            $clauses['where'] .= ' ' . $this->comments_and_clauses;
        }

        return $clauses;
    }

    /**
     * Get all comments.
     *
     * @uses \MainWP\Child\MainWP_Helper::write()
     */
    public function get_all_comments() { //phpcs:ignore -- NOSONAR - complex.

        if ( $this->has_contract_selector() ) {
            if ( $this->is_v2_request() ) {
                $this->get_all_comments_v2();
            } else {
                MainWP_Helper::write( $this->v2_error( 'get_comment' ) );
            }
            return;
        }

        /**
         * WordPress Database instance.
         *
         * @global object $wpdb
         */
        global $wpdb;

        add_filter( 'comments_clauses', array( &$this, 'comments_clauses' ) );
        // phpcs:disable WordPress.Security.NonceVerification
        if ( isset( $_POST['postId'] ) ) {
            $this->comments_and_clauses .= $wpdb->prepare( " AND $wpdb->comments.comment_post_ID = %d ", sanitize_text_field( wp_unslash( $_POST['postId'] ) ) );
        } else {
            if ( isset( $_POST['keyword'] ) && '' !== $_POST['keyword'] ) {
                $this->comments_and_clauses .= $wpdb->prepare( " AND $wpdb->comments.comment_content LIKE %s ", '%' . $wpdb->esc_like( sanitize_text_field( wp_unslash( $_POST['keyword'] ) ) ) . '%' );
            }
            if ( isset( $_POST['dtsstart'] ) && '' !== $_POST['dtsstart'] ) {
                $this->comments_and_clauses .= $wpdb->prepare( " AND $wpdb->comments.comment_date > %s ", $wpdb->esc_like( sanitize_text_field( wp_unslash( $_POST['dtsstart'] ) ) ) );
            }
            if ( isset( $_POST['dtsstop'] ) && '' !== $_POST['dtsstop'] ) {
                $this->comments_and_clauses .= $wpdb->prepare( " AND $wpdb->comments.comment_date < %s ", $wpdb->esc_like( sanitize_text_field( wp_unslash( $_POST['dtsstop'] ) ) ) );
            }
        }

        $maxComments = 50;
        if ( defined( 'MAINWP_CHILD_NR_OF_COMMENTS' ) ) {
            $maxComments = MAINWP_CHILD_NR_OF_COMMENTS; // to compatible.
        }

        if ( isset( $_POST['maxRecords'] ) ) {
            $maxComments = ! empty( $_POST['maxRecords'] ) ? intval( $_POST['maxRecords'] ) : 0;
        }

        if ( 0 === $maxComments ) {
            $maxComments = 99999;
        }
        $status                     = isset( $_POST['status'] ) ? sanitize_text_field( wp_unslash( $_POST['status'] ) ) : '';
        $rslt                       = $this->get_recent_comments( explode( ',', $status ), $maxComments );
        $this->comments_and_clauses = '';
        // phpcs:enable
        MainWP_Helper::write( $rslt );
    }

    /**
     * Whether the request explicitly selects the additive Comments v2 contract.
     *
     * @return bool
     */
    private function is_v2_request() {
        // phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Authenticated MainWP Child callable; the value is only strict-compared to '2', never stored or output.
        return isset( $_POST['contract_version'] ) && '2' === (string) wp_unslash( $_POST['contract_version'] );
    }

    /**
     * Whether the request carries a contract version selector at all.
     *
     * @return bool
     */
    private function has_contract_selector() {
        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Authenticated MainWP Child callable.
        return array_key_exists( 'contract_version', $_POST );
    }

    /**
     * Return one exact, redacted comment through the v2 read contract.
     *
     * @return void
     */
    private function get_all_comments_v2() {
        $request = $this->decode_v2_request( array( 'operation', 'comment_id' ) );
        MainWP_Helper::write( false === $request ? $this->v2_error( 'get_comment' ) : $this->comments_v2( 'get_comment', $request ) );
    }

    /**
     * Apply or preview a strictly validated v2 comment batch.
     *
     * @return void
     */
    private function comment_bulk_action_v2() {
        $request = $this->decode_v2_request();
        MainWP_Helper::write( false === $request ? $this->v2_error( 'unknown' ) : $this->comments_v2( isset( $request['operation'] ) ? $request['operation'] : 'unknown', $request ) );
    }

    /**
     * Process a decoded Comments v2 request without transport side effects.
     *
     * @param string $operation Closed operation name.
     * @param array  $request Decoded request.
     * @return array
     */
    public function comments_v2( $operation, $request ) {
        if ( ! is_string( $operation ) || ! is_array( $request ) ) {
            return $this->v2_error( 'unknown' );
        }
        if ( 'get_comment' === $operation ) {
            return $this->get_comment_v2_result( $request );
        }
        if ( 'moderate' === $operation ) {
            return $this->moderate_comments_v2( $request );
        }
        if ( 'delete_permanently' === $operation ) {
            return $this->delete_comments_v2( $request );
        }
        return $this->v2_error( 'unknown' );
    }

    /**
     * Build the v2 get_comment response for a validated request.
     *
     * @param array $request Decoded request.
     * @return array
     */
    private function get_comment_v2_result( $request ) {
        if ( ! $this->has_exact_keys( $request, array( 'operation', 'comment_id' ) ) || 'get_comment' !== $request['operation'] ) {
            return $this->v2_error( 'get_comment' );
        }
        $comment_id = $this->canonical_positive_integer( $request['comment_id'] );
        if ( false === $comment_id ) {
            return $this->v2_error( 'get_comment' );
        }
        $comment = $this->load_v2_comment( $comment_id );
        if ( ! $comment ) {
            return array(
                'contract_version' => 2,
                'operation'        => 'get_comment',
                'found'            => false,
                'comment'          => null,
            );
        }
        $projected = $this->project_v2_comment( $comment );
        return false === $projected ? $this->v2_error( 'get_comment' ) : array(
            'contract_version' => 2,
            'operation'        => 'get_comment',
            'found'            => true,
            'comment'          => $projected,
        );
    }

    /**
     * Apply a reversible, expected-state moderation batch.
     *
     * @param array $request Decoded request.
     * @return array
     */
    private function moderate_comments_v2( $request ) {
        if ( ! $this->has_exact_keys( $request, array( 'operation', 'action', 'items' ) ) || 'moderate' !== $request['operation'] || ! is_string( $request['action'] ) || ! $this->valid_v2_items( $request['items'] ) ) {
            return $this->v2_error( 'moderate' );
        }

        $transitions = array(
            'approve'   => array( 'pending' ),
            'unapprove' => array( 'approved' ),
            'spam'      => array( 'approved', 'pending' ),
            'unspam'    => array( 'spam' ),
            'trash'     => array( 'approved', 'pending', 'spam' ),
            'restore'   => array( 'trash' ),
        );
        if ( ! isset( $transitions[ $request['action'] ] ) ) {
            return $this->v2_error( 'moderate' );
        }
        foreach ( $request['items'] as $item ) {
            if ( ! in_array( $item['expected_status'], $transitions[ $request['action'] ], true ) ) {
                return $this->v2_error( 'moderate' );
            }
        }

        $items   = $this->sort_v2_items( $request['items'] );
        $results = array();
        $applied = 0;
        foreach ( $items as $item ) {
            $comment = $this->load_v2_comment( $item['comment_id'] );
            if ( ! $comment ) {
                $results[] = $this->moderation_result( $item['comment_id'], 'not_found', null, null, 'comment_not_found', 'The comment was not found.' );
                continue;
            }
            $before = $this->canonical_comment_status( wp_get_comment_status( $comment ) );
            if ( false === $before ) {
                $results[] = $this->moderation_result( $item['comment_id'], 'failed', null, null, 'status_unavailable', 'The comment status could not be verified.' );
                continue;
            }
            if ( $before !== $item['expected_status'] ) {
                $results[] = $this->moderation_result( $item['comment_id'], 'conflict', $before, $before, 'status_conflict', 'The comment status changed before moderation.' );
                continue;
            }

            $claimed = $this->claim_v2_comment( $comment );
            if ( null === $claimed ) {
                $results[] = $this->moderation_result( $item['comment_id'], 'failed', $before, null, 'status_unavailable', 'The comment status could not be verified.' );
                continue;
            }
            if ( false === $claimed ) {
                $current   = $this->current_v2_status( $item['comment_id'] );
                $results[] = $this->moderation_result( $item['comment_id'], 'conflict', $current, $current, 'status_conflict', 'The comment status changed before moderation.' );
                continue;
            }

            // The claim guarded the swap, not the WordPress transition that follows it. Core updates
            // the row by ID alone, so anything that moved the comment since the claim would be
            // overwritten rather than seen. Confirming the claim is still held immediately before the
            // call refuses that instead.
            $owned = $this->holds_v2_claim( $item['comment_id'] );
            if ( null === $owned ) {
                $this->release_v2_claim( $comment );
                $results[] = $this->moderation_result( $item['comment_id'], 'failed', $before, $this->current_v2_status( $item['comment_id'] ), 'status_unavailable', 'The comment status could not be verified.' );
                continue;
            }
            if ( ! $owned ) {
                $this->release_v2_claim( $comment );
                $current   = $this->current_v2_status( $item['comment_id'] );
                $results[] = $this->moderation_result( $item['comment_id'], 'conflict', $current, $current, 'status_conflict', 'The comment status changed before moderation.' );
                continue;
            }

            $changed = $this->apply_v2_moderation( $request['action'], $comment );
            $after   = $this->current_v2_status( $item['comment_id'] );
            if ( true === $changed && $this->moderation_post_state_matches( $request['action'], $after ) ) {
                delete_comment_meta( $item['comment_id'], self::CLAIM_META );
                ++$applied;
                $results[] = $this->moderation_result( $item['comment_id'], 'applied', $before, $after );
                continue;
            }
            $this->release_v2_claim( $comment );
            $results[] = $this->moderation_result( $item['comment_id'], 'failed', $before, $this->current_v2_status( $item['comment_id'] ), 'mutation_failed', 'The moderation result could not be verified.' );
        }

        return array(
            'contract_version' => 2,
            'operation'        => 'moderate',
            'action'           => $request['action'],
            'requested'        => count( $items ),
            'applied'          => $applied,
            'results'          => $results,
        );
    }

    /**
     * Preview or execute a trash-only permanent deletion batch.
     *
     * @param array $request Decoded request.
     * @return array
     */
    private function delete_comments_v2( $request ) {
        if ( ! $this->has_exact_keys( $request, array( 'operation', 'dry_run', 'items' ) ) || 'delete_permanently' !== $request['operation'] || ! is_bool( $request['dry_run'] ) || ! $this->valid_v2_items( $request['items'] ) ) {
            return $this->v2_error( 'delete_permanently' );
        }
        foreach ( $request['items'] as $item ) {
            if ( 'trash' !== $item['expected_status'] ) {
                return $this->v2_error( 'delete_permanently' );
            }
        }

        $items    = $this->sort_v2_items( $request['items'] );
        $results  = array();
        $eligible = 0;
        $deleted  = 0;
        foreach ( $items as $item ) {
            $comment = $this->load_v2_comment( $item['comment_id'] );
            if ( ! $comment ) {
                $results[] = $this->deletion_result( $item['comment_id'], 'not_found', null, false, 'comment_not_found', 'The comment was not found.' );
                continue;
            }
            $before = $this->canonical_comment_status( wp_get_comment_status( $comment ) );
            if ( 'trash' !== $before ) {
                $results[] = $this->deletion_result( $item['comment_id'], 'conflict', false === $before ? null : $before, true, 'status_conflict', 'The comment is not in trash.' );
                continue;
            }

            if ( $request['dry_run'] ) {
                ++$eligible;
                $results[] = $this->deletion_result( $item['comment_id'], 'would_delete', $before, true );
                continue;
            }

            $claimed = $this->claim_v2_comment( $comment );
            if ( null === $claimed ) {
                $results[] = $this->deletion_result( $item['comment_id'], 'failed', $before, true, 'delete_failed', 'Permanent deletion could not be verified.' );
                continue;
            }
            if ( false === $claimed ) {
                $current   = $this->current_v2_status( $item['comment_id'] );
                $results[] = $this->deletion_result( $item['comment_id'], 'conflict', $current, (bool) get_comment( $item['comment_id'] ), 'status_conflict', 'The comment is not in trash.' );
                continue;
            }

            // A forced delete removes the row whatever it now holds, so losing the claim between the
            // swap and this call would destroy a comment another actor had just put back. Nothing is
            // deleted unless this request still owns the claim it took.
            $owned = $this->holds_v2_claim( $item['comment_id'] );
            if ( null === $owned ) {
                $this->release_v2_claim( $comment );
                $results[] = $this->deletion_result( $item['comment_id'], 'failed', $before, (bool) get_comment( $item['comment_id'] ), 'delete_failed', 'Permanent deletion could not be verified.' );
                continue;
            }
            if ( ! $owned ) {
                $this->release_v2_claim( $comment );
                $results[] = $this->deletion_result( $item['comment_id'], 'conflict', $this->current_v2_status( $item['comment_id'] ), (bool) get_comment( $item['comment_id'] ), 'status_conflict', 'The comment is not in trash.' );
                continue;
            }

            ++$eligible;
            $removed      = wp_delete_comment( $comment, true );
            $exists_after = (bool) get_comment( $item['comment_id'] );
            if ( true === $removed && ! $exists_after ) {
                ++$deleted;
                $results[] = $this->deletion_result( $item['comment_id'], 'deleted', $before, false );
                continue;
            }
            $this->release_v2_claim( $comment );
            $results[] = $this->deletion_result( $item['comment_id'], 'failed', $before, (bool) get_comment( $item['comment_id'] ), 'delete_failed', 'Permanent deletion could not be verified.' );
        }

        return array(
            'contract_version' => 2,
            'operation'        => 'delete_permanently',
            'dry_run'          => $request['dry_run'],
            'requested'        => count( $items ),
            'eligible'         => $eligible,
            'deleted'          => $deleted,
            'results'          => $results,
        );
    }

    /**
     * Decode a closed JSON request from the authenticated Child callable.
     *
     * @param array|null $keys Optional exact keys.
     * @return array|false
     */
    private function decode_v2_request( $keys = null ) {
        // phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Authenticated MainWP Child callable; JSON body is length-capped and strictly validated by json_decode below, sanitizing would corrupt it.
        $raw = isset( $_POST['request'] ) && is_string( $_POST['request'] ) ? wp_unslash( $_POST['request'] ) : '';
        if ( '' === $raw || strlen( $raw ) > 65535 ) {
            return false;
        }
        $request = json_decode( $raw, true );
        if ( ! is_array( $request ) || JSON_ERROR_NONE !== json_last_error() || $this->is_list( $request ) || ( null !== $keys && ! $this->has_exact_keys( $request, $keys ) ) ) {
            return false;
        }
        return $request;
    }

    /**
     * Validate a bounded unique list of exact item records.
     *
     * @param mixed $items Candidate items.
     * @return bool
     */
    private function valid_v2_items( $items ) {
        if ( ! is_array( $items ) || ! $this->is_list( $items ) || empty( $items ) || count( $items ) > 100 ) {
            return false;
        }
        $seen = array();
        foreach ( $items as $item ) {
            if ( ! is_array( $item ) || ! $this->has_exact_keys( $item, array( 'comment_id', 'expected_status' ) ) || ! is_string( $item['expected_status'] ) ) {
                return false;
            }
            $comment_id = $this->canonical_positive_integer( $item['comment_id'] );
            if ( false === $comment_id || isset( $seen[ $comment_id ] ) ) {
                return false;
            }
            $seen[ $comment_id ] = true;
        }
        return true;
    }

    /**
     * Order batch items by comment id and cast the ids to integers.
     *
     * @param array $items Validated batch items.
     * @return array
     */
    private function sort_v2_items( $items ) {
        usort(
            $items,
            static function ( $left, $right ) {
                return (int) $left['comment_id'] <=> (int) $right['comment_id'];
            }
        );
        foreach ( $items as &$item ) {
            $item['comment_id'] = (int) $item['comment_id'];
        }
        unset( $item );
        return $items;
    }

    /**
     * Whether an array holds exactly the given keys, no more and no fewer.
     *
     * @param mixed $value Value to inspect.
     * @param array $keys  Required keys.
     * @return bool
     */
    private function has_exact_keys( $value, $keys ) {
        if ( ! is_array( $value ) || count( $value ) !== count( $keys ) ) {
            return false;
        }
        $actual = array_keys( $value );
        sort( $actual );
        sort( $keys );
        return $actual === $keys;
    }

    /**
     * Whether an array is a zero-indexed sequential list.
     *
     * @param array $value Array to inspect.
     * @return bool
     */
    private function is_list( $value ) {
        return empty( $value ) || array_keys( $value ) === range( 0, count( $value ) - 1 );
    }

    /**
     * Read a value as a positive integer, rejecting any non-canonical form.
     *
     * @param mixed $value Value to read.
     * @return int|false
     */
    private function canonical_positive_integer( $value ) {
        if ( is_int( $value ) ) {
            return 0 < $value ? $value : false;
        }
        if ( ! is_string( $value ) || ! preg_match( '/^[1-9][0-9]*$/D', $value ) || strlen( $value ) > strlen( (string) PHP_INT_MAX ) || ( strlen( $value ) === strlen( (string) PHP_INT_MAX ) && $value > (string) PHP_INT_MAX ) ) {
            return false;
        }
        return (int) $value;
    }

    /**
     * Map a WordPress comment status onto the closed v2 status set.
     *
     * @param string $status WordPress comment status.
     * @return string|false
     */
    private function canonical_comment_status( $status ) {
        if ( 'unapproved' === $status || '0' === $status ) {
            return 'pending';
        }
        if ( '1' === $status ) {
            return 'approved';
        }
        return in_array( $status, array( 'approved', 'pending', 'spam', 'trash', 'post-trashed' ), true ) ? $status : false;
    }

    /**
     * Project a comment onto the v2 read shape, or fail if any field is unusable.
     *
     * @param \WP_Comment $comment Comment to project.
     * @return array|false
     */
    private function project_v2_comment( $comment ) {
        $status    = $this->canonical_comment_status( wp_get_comment_status( $comment ) );
        $post      = get_post( $comment->comment_post_ID );
        $timestamp = strtotime( $comment->comment_date_gmt );
        if ( false === $status || ! $post || 1 > (int) $comment->comment_ID || 1 > (int) $comment->comment_post_ID || false === $timestamp || 1 > $timestamp ) {
            return false;
        }
        $author  = $this->bounded_v2_text( $comment->comment_author, 200 );
        $content = $this->bounded_v2_text( $comment->comment_content, 10000 );
        $title   = $this->bounded_v2_text( $post->post_title, 500 );
        if ( false === $author || false === $content || false === $title ) {
            return false;
        }
        return array(
            'comment_id'      => (int) $comment->comment_ID,
            'comment_author'  => $author,
            'comment_content' => $content,
            'comment_date'    => $timestamp,
            'comment_status'  => $status,
            'post_id'         => (int) $comment->comment_post_ID,
            'post_title'      => $title,
        );
    }

    /**
     * Accept a UTF-8 string no longer than the given character limit.
     *
     * @param mixed $value   Value to check.
     * @param int   $maximum Maximum length in characters.
     * @return string|false
     */
    private function bounded_v2_text( $value, $maximum ) {
        if ( ! is_string( $value ) || ( '' !== $value && '' === wp_check_invalid_utf8( $value ) ) ) {
            return false;
        }
        $length = function_exists( 'mb_strlen' ) ? mb_strlen( $value, 'UTF-8' ) : strlen( $value );
        return $length <= $maximum ? $value : false;
    }

    /**
     * Claim a comment for mutation, but only while it still holds the observed status.
     *
     * WordPress has no conditional comment update, so the precondition is enforced by swapping the
     * row out of its observed status in a single guarded statement. Whoever wins the swap owns the
     * transition; every other actor now sees a status that no longer matches what it read. The swap
     * does not keep the row still afterwards, so holds_v2_claim() re-reads it right before core is
     * called. The WordPress call that follows is handed the pre-claim comment, so core still performs
     * the real transition, its trash metadata and its hooks with the correct prior status.
     *
     * @param \WP_Comment $comment Comment read during the precondition check.
     * @return bool|null True when claimed, false when the status already moved, null when the write could not be run.
     */
    private function claim_v2_comment( $comment ) {
        global $wpdb;
        if ( ! is_object( $wpdb ) || ! is_callable( array( $wpdb, 'update' ) ) ) {
            return null;
        }
        $claimed = $wpdb->update( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Compare-and-swap on the comment row is the precondition; no comment API exposes it.
            $wpdb->comments,
            array( 'comment_approved' => self::CLAIM_STATUS ),
            array(
                'comment_ID'       => (int) $comment->comment_ID,
                'comment_approved' => (string) $comment->comment_approved,
            ),
            array( '%s' ),
            array( '%d', '%s' )
        );
        if ( false === $claimed ) {
            return null;
        }
        if ( 1 !== (int) $claimed ) {
            return false;
        }
        // The swap overwrote the only copy of the status it replaced, so the claim leaves recover_v2_claim()
        // one, along with the moment it was taken. A fatal between the swap and this write still strands the
        // row, but that gap is one statement wide against the whole WordPress transition that follows it.
        update_comment_meta( (int) $comment->comment_ID, self::CLAIM_META, (string) $comment->comment_approved . '|' . time() );
        return true;
    }

    /**
     * Whether the comment row still holds the claim this request took.
     *
     * The claim only guards the swap into the holding value. Every WordPress transition and the
     * forced delete address the row by ID alone, so a competing write landing after the claim would
     * be silently overwritten by the call this check precedes. Reading the row back immediately
     * before that call narrows the exposure to core's own read-modify-write; the remaining gap
     * between this read and core's statement cannot be closed without reimplementing the transition.
     *
     * A row that has moved on, including one that no longer exists, is a claim this request no longer
     * owns rather than a storage failure, so only an unusable $wpdb is reported as unverifiable.
     *
     * @param int $comment_id Comment ID.
     * @return bool|null True while the claim is held, false once it is not, null when it could not be read.
     */
    private function holds_v2_claim( $comment_id ) {
        global $wpdb;
        if ( ! is_object( $wpdb ) || ! is_callable( array( $wpdb, 'get_var' ) ) || ! is_callable( array( $wpdb, 'prepare' ) ) ) {
            return null;
        }
        $raw = $wpdb->get_var( $wpdb->prepare( "SELECT comment_approved FROM {$wpdb->comments} WHERE comment_ID = %d", $comment_id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- The cached comment cannot answer whether the row still holds the claim.
        return null !== $raw && self::CLAIM_STATUS === (string) $raw;
    }

    /**
     * Put a comment back where a claim left it when the request holding that claim never returned.
     *
     * A fatal between the claim and the transition leaves the row at CLAIM_STATUS, which is not a status
     * wp-admin lists, so the comment drops out of the site owner's view with nothing left to put it back.
     * Recovery is gated on the claim being older than any request could still be running, so a claim that
     * is still being worked is never taken from its owner, and the restore is itself a compare-and-swap on
     * CLAIM_STATUS, so a mutation that did complete can never be undone by it.
     *
     * @param int $comment_id Comment holding the stranded claim.
     * @return bool Whether the comment was restored.
     */
    private function recover_v2_claim( $comment_id ) {
        global $wpdb;
        $marker = get_comment_meta( $comment_id, self::CLAIM_META, true );
        if ( ! is_string( $marker ) || 1 !== preg_match( '/^([^|]+)\|([1-9][0-9]*)$/D', $marker, $parts ) ) {
            return false;
        }
        // Only the raw values a claim can ever have swapped out are restorable; anything else is not
        // something this class wrote, and guessing a status is worse than leaving the row alone.
        if ( ! in_array( $parts[1], array( '0', '1', 'spam', 'trash' ), true ) || time() - (int) $parts[2] < self::CLAIM_GRACE ) {
            return false;
        }
        if ( ! is_object( $wpdb ) || ! is_callable( array( $wpdb, 'update' ) ) ) {
            return false;
        }
        $restored = $wpdb->update( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Compare-and-swap back out of the holding value written by claim_v2_comment().
            $wpdb->comments,
            array( 'comment_approved' => $parts[1] ),
            array(
                'comment_ID'       => $comment_id,
                'comment_approved' => self::CLAIM_STATUS,
            ),
            array( '%s' ),
            array( '%d', '%s' )
        );
        if ( false === $restored ) {
            return false;
        }
        delete_comment_meta( $comment_id, self::CLAIM_META );
        clean_comment_cache( $comment_id );
        return 1 === (int) $restored;
    }

    /**
     * Read a comment for a v2 operation, recovering an abandoned claim before anything reads its status.
     *
     * @param int $comment_id Comment ID.
     * @return \WP_Comment|null
     */
    private function load_v2_comment( $comment_id ) {
        $comment = get_comment( $comment_id );
        if ( $comment && self::CLAIM_STATUS === (string) $comment->comment_approved && $this->recover_v2_claim( $comment_id ) ) {
            $comment = get_comment( $comment_id );
        }
        return $comment;
    }

    /**
     * Put a claimed comment back the way it was found after the mutation did not happen.
     *
     * @param \WP_Comment $comment Comment read during the precondition check.
     * @return void
     */
    private function release_v2_claim( $comment ) {
        global $wpdb;
        if ( is_object( $wpdb ) && is_callable( array( $wpdb, 'update' ) ) ) {
            $wpdb->update( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Releases the compare-and-swap made by claim_v2_comment().
                $wpdb->comments,
                array( 'comment_approved' => (string) $comment->comment_approved ),
                array(
                    'comment_ID'       => (int) $comment->comment_ID,
                    'comment_approved' => self::CLAIM_STATUS,
                ),
                array( '%s' ),
                array( '%d', '%s' )
            );
        }
        delete_comment_meta( (int) $comment->comment_ID, self::CLAIM_META );
        clean_comment_cache( (int) $comment->comment_ID );
    }

    /**
     * Read a comment's current status straight from storage, past any cached copy.
     *
     * @param int $comment_id Comment ID.
     * @return string|null
     */
    private function current_v2_status( $comment_id ) {
        clean_comment_cache( $comment_id );
        $comment = get_comment( $comment_id );
        if ( ! $comment ) {
            return null;
        }
        $status = $this->canonical_comment_status( wp_get_comment_status( $comment ) );
        return false === $status ? null : $status;
    }

    /**
     * Run one moderation action against a claimed comment.
     *
     * @param string      $action  Moderation action name.
     * @param \WP_Comment $comment Comment as it was read before the claim.
     * @return bool
     */
    private function apply_v2_moderation( $action, $comment ) {
        if ( 'approve' === $action ) {
            return true === wp_set_comment_status( $comment, 'approve' );
        }
        if ( 'unapprove' === $action ) {
            return true === wp_set_comment_status( $comment, 'hold' );
        }
        if ( 'spam' === $action ) {
            return true === wp_spam_comment( $comment );
        }
        if ( 'unspam' === $action ) {
            return true === wp_unspam_comment( $comment );
        }
        if ( 'trash' === $action ) {
            return true === wp_trash_comment( $comment );
        }
        return true === wp_untrash_comment( $comment );
    }

    /**
     * Whether the status after a moderation action is one the action can produce.
     *
     * @param string $action Moderation action name.
     * @param string $status Status observed after the action.
     * @return bool
     */
    private function moderation_post_state_matches( $action, $status ) {
        $expected = array(
            'approve'   => array( 'approved' ),
            'unapprove' => array( 'pending' ),
            'spam'      => array( 'spam' ),
            'unspam'    => array( 'approved', 'pending' ),
            'trash'     => array( 'trash' ),
            'restore'   => array( 'approved', 'pending', 'spam' ),
        );
        return isset( $expected[ $action ] ) && in_array( $status, $expected[ $action ], true );
    }

    /**
     * Build one per-comment moderation result row.
     *
     * @param int    $comment_id Comment ID.
     * @param string $outcome    Outcome name.
     * @param string $before     Status before the action.
     * @param string $after      Status after the action.
     * @param string $code       Error code, empty when the action succeeded.
     * @param string $message    Error message paired with the code.
     * @return array
     */
    private function moderation_result( $comment_id, $outcome, $before, $after, $code = '', $message = '' ) {
        $result = array(
            'comment_id'    => $comment_id,
            'outcome'       => $outcome,
            'status_before' => $before,
            'status_after'  => $after,
        );
        if ( '' !== $code ) {
            $result['error_code']    = $code;
            $result['error_message'] = $message;
        }
        return $result;
    }

    /**
     * Build one per-comment deletion result row.
     *
     * @param int    $comment_id   Comment ID.
     * @param string $outcome      Outcome name.
     * @param string $before       Status before the deletion.
     * @param bool   $exists_after Whether the comment still exists afterwards.
     * @param string $code         Error code, empty when the deletion succeeded.
     * @param string $message      Error message paired with the code.
     * @return array
     */
    private function deletion_result( $comment_id, $outcome, $before, $exists_after, $code = '', $message = '' ) {
        $result = array(
            'comment_id'    => $comment_id,
            'outcome'       => $outcome,
            'status_before' => $before,
            'exists_after'  => $exists_after,
        );
        if ( '' !== $code ) {
            $result['error_code']    = $code;
            $result['error_message'] = $message;
        }
        return $result;
    }

    /**
     * Build the single invalid-request error response for a v2 operation.
     *
     * @param string $operation Operation the request was rejected for.
     * @return array
     */
    private function v2_error( $operation ) {
        return array(
            'contract_version' => 2,
            'operation'        => $operation,
            'error_code'       => 'invalid_request',
            'error_message'    => 'The Comments v2 request is invalid.',
        );
    }

    /**
     * Get recent comments.
     *
     * @param array $pAllowedStatuses An array containing allowed comment statuses.
     * @param int   $pCount Number of comments to return.
     *
     * @return array $allComments Array of all comments found.
     */
    public function get_recent_comments( $pAllowedStatuses, $pCount ) {
        if ( ! function_exists( '\get_comment_author_url' ) ) {
            include_once WPINC . '/comment-template.php'; // NOSONAR -- WP compatible.
        }
        $allComments = array();

        foreach ( $pAllowedStatuses as $status ) {
            $params = array( 'status' => $status );
            if ( 0 !== $pCount ) {
                $params['number'] = $pCount;
            }
            $comments = get_comments( $params );
            if ( is_array( $comments ) ) {
                foreach ( $comments as $comment ) {
                    $post                        = get_post( $comment->comment_post_ID );
                    $outComment                  = array();
                    $outComment['id']            = $comment->comment_ID;
                    $outComment['status']        = wp_get_comment_status( $comment->comment_ID );
                    $outComment['author']        = $comment->comment_author;
                    $outComment['author_url']    = get_comment_author_url( $comment->comment_ID );
                    $outComment['author_ip']     = get_comment_author_IP( $comment->comment_ID );
                    $outComment['author_email']  = apply_filters( 'comment_email', $comment->comment_author_email );
                    $outComment['postId']        = $comment->comment_post_ID;
                    $outComment['postName']      = $post->post_title;
                    $outComment['comment_count'] = $post->comment_count;
                    $outComment['content']       = $comment->comment_content;
                    $outComment['dts']           = strtotime( $comment->comment_date_gmt );
                    $allComments[]               = $outComment;
                }
            }
        }

        return $allComments;
    }
}
