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
     * Walk the tree and write the staged sidecars into the given directory.
     *
     * The tree is walked in bounded chunks: each chunk is sorted and spilled
     * to a run file, then the runs are k-way merged into the sidecars in
     * strcmp order.
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
        $this->run_dir       = rtrim( $backup_dir, '/' ) . '/';
        $this->buffer        = array();
        $this->runs          = array();
        $this->files_scanned = 0;
        $this->total_bytes   = 0;

        $files_list  = $this->run_dir . self::FILES_LIST . self::STAGED_SUFFIX;
        $paths_cache = $this->run_dir . self::PATHS_CACHE . self::STAGED_SUFFIX;

        // Clear any runs left by a prior killed slice so a re-walk never
        // merges them into the fresh manifest.
        $this->delete_runs( (array) glob( $this->run_dir . self::RUN_PREFIX . '*' ) );

        try {
            $this->walk_tree();
            $this->flush_chunk();
            $this->merge_runs( $this->runs, $files_list, $paths_cache );
        } catch ( MainWP_Exception $e ) {
            // Drop the partial runs and staged sidecars, then rethrow. Any
            // non-MainWP_Exception is swept by the startup cleanup on the next
            // emission instead.
            $this->delete_runs( $this->runs );
            wp_delete_file( $files_list ); // NOSONAR .
            wp_delete_file( $paths_cache ); // NOSONAR .
            throw $e;
        }

        // Runs are consumed once merged.
        $this->delete_runs( $this->runs );

        // The sidecar was just streamed through fopen/fwrite/fclose, which
        // leaves any earlier stat result for the path in PHP's stat cache
        // on some runtimes; a stale read here would report the wrong
        // manifest size.
        clearstatcache( true, $files_list );

        return array(
            'file'        => self::FILES_LIST,
            'size'        => (int) filesize( $files_list ),
            'count'       => (int) $this->files_scanned,
            'total_bytes' => (int) $this->total_bytes,
        );
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
     * Walk the tree, spilling sorted chunks to run files as the buffer fills.
     * The final partial chunk is left in the buffer for the caller to flush.
     */
    protected function walk_tree() {
        foreach ( (array) glob( $this->root . '*' ) as $node ) {
            if ( ! $this->is_walkable( $node ) ) {
                continue;
            }

            $relpath = substr( $node, strlen( $this->root ) );

            if ( $this->excludenonwp && is_dir( $node ) && ! in_array( $relpath, array( 'wp-content', 'wp-admin', 'wp-includes' ), true ) ) {
                continue;
            }

            if ( MainWP_Helper::excluded_from_backup( $this->excludes, $relpath ) ) {
                continue;
            }

            if ( is_dir( $node ) ) {
                $this->walk_dir( $node );
            } elseif ( is_file( $node ) ) {
                $this->record_file( $node, $relpath );
            }
        }
    }

    /**
     * Recursively walk one directory, recording included files.
     *
     * @param string $dir Directory (absolute).
     */
    protected function walk_dir( $dir ) {
        $items = scandir( $dir );
        if ( false === $items ) {
            return; // Unreadable directory - skipped, same as the archive walk.
        }

        foreach ( $items as $item ) {
            if ( '.' === $item || '..' === $item ) {
                continue;
            }

            $node = rtrim( $dir, '/' ) . '/' . $item;
            if ( ! $this->is_walkable( $node ) ) {
                continue;
            }

            $relpath = substr( $node, strlen( $this->root ) );
            if ( MainWP_Helper::excluded_from_backup( $this->excludes, $relpath ) ) {
                continue;
            }

            if ( is_dir( $node ) ) {
                $this->walk_dir( $node );
            } elseif ( is_file( $node ) ) {
                $this->record_file( $node, $relpath );
            }
        }
    }

    /**
     * Whether a node may be walked: never a symlink, always inside the
     * walk root, never inside a skipped directory.
     *
     * @param string $node Absolute path.
     *
     * @return bool True when the node may be walked.
     */
    protected function is_walkable( $node ) {
        if ( ! MainWP_Backup::is_safe_backup_path( $node, array( $this->root ) ) ) {
            return false;
        }

        $real = realpath( $node );
        if ( false === $real ) {
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
