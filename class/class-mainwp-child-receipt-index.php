<?php
/**
 * Bounded index of the per-request receipt rows one protocol has written.
 *
 * @package MainWP\Child
 */

namespace MainWP\Child;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Remember which receipt keys a protocol wrote, so retention is reclaimed by exact key lookup.
 *
 * A request reference is one-shot, so nothing ever comes back to the row it created and the owning
 * protocol only ever reclaims the reference the current request happens to name. The rows nobody
 * returns for are findable two ways: by scanning wp_options for a key prefix, which is unindexed
 * and would sit on the mutation path, or by remembering the keys. This remembers them.
 *
 * The index is advisory in both directions. It never decides that a row may go - the owner's own
 * expiry predicate does, read against the row as it stands now, because the expiry stored here is
 * only a cheap gate for which keys are worth reading at all. And it never refuses: a full index
 * drops its oldest entry, so the row that entry named goes back to leaking exactly as it did
 * before there was an index, rather than a bookkeeping limit travelling back into a mutation that
 * has already reserved its effect.
 *
 * Refusal reaches an owner two ways and both stop at this boundary. Every option read and write in
 * here runs the option filters, which any plugin on the site can hook, so a failure inside one
 * arrives as a throw rather than as a false. Letting one out would end the owner's mutation from
 * inside its bookkeeping - and add() runs after the durable dispatch marker exists, so the request
 * would die holding a reservation for an effect that never ran, which every later request under
 * that reference then reads. That is the one reason this class swallows a \Throwable where the rest
 * of this plugin deliberately fails loudly: what a caught throw costs is a tracked key, and what an
 * escaped one costs is a request nothing can settle.
 */
class MainWP_Child_Receipt_Index {

    /**
     * Stored elements above which an index is discarded instead of read entry by entry.
     *
     * Nothing this class writes can exceed the cap its caller passes, so an array this long was put
     * there by a hand edit, a partial restore or corruption, and walking it would put unbounded
     * work on a mutation path that has already reserved its effect. Counting an array is O(1), so
     * the guard costs nothing. It bounds the traversal only: get_option() has already unserialized
     * whatever was stored by the time this class sees it.
     *
     * @var int
     */
    const RAW_ENTRY_CEILING = 2000;

    /**
     * Option-key prefix every indexed row has to carry.
     *
     * @var string
     */
    private $prefix;

    /**
     * Bind one index to the key namespace of a single protocol.
     *
     * @param string $prefix Prefix the owning protocol builds its receipt option keys from.
     */
    public function __construct( $prefix ) {
        $this->prefix = is_string( $prefix ) ? $prefix : '';
    }

    /**
     * Record one receipt key, dropping the oldest entry when the index is full.
     *
     * @param string $index_option Option holding this protocol's index.
     * @param string $option_key   Receipt option key that was just written.
     * @param mixed  $expires_at   Retention moment stored on that receipt.
     * @param int    $cap          Entries the index holds at once.
     * @return bool Whether the entry was stored.
     */
    public function add( $index_option, $option_key, $expires_at, $cap ) {
        if ( ! $this->own_key( $option_key ) || ! is_int( $expires_at ) || 0 >= $expires_at || ! is_int( $cap ) || 1 > $cap ) {
            return false;
        }
        try {
            $entries   = $this->entries( $index_option );
            $entries[] = array(
                'key'        => $option_key,
                'expires_at' => $expires_at,
            );
            $overflow  = count( $entries ) - $cap;
            if ( 0 < $overflow ) {
                $entries = array_slice( $entries, $overflow );
            }
            return $this->store( $index_option, $entries );
        } catch ( \Throwable $index_failed ) {
            // The answer a rejected entry already gets, because the caller must not be able to tell
            // a key that went untracked from one that did.
            return false;
        }
    }

    /**
     * Reclaim up to $limit indexed rows the owner judges past retention.
     *
     * @param string   $index_option Option holding this protocol's index.
     * @param int      $limit        Rows one call may read.
     * @param callable $reclaimable  Owner predicate over the stored row; true means the row may go.
     * @return int Rows actually deleted.
     */
    public function sweep( $index_option, $limit, callable $reclaimable ) {
        if ( ! is_int( $limit ) || 1 > $limit ) {
            return 0;
        }
        $kept      = array();
        $read      = 0;
        $reclaimed = 0;
        $now       = time();
        $missing   = '__mainwp_child_receipt_index_missing__';
        try {
            $entries = $this->entries( $index_option );
            foreach ( $entries as $entry ) {
                if ( $read >= $limit || $entry['expires_at'] > $now ) {
                    $kept[] = $entry;
                    continue;
                }
                ++$read;
                $value = get_option( $entry['key'], $missing );
                if ( $missing === $value ) {
                    continue;
                }
                // The owner judges the row as it stands, never the entry: the key may have been
                // written again since it was indexed, and a row nothing can parse is still evidence
                // a resent request needs, which is why a predicate that says no keeps the entry as
                // well as the row. A delete that did not take leaves both standing for the same
                // reason.
                if ( true !== $reclaimable( $value ) || ! $this->delete_matched( $entry['key'], $value ) ) {
                    $kept[] = $entry;
                    continue;
                }
                ++$reclaimed;
            }
            if ( $kept !== $entries ) {
                $this->store( $index_option, $kept );
            }
        } catch ( \Throwable $index_failed ) {
            // The rows counted so far are gone from the table, so the count reports them; the index
            // keeps naming them until a later sweep reads them missing and drops the entries.
            // The throw ends the sweep instead of skipping the entry it landed on: a predicate that
            // threw said nothing about the row it was judging, and a delete decided after an unknown
            // failure is the one thing this class must never do.
        }
        return $reclaimed;
    }

    /**
     * Read the stored index, discarding anything that is not a usable entry.
     *
     * The index is stored input exactly as the receipts are, so an entry that does not name a key
     * in this protocol's own namespace is dropped rather than acted on. Dropping one touches no
     * option row: the row it might have named stops being tracked, which is the leak this
     * class exists to reduce and never a deletion. An index that will not read at all is treated
     * as empty and written over, because refusing on behalf of an unreadable one would stall every
     * mutation behind it. An index holding more elements than any cap could have produced is
     * discarded the same way, for the same reason: normalizing it entry by entry is the unbounded
     * work, not the refusal.
     *
     * @param string $index_option Option holding this protocol's index.
     * @return array Validated entries in insertion order.
     */
    private function entries( $index_option ) {
        $stored = get_option( $index_option, array() );
        if ( ! is_array( $stored ) || self::RAW_ENTRY_CEILING < count( $stored ) ) {
            return array();
        }
        $entries = array();
        foreach ( $stored as $entry ) {
            if ( is_array( $entry ) && isset( $entry['key'], $entry['expires_at'] ) && $this->own_key( $entry['key'] ) && is_int( $entry['expires_at'] ) && 0 < $entry['expires_at'] ) {
                $entries[] = array(
                    'key'        => $entry['key'],
                    'expires_at' => $entry['expires_at'],
                );
            }
        }
        return $entries;
    }

    /**
     * Whether one key belongs to the namespace this index was bound to.
     *
     * An empty prefix would make every row in the options table look like this protocol's own, so
     * it matches nothing at all. Every key an owner can actually write is its prefix followed by a
     * sha256 of the request reference, so anything else under the prefix is a row this class did
     * not write and has no business handing to an owner's delete predicate. The length bound stays
     * because the prefix is the part this class is handed rather than derived.
     *
     * @param mixed $option_key Candidate option key.
     * @return bool
     */
    private function own_key( $option_key ) {
        return '' !== $this->prefix && is_string( $option_key ) && 191 >= strlen( $option_key ) && 1 === preg_match( '/^' . preg_quote( $this->prefix, '/' ) . '[a-f0-9]{64}$/D', $option_key );
    }

    /**
     * Delete one row only while it still holds the exact value the owner judged.
     *
     * Core's delete_option() matches the option key alone, so a delete decided from a value read a
     * moment earlier lands on whatever has since been written under the same reference. A
     * reference is one-shot, so what lands there is a fresh dispatch marker: dropping that leaves
     * the request which wrote it holding an effect to perform and no receipt to settle, and a later
     * retry against restored preconditions performs the effect again. Two of the three owners hold
     * a lane around their mutation and one does not, and the index cannot tell which is calling.
     *
     * The stored column carries the serialized form, so the value read back has to be serialized
     * again to compare against it.
     *
     * @param string $option_key Row to delete.
     * @param mixed  $value      Value the owner judged, exactly as it was read.
     * @return bool Whether that exact row went.
     */
    private function delete_matched( $option_key, $value ) {
        global $wpdb;
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- delete_option() cannot condition on the value, which is the whole judgement here.
        $deleted = $wpdb->query( $wpdb->prepare( "DELETE FROM $wpdb->options WHERE option_name = %s AND option_value = %s", $option_key, maybe_serialize( $value ) ) );
        $this->forget_option_cache( $option_key );
        return 1 === (int) $deleted;
    }

    /**
     * Correct the options cache for a row deleted behind the options API.
     *
     * Core's delete_option() maintains these buckets and a direct query does not, so a row that has
     * gone keeps answering every later get_option() in the request out of the cache or the
     * alloptions bucket - including the owner's own status read, which would replay a receipt that
     * is no longer there. The notoptions entry is cleared alongside them so the three cannot
     * disagree about the same key. Only the key that was deleted is dropped, the way core corrects
     * the buckets, because flushing them would cost every option the request has already loaded.
     *
     * @param string $option_key Key whose cached state is now wrong.
     */
    private function forget_option_cache( $option_key ) {
        wp_cache_delete( $option_key, 'options' );
        $alloptions = wp_cache_get( 'alloptions', 'options' );
        if ( is_array( $alloptions ) && isset( $alloptions[ $option_key ] ) ) {
            unset( $alloptions[ $option_key ] );
            wp_cache_set( 'alloptions', $alloptions, 'options' );
        }
        $notoptions = wp_cache_get( 'notoptions', 'options' );
        if ( is_array( $notoptions ) && isset( $notoptions[ $option_key ] ) ) {
            unset( $notoptions[ $option_key ] );
            wp_cache_set( 'notoptions', $notoptions, 'options' );
        }
    }

    /**
     * Persist the index without autoloading it.
     *
     * @param string $index_option Option holding this protocol's index.
     * @param array  $entries      Entries to store.
     * @return bool
     */
    private function store( $index_option, $entries ) {
        return update_option( $index_option, $entries, false );
    }
}
