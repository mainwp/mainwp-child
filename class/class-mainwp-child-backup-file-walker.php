<?php
/**
 * MainWP Child Backup File Walker - streams the manifest sidecars.
 *
 * Walks the site tree with the same containment, symlink, and exclude
 * rules as the archive traversal and streams two sidecar files into the
 * backup scratch directory:
 *
 * - files.list:  relpath<tab>size<tab>mtime per file, sorted by relpath.
 *   This is the manifest an incremental backup diffs against; sorted
 *   output keeps a bounded-memory sorted-merge diff possible on very
 *   large sites.
 * - paths.cache: component<tab>relpath per file, in the same order. The
 *   incremental pack phase resumes by line number in this file.
 *
 * To stay within a child's memory_limit on very large trees, the walk does
 * not hold the whole tree in memory. It buffers entries up to a chunk size,
 * sorts each chunk, spills it to a numbered immutable run file, and then
 * k-way merges the runs straight into the sidecars. Peak memory is one chunk
 * during the walk plus k head lines during the merge, independent of the
 * total file count. The merged output is byte-for-byte identical to a single
 * global usort by strcmp, because relpaths are unique and both the per-chunk
 * sort and the merge compare with strcmp.
 *
 * Sidecars are written under staged names and only promoted to their
 * final names when the run that produced them completes, so the previous
 * snapshot's manifest survives a failed or interrupted run.
 *
 * The walk is resumable across request slices. emit_sidecars_slice() does a
 * budget-bounded amount of work and returns a serializable cursor: an explicit
 * directory-cursor stack (each frame a directory plus its index into that
 * directory's strcmp-sorted child list), the list of completed run files, the
 * running counters, and the phase (enumerate or merge). A killed slice resumes
 * from the cursor instead of restarting the walk from zero, so a very large
 * tree no longer livelocks under a short slice budget. emit_sidecars() is the
 * non-sliced wrapper that loops the engine to completion. The cursor's index is
 * stable only for a tree that does not change between slices; a file added or
 * removed at a sort position before the live index may be skipped or
 * double-counted, the same snapshot inconsistency the single-pass walk accepts.
 *
 * Only files are tracked. Directory entries carry no backup payload and
 * deletions are tracked per file; an empty directory does not survive an
 * incremental chain, which restore accepts.
 *
 * @package MainWP\Child
 */

namespace MainWP\Child;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Class MainWP_Child_Backup_File_Walker
 *
 * @package MainWP\Child
 */
class MainWP_Child_Backup_File_Walker { // phpcs:ignore Generic.Classes.OpeningBraceSameLine.ContentAfterBrace -- NOSONAR.

    /**
     * Final manifest sidecar name.
     */
    const FILES_LIST = 'files.list';

    /**
     * Final pack-order sidecar name.
     */
    const PATHS_CACHE = 'paths.cache';

    /**
     * Suffix for staged (not yet promoted) sidecars.
     */
    const STAGED_SUFFIX = '.staged';

    /**
     * Prefix for the numbered spill run files.
     */
    const RUN_PREFIX = 'walk-run-';

    /**
     * Entries buffered before a sorted chunk spills to a run file. At roughly
     * 390 bytes per entry this caps the walk's working set near 20 MB,
     * independent of the total file count.
     */
    const CHUNK_SIZE = 50000;

    /**
     * How many times a resumed walk may sweep its runs and restart from a
     * clean slate after meeting an unreadable run (a partial survivor of a
     * host crash) before the error is allowed to fail the backup.
     */
    const MAX_WALK_RECOVERIES = 1;

    /**
     * Walk root with a trailing slash.
     *
     * @var string
     */
    protected $root;

    /**
     * Exclude list of site-relative paths.
     *
     * @var array
     */
    protected $excludes;

    /**
     * Whether .zip files are skipped, matching the archive walk.
     *
     * @var bool
     */
    protected $excludezip;

    /**
     * Whether only the wp-admin, wp-includes, and wp-content trees are
     * walked, matching the archive walk's directory gate.
     *
     * @var bool
     */
    protected $excludenonwp;

    /**
     * Realpaths never walked - the backup scratch dir lives here so the
     * manifest never describes in-flight backup artifacts.
     *
     * @var array
     */
    protected $skip_dirs;

    /**
     * Output directory (the backup scratch dir) with a trailing slash, where
     * the run files and staged sidecars are written. Set per emission.
     *
     * @var string
     */
    protected $run_dir = '';

    /**
     * In-memory buffer for the current chunk, reset on each spill.
     *
     * @var array
     */
    protected $buffer = array();

    /**
     * Paths of the spilled run files, in spill order.
     *
     * @var array
     */
    protected $runs = array();

    /**
     * Running count of recorded files (the manifest line count).
     *
     * @var int
     */
    protected $files_scanned = 0;

    /**
     * Running sum of recorded file sizes in bytes.
     *
     * @var int
     */
    protected $total_bytes = 0;

    /**
     * Optional heartbeat fired at each chunk boundary.
     *
     * @var callable|null
     */
    protected $progress_callback = null;

    /**
     * Enumeration stack: one frame per active directory on the DFS path, each
     * holding its directory, its index into the strcmp-sorted child list, the
     * is_root flag, and the in-memory children array (not serialized).
     *
     * @var array
     */
    protected $stack = array();

    /**
     * Walk phase for the current slice: 'enumerate' while the tree is being
     * scanned into runs, then 'merge' while the runs are merged.
     *
     * @var string
     */
    protected $phase = 'enumerate';

    /**
     * How many times this walk has swept its runs and restarted after meeting
     * an unreadable run, bounded by MAX_WALK_RECOVERIES.
     *
     * @var int
     */
    protected $recover_count = 0;

    /**
     * Constructor.
     *
     * @param string|null $root         Walk root, null for ABSPATH.
     * @param array       $excludes     Exclude list of site-relative paths.
     * @param bool        $excludezip   Skip .zip files.
     * @param bool        $excludenonwp Walk only the wp trees.
     * @param array       $skip_dirs    Directories never walked (the backup scratch dir).
     */
    public function __construct( $root = null, $excludes = array(), $excludezip = false, $excludenonwp = false, $skip_dirs = array() ) {
        $this->root         = rtrim( null === $root ? ABSPATH : $root, '/' ) . '/';
        $this->excludes     = is_array( $excludes ) ? $excludes : array();
        $this->excludezip   = (bool) $excludezip;
        $this->excludenonwp = (bool) $excludenonwp;

        $this->skip_dirs = array();
        foreach ( (array) $skip_dirs as $dir ) {
            $real = realpath( $dir );
            if ( false !== $real ) {
                $this->skip_dirs[] = rtrim( $real, '/' );
            }
        }
    }

    /**
     * Register a heartbeat callback fired at each chunk boundary so a long
     * walk on a large tree keeps the task alive instead of looking stalled.
     *
     * @param callable|null $callback Callable, or null to clear.
     */
    public function set_progress_callback( $callback ) {
        $this->progress_callback = is_callable( $callback ) ? $callback : null;
    }

    /**
     * Walk the whole tree in one call and write the staged sidecars.
     *
     * The non-sliced wrapper around emit_sidecars_slice(): it loops the engine
     * with an unreachable deadline so the walk never yields, then cleans up the
     * spilled runs the way a completed sliced walk does at task completion. The
     * sliced callers in the task runner use emit_sidecars_slice() directly.
     *
     * @param string $backup_dir Output directory (the backup scratch dir).
     *
     * @throws MainWP_Exception When a sidecar or run cannot be written, or a
     *                          run is corrupt.
     *
     * @return array Emission result: file (final manifest name), size
     *               (manifest bytes), count (files recorded), total_bytes
     *               (sum of recorded file sizes).
     */
    public function emit_sidecars( $backup_dir ) {
        $cursor = null;
        $guard  = 0;
        do {
            $out    = $this->emit_sidecars_slice( $backup_dir, $cursor, PHP_INT_MAX );
            $cursor = $out['cursor'];
        } while ( empty( $out['done'] ) && ++$guard < 100000 );

        // A completed walk's runs are consumed; the sliced path defers this to
        // task completion, the one-shot path does it here.
        $this->delete_runs( $this->runs );

        return array(
            'file'        => $out['file'],
            'size'        => $out['size'],
            'count'       => $out['count'],
            'total_bytes' => $out['total_bytes'],
        );
    }

    /**
     * Do a budget-bounded slice of the walk and return a resumable cursor.
     *
     * A fresh start (null or empty cursor) wipes any leftover runs and seeds the
     * stack at the root. A resume rehydrates the stack, run list, and counters
     * from the cursor and sweeps only runs the cursor does not reference. Work
     * proceeds until the deadline is near, then the current chunk spills and the
     * cursor is returned with done = false; the caller persists it and yields.
     * When the stack empties the merge runs; if it cannot finish in the slice it
     * re-merges from scratch on the next call (the runs are immutable).
     *
     * @param string     $backup_dir Output directory (the backup scratch dir).
     * @param array|null $cursor  Resume cursor from a prior slice, or null/empty to start.
     * @param int        $deadline    Unix time the slice must yield by.
     *
     * @throws MainWP_Exception When a sidecar or run cannot be written, or a
     *                          run stays corrupt past the recovery budget.
     *
     * @return array { done: bool, cursor: array|null, count: int,
     *                 total_bytes: int, and file/size when done }.
     */
    public function emit_sidecars_slice( $backup_dir, $cursor, $deadline ) {
        $this->run_dir = rtrim( $backup_dir, '/' ) . '/';
        $files_list    = $this->run_dir . self::FILES_LIST . self::STAGED_SUFFIX;
        $paths_cache   = $this->run_dir . self::PATHS_CACHE . self::STAGED_SUFFIX;

        $resuming = is_array( $cursor ) && ! empty( $cursor );

        if ( $resuming ) {
            $this->hydrate_cursor( $cursor );
            // Drop runs from a dead slice that spilled past the last checkpoint;
            // the cursor's runs are kept and the cursor re-creates the rest.
            $this->sweep_orphan_runs();
        } else {
            $this->buffer        = array();
            $this->runs          = array();
            $this->files_scanned = 0;
            $this->total_bytes   = 0;
            $this->recover_count = 0;
            $this->phase         = 'enumerate';
            $this->stack         = array( $this->root_frame() );

            // Clear any runs left by a prior killed slice so a fresh walk never
            // merges them into the new manifest.
            $this->delete_runs( (array) glob( $this->run_dir . self::RUN_PREFIX . '*' ) );
        }

        if ( 'enumerate' === $this->phase ) {
            while ( ! empty( $this->stack ) ) {
                if ( $this->walk_budget_exhausted( $deadline ) ) {
                    $this->flush_chunk();
                    return $this->slice_progress( false );
                }
                $this->step_one();
            }

            $this->flush_chunk();
            $this->phase = 'merge';

            if ( $this->walk_budget_exhausted( $deadline ) ) {
                return $this->slice_progress( false );
            }
        }

        try {
            $this->merge_runs( $this->runs, $files_list, $paths_cache );
        } catch ( MainWP_Exception $e ) {
            if ( $resuming && $this->recover_count < self::MAX_WALK_RECOVERIES ) {
                // A run referenced by the resumed cursor is unreadable (a
                // partial survivor of a host crash). Sweep everything and
                // restart the walk once instead of failing the backup.
                $this->reset_for_recovery( $files_list, $paths_cache );
                return $this->slice_progress( false );
            }
            // Drop the partial runs and staged sidecars, then rethrow - the
            // same contract the one-shot walk has always had.
            $this->delete_runs( $this->runs );
            wp_delete_file( $files_list ); // NOSONAR .
            wp_delete_file( $paths_cache ); // NOSONAR .
            throw $e;
        }

        // The sidecar was just streamed through fopen/fwrite/fclose, which
        // leaves any earlier stat result for the path in PHP's stat cache on
        // some runtimes; a stale read here would report the wrong size.
        clearstatcache( true, $files_list );

        return array(
            'done'        => true,
            'cursor'      => null,
            'file'        => self::FILES_LIST,
            'size'        => (int) filesize( $files_list ),
            'count'       => (int) $this->files_scanned,
            'total_bytes' => (int) $this->total_bytes,
        );
    }

    /**
     * Whether the slice deadline is near enough to spill and yield. Mirrors the
     * pack phase's two-second guard so a checkpoint always lands before the
     * request is killed.
     *
     * @param int $deadline Unix time the slice must yield by.
     *
     * @return bool True when the slice should checkpoint and yield.
     */
    protected function walk_budget_exhausted( $deadline ) {
        return time() >= $deadline - 2;
    }

    /**
     * Build the done = false slice result with the serialized cursor.
     *
     * @param bool $done Always false here; kept for call-site symmetry.
     *
     * @return array Slice result with the resume cursor and live counters.
     */
    protected function slice_progress( $done ) {
        return array(
            'done'        => (bool) $done,
            'cursor'      => $this->serialize_cursor(),
            'count'       => (int) $this->files_scanned,
            'total_bytes' => (int) $this->total_bytes,
        );
    }

    /**
     * Serialize the walk state into a persistable cursor. Children arrays are
     * not stored - they are re-derived on resume - and run paths are stored as
     * basenames so the cursor survives a scratch-dir path change.
     *
     * @return array The resume cursor.
     */
    protected function serialize_cursor() {
        $frames = array();
        foreach ( $this->stack as $frame ) {
            $frames[] = array(
                'dir'     => $frame['dir'],
                'index'   => (int) $frame['index'],
                'is_root' => ! empty( $frame['is_root'] ),
            );
        }

        $runs = array();
        foreach ( $this->runs as $run ) {
            $runs[] = basename( (string) $run );
        }

        return array(
            'stack'         => $frames,
            'runs'          => $runs,
            'count'         => (int) $this->files_scanned,
            'bytes'         => (int) $this->total_bytes,
            'phase'         => 'merge' === $this->phase ? 'merge' : 'enumerate',
            'recover_count' => (int) $this->recover_count,
        );
    }

    /**
     * Rehydrate the walk state from a resume cursor. Each stack frame's child
     * list is re-derived from disk, tolerating a directory that vanished
     * between slices (an empty list pops the frame on the next step).
     *
     * @param array $cursor Resume cursor from a prior slice.
     */
    protected function hydrate_cursor( $cursor ) {
        $this->buffer        = array();
        $this->files_scanned = isset( $cursor['count'] ) ? (int) $cursor['count'] : 0;
        $this->total_bytes   = isset( $cursor['bytes'] ) ? (int) $cursor['bytes'] : 0;
        $this->phase         = isset( $cursor['phase'] ) && 'merge' === $cursor['phase'] ? 'merge' : 'enumerate';
        $this->recover_count = isset( $cursor['recover_count'] ) ? (int) $cursor['recover_count'] : 0;

        $this->runs = array();
        if ( isset( $cursor['runs'] ) && is_array( $cursor['runs'] ) ) {
            foreach ( $cursor['runs'] as $base ) {
                $this->runs[] = $this->run_dir . basename( (string) $base );
            }
        }

        $this->stack = array();
        if ( isset( $cursor['stack'] ) && is_array( $cursor['stack'] ) ) {
            foreach ( $cursor['stack'] as $frame ) {
                if ( ! isset( $frame['dir'] ) ) {
                    continue;
                }
                $is_root       = ! empty( $frame['is_root'] );
                $this->stack[] = array(
                    'dir'      => (string) $frame['dir'],
                    'index'    => isset( $frame['index'] ) ? (int) $frame['index'] : 0,
                    'is_root'  => $is_root,
                    'children' => $this->enumerate_children( (string) $frame['dir'], $is_root ),
                );
            }
        }
    }

    /**
     * Delete on-disk run files the cursor does not reference - orphans a dead
     * slice spilled after the last checkpoint, which the resumed cursor will
     * re-create from its enumeration position.
     */
    protected function sweep_orphan_runs() {
        $keep = array();
        foreach ( $this->runs as $run ) {
            $keep[ basename( (string) $run ) ] = true;
        }
        foreach ( (array) glob( $this->run_dir . self::RUN_PREFIX . '*' ) as $path ) {
            if ( ! isset( $keep[ basename( $path ) ] ) ) {
                wp_delete_file( $path ); // NOSONAR .
            }
        }
    }

    /**
     * Sweep every run and staged sidecar and reseed the walk at the root after
     * a resumed run met an unreadable run file. Bounded by MAX_WALK_RECOVERIES.
     *
     * @param string $files_list  Staged files.list path.
     * @param string $paths_cache Staged paths.cache path.
     */
    protected function reset_for_recovery( $files_list, $paths_cache ) {
        $this->delete_runs( (array) glob( $this->run_dir . self::RUN_PREFIX . '*' ) );
        wp_delete_file( $files_list ); // NOSONAR .
        wp_delete_file( $paths_cache ); // NOSONAR .

        $this->buffer        = array();
        $this->runs          = array();
        $this->files_scanned = 0;
        $this->total_bytes   = 0;
        $this->phase         = 'enumerate';
        $this->stack         = array( $this->root_frame() );
        ++$this->recover_count;
    }

    /**
     * Promote the staged sidecars over the final names.
     *
     * Called when the run that emitted them completes. Until then the
     * previous snapshot's promoted sidecars stay untouched, so a failed
     * run never destroys the manifest the next incremental needs.
     *
     * @param string $backup_dir Directory holding the sidecars.
     *
     * @return bool True when both sidecars were promoted.
     */
    public static function promote_sidecars( $backup_dir ) {
        $dir    = rtrim( $backup_dir, '/' ) . '/';
        $staged = array(
            $dir . self::FILES_LIST . self::STAGED_SUFFIX  => $dir . self::FILES_LIST,
            $dir . self::PATHS_CACHE . self::STAGED_SUFFIX => $dir . self::PATHS_CACHE,
        );

        foreach ( array_keys( $staged ) as $from ) {
            if ( ! file_exists( $from ) ) {
                return false;
            }
        }

        $ok = true;
        foreach ( $staged as $from => $to ) {
            $ok = rename( $from, $to ) && $ok; // phpcs:ignore WordPress.WP.AlternativeFunctions.rename_rename -- atomic same-directory promotion.
        }

        return $ok;
    }

    /**
     * Entries buffered before a chunk spills. Overridable so tests can force
     * the multi-run merge path on a small fixture.
     *
     * @return int Chunk size in entries.
     */
    protected function chunk_size() {
        return self::CHUNK_SIZE;
    }

    /**
     * Seed the enumeration stack with the root frame. The root is enumerated
     * with glob so leading-dot entries are skipped, matching the original walk.
     *
     * @return array The root stack frame.
     */
    protected function root_frame() {
        return array(
            'dir'      => $this->root,
            'index'    => 0,
            'is_root'  => true,
            'children' => $this->enumerate_children( $this->root, true ),
        );
    }

    /**
     * List a directory's child node paths in a resume-stable order.
     *
     * The root uses glob (skips leading-dot entries, as the original root walk
     * did); other directories use scandir (keeps dotfiles, drops . and ..). A
     * vanished or unreadable directory yields an empty list, the same tolerance
     * the original recursive walk had. The list is sorted by raw byte order so
     * a frame's index resolves to the same child on resume regardless of the
     * host locale; the merge re-sorts the manifest output anyway.
     *
     * @param string $dir     Directory (absolute, trailing slash for the root).
     * @param bool   $is_root Whether this is the root frame.
     *
     * @return string[] Absolute child node paths, strcmp-sorted.
     */
    protected function enumerate_children( $dir, $is_root ) {
        if ( $is_root ) {
            $children = glob( $dir . '*' );
            if ( false === $children ) {
                $children = array();
            }
        } else {
            $items = scandir( $dir, SCANDIR_SORT_NONE );
            if ( false === $items ) {
                return array();
            }
            $children = array();
            foreach ( $items as $item ) {
                if ( '.' === $item || '..' === $item ) {
                    continue;
                }
                $children[] = rtrim( $dir, '/' ) . '/' . $item;
            }
        }

        usort( $children, 'strcmp' );
        return $children;
    }

    /**
     * Process one candidate from the top stack frame: record a file, descend
     * into a directory, or pop an exhausted frame. The frame index advances
     * before any descent so a checkpoint here resumes after this candidate. The
     * filter order matches the original walk: containment, then the root-only
     * non-wp gate, then the exclude list.
     */
    protected function step_one() {
        $top = count( $this->stack ) - 1;

        if ( $this->stack[ $top ]['index'] >= count( $this->stack[ $top ]['children'] ) ) {
            array_pop( $this->stack );
            return;
        }

        $node    = $this->stack[ $top ]['children'][ $this->stack[ $top ]['index'] ];
        $is_root = $this->stack[ $top ]['is_root'];
        ++$this->stack[ $top ]['index'];

        if ( ! $this->is_walkable( $node ) ) {
            return;
        }

        $relpath = substr( $node, strlen( $this->root ) );

        if ( $is_root && $this->excludenonwp && is_dir( $node ) && ! in_array( $relpath, array( 'wp-content', 'wp-admin', 'wp-includes' ), true ) ) {
            return;
        }

        if ( MainWP_Helper::excluded_from_backup( $this->excludes, $relpath ) ) {
            return;
        }

        if ( is_dir( $node ) ) {
            $this->stack[] = array(
                'dir'      => $node,
                'index'    => 0,
                'is_root'  => false,
                'children' => $this->enumerate_children( $node, false ),
            );
        } elseif ( is_file( $node ) ) {
            $this->record_file( $node, $relpath );
        }
    }

    /**
     * Shared containment and the frame chain keep linked content within bounds.
     *
     * @param string $node Absolute path.
     *
     * @return bool True when the node may be walked.
     */
    protected function is_walkable( $node ) {
        if ( ! MainWP_Backup::is_safe_backup_path( $node, array_merge( MainWP_Backup::default_backup_bases(), array( $this->root ) ), array_column( $this->stack, 'dir' ) ) ) {
            MainWP_Backup::record_skipped_path( $node );
            return false;
        }

        $real = realpath( $node );
        if ( false === $real ) {
            MainWP_Backup::record_skipped_path( $node );
            return false;
        }

        foreach ( $this->skip_dirs as $skip ) {
            if ( $real === $skip || 0 === strpos( $real . '/', $skip . '/' ) ) {
                return false;
            }
        }

        return true;
    }

    /**
     * Record one file entry into the buffer, spilling a run when it fills.
     *
     * @param string $node    Absolute path.
     * @param string $relpath Site-relative path.
     */
    protected function record_file( $node, $relpath ) {
        if ( $this->excludezip && MainWP_Helper::ends_with( $node, '.zip' ) ) {
            return;
        }

        $size  = @filesize( $node ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- a file deleted mid-walk is skipped, not fatal.
        $mtime = @filemtime( $node ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- a file deleted mid-walk is skipped, not fatal.
        if ( false === $size || false === $mtime ) {
            return;
        }

        $this->buffer[]       = array( $relpath, (int) $size, (int) $mtime, self::classify_component( $relpath ) );
        $this->files_scanned += 1;
        $this->total_bytes   += (int) $size;

        if ( count( $this->buffer ) >= $this->chunk_size() ) {
            $this->flush_chunk();
        }
    }

    /**
     * Sort the buffered chunk by relpath and spill it to a run file, then
     * fire the heartbeat. A no-op on an empty buffer so the final flush after
     * an exact-multiple walk neither writes an empty run nor double-beats.
     */
    protected function flush_chunk() {
        if ( empty( $this->buffer ) ) {
            return;
        }

        usort(
            $this->buffer,
            function ( $a, $b ) {
                return strcmp( $a[0], $b[0] );
            }
        );

        $this->runs[] = $this->write_run( $this->buffer );
        $this->buffer = array();

        if ( null !== $this->progress_callback ) {
            call_user_func( $this->progress_callback );
        }
    }

    /**
     * Write a sorted chunk to the next numbered run file as
     * relpath<tab>size<tab>mtime<tab>component lines.
     *
     * @param array $buffer Sorted chunk entries.
     *
     * @throws MainWP_Exception When the run cannot be written.
     *
     * @return string Absolute path of the written run file.
     */
    protected function write_run( array $buffer ) {
        $path = $this->run_dir . self::RUN_PREFIX . sprintf( '%04d', count( $this->runs ) );

        $fh = fopen( $path, 'wb' ); // phpcs:ignore WordPress.WP.AlternativeFunctions -- streamed run write.
        if ( false === $fh ) {
            throw new MainWP_Exception( esc_html( MainWP_Backup_Error::message( MainWP_Backup_Error::ERR_DIR_NOT_WRITABLE ) ), null, MainWP_Backup_Error::ERR_DIR_NOT_WRITABLE ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- registry messages are pre-escaped.
        }

        $ok = true;
        foreach ( $buffer as $entry ) {
            $ok = false !== fwrite( $fh, $entry[0] . "\t" . $entry[1] . "\t" . $entry[2] . "\t" . $entry[3] . "\n" ) && $ok; // phpcs:ignore WordPress.WP.AlternativeFunctions -- streamed run write.
            if ( ! $ok ) {
                break;
            }
        }

        $ok = fclose( $fh ) && $ok; // phpcs:ignore WordPress.WP.AlternativeFunctions -- streamed run write.

        if ( ! $ok ) {
            wp_delete_file( $path ); // NOSONAR .
            throw new MainWP_Exception( esc_html( MainWP_Backup_Error::message( MainWP_Backup_Error::ERR_DISK_FULL ) ), null, MainWP_Backup_Error::ERR_DISK_FULL ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- registry messages are pre-escaped.
        }

        return $path;
    }

    /**
     * K-way merge the run files into the staged sidecars in strcmp order.
     *
     * Picks the smallest head relpath across the open runs and streams it to
     * both sidecars line-aligned, so files.list and paths.cache stay in the
     * same order. Peak memory is k head lines, independent of file count.
     *
     * @param array  $runs        Run file paths to merge.
     * @param string $files_list  Staged files.list path.
     * @param string $paths_cache Staged paths.cache path.
     *
     * @throws MainWP_Exception When a sidecar cannot be written or a run is corrupt.
     */
    protected function merge_runs( array $runs, $files_list, $paths_cache ) {
        $fl = fopen( $files_list, 'wb' ); // phpcs:ignore WordPress.WP.AlternativeFunctions -- streamed sidecar write.
        $pc = fopen( $paths_cache, 'wb' ); // phpcs:ignore WordPress.WP.AlternativeFunctions -- streamed sidecar write.
        if ( false === $fl || false === $pc ) {
            if ( is_resource( $fl ) ) {
                fclose( $fl ); // phpcs:ignore WordPress.WP.AlternativeFunctions -- streamed sidecar write.
            }
            if ( is_resource( $pc ) ) {
                fclose( $pc ); // phpcs:ignore WordPress.WP.AlternativeFunctions -- streamed sidecar write.
            }
            throw new MainWP_Exception( esc_html( MainWP_Backup_Error::message( MainWP_Backup_Error::ERR_DIR_NOT_WRITABLE ) ), null, MainWP_Backup_Error::ERR_DIR_NOT_WRITABLE ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- registry messages are pre-escaped.
        }

        $handles  = array();
        $write_ok = true;
        try {
            $heads = array();
            foreach ( $runs as $run ) {
                $h = fopen( $run, 'rb' ); // phpcs:ignore WordPress.WP.AlternativeFunctions -- streamed run read.
                if ( false === $h ) {
                    throw new MainWP_Exception( esc_html( MainWP_Backup_Error::message( MainWP_Backup_Error::ERR_DIR_NOT_WRITABLE ) ), null, MainWP_Backup_Error::ERR_DIR_NOT_WRITABLE ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- registry messages are pre-escaped.
                }
                $handles[] = $h;
                $heads[]   = $this->next_run_entry( $h );
            }

            while ( true ) {
                $min = null;
                foreach ( $heads as $i => $entry ) {
                    if ( null === $entry ) {
                        continue;
                    }
                    if ( null === $min || strcmp( $entry[0], $heads[ $min ][0] ) < 0 ) {
                        $min = $i;
                    }
                }
                if ( null === $min ) {
                    break;
                }

                $e        = $heads[ $min ];
                $write_ok = false !== fwrite( $fl, $e[0] . "\t" . $e[1] . "\t" . $e[2] . "\n" ) && $write_ok; // phpcs:ignore WordPress.WP.AlternativeFunctions -- streamed sidecar write.
                $write_ok = false !== fwrite( $pc, $e[3] . "\t" . $e[0] . "\n" ) && $write_ok; // phpcs:ignore WordPress.WP.AlternativeFunctions -- streamed sidecar write.
                if ( ! $write_ok ) {
                    break;
                }

                $heads[ $min ] = $this->next_run_entry( $handles[ $min ] );
            }

            $write_ok = fclose( $fl ) && $write_ok; // phpcs:ignore WordPress.WP.AlternativeFunctions -- streamed sidecar write.
            $write_ok = fclose( $pc ) && $write_ok; // phpcs:ignore WordPress.WP.AlternativeFunctions -- streamed sidecar write.
        } finally {
            foreach ( $handles as $h ) {
                if ( is_resource( $h ) ) {
                    fclose( $h ); // phpcs:ignore WordPress.WP.AlternativeFunctions -- streamed run read.
                }
            }
            // On the exception path the in-try fclose above was skipped; close
            // the sidecars here so no handle leaks. emit_sidecars() deletes the
            // staged files.
            if ( is_resource( $fl ) ) {
                fclose( $fl ); // phpcs:ignore WordPress.WP.AlternativeFunctions -- streamed sidecar write.
            }
            if ( is_resource( $pc ) ) {
                fclose( $pc ); // phpcs:ignore WordPress.WP.AlternativeFunctions -- streamed sidecar write.
            }
        }

        if ( ! $write_ok ) {
            throw new MainWP_Exception( esc_html( MainWP_Backup_Error::message( MainWP_Backup_Error::ERR_DISK_FULL ) ), null, MainWP_Backup_Error::ERR_DISK_FULL ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- registry messages are pre-escaped.
        }
    }

    /**
     * Read the next parseable entry from a run handle, skipping blank lines.
     *
     * @param resource $handle Open run handle.
     *
     * @throws MainWP_Exception When a run line is malformed.
     *
     * @return array|null Entry [ relpath, size, mtime, component ], or null at EOF.
     */
    protected function next_run_entry( $handle ) {
        while ( true ) {
            $line = fgets( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions -- streamed run read.
            if ( false === $line ) {
                return null;
            }

            $line = rtrim( $line, "\r\n" );
            if ( '' === $line ) {
                continue;
            }

            $entry = $this->parse_run_line( $line );
            if ( null === $entry ) {
                throw new MainWP_Exception( esc_html( MainWP_Backup_Error::message( MainWP_Backup_Error::ERR_FILE_CORRUPT ) ), null, MainWP_Backup_Error::ERR_FILE_CORRUPT ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- registry messages are pre-escaped.
            }

            return $entry;
        }
    }

    /**
     * Parse one run line into an entry, or null when malformed.
     *
     * @param string $line Run line without its terminator.
     *
     * @return array|null Entry [ relpath, size, mtime, component ], or null.
     */
    protected function parse_run_line( $line ) {
        $fields = explode( "\t", $line );
        if ( 4 !== count( $fields ) || '' === $fields[0] || ! ctype_digit( $fields[1] ) || ! ctype_digit( $fields[2] ) || '' === $fields[3] ) {
            return null;
        }
        return array( $fields[0], (int) $fields[1], (int) $fields[2], $fields[3] );
    }

    /**
     * Delete the given run files.
     *
     * @param array $runs Run file paths.
     */
    protected function delete_runs( array $runs ) {
        foreach ( $runs as $run ) {
            if ( is_string( $run ) && file_exists( $run ) ) {
                wp_delete_file( $run ); // NOSONAR .
            }
        }
    }

    /**
     * Classify a site-relative path into its pack component. Components
     * group the incremental part archives so related files rotate
     * together.
     *
     * @param string $relpath Site-relative path.
     *
     * @return string One of plugins, themes, uploads, wp-content, core.
     */
    public static function classify_component( $relpath ) {
        if ( 0 === strpos( $relpath, 'wp-content/plugins/' ) ) {
            return 'plugins';
        }
        if ( 0 === strpos( $relpath, 'wp-content/themes/' ) ) {
            return 'themes';
        }
        if ( 0 === strpos( $relpath, 'wp-content/uploads/' ) ) {
            return 'uploads';
        }
        if ( 0 === strpos( $relpath, 'wp-content/' ) ) {
            return 'wp-content';
        }
        return 'core';
    }
}
