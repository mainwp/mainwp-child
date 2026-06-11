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
     * Walk the tree and write the staged sidecars into the given directory.
     *
     * @param string $backup_dir Output directory (the backup scratch dir).
     *
     * @throws MainWP_Exception When a sidecar cannot be written.
     *
     * @return array Emission result: file (final manifest name), size
     *               (manifest bytes), count (files recorded).
     */
    public function emit_sidecars( $backup_dir ) {
        $entries = $this->collect_entries();

        $dir         = rtrim( $backup_dir, '/' ) . '/';
        $files_list  = $dir . self::FILES_LIST . self::STAGED_SUFFIX;
        $paths_cache = $dir . self::PATHS_CACHE . self::STAGED_SUFFIX;

        $fl = fopen( $files_list, 'wb' ); // phpcs:ignore WordPress.WP.AlternativeFunctions -- streamed sidecar write.
        $pc = fopen( $paths_cache, 'wb' ); // phpcs:ignore WordPress.WP.AlternativeFunctions -- streamed sidecar write.
        if ( false === $fl || false === $pc ) {
            if ( false !== $fl ) {
                fclose( $fl ); // phpcs:ignore WordPress.WP.AlternativeFunctions -- streamed sidecar write.
            }
            if ( false !== $pc ) {
                fclose( $pc ); // phpcs:ignore WordPress.WP.AlternativeFunctions -- streamed sidecar write.
            }
            throw new MainWP_Exception( esc_html( MainWP_Backup_Error::message( MainWP_Backup_Error::ERR_DIR_NOT_WRITABLE ) ), null, MainWP_Backup_Error::ERR_DIR_NOT_WRITABLE ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- registry messages are pre-escaped.
        }

        $ok = true;
        foreach ( $entries as $entry ) {
            $ok = $ok && false !== fwrite( $fl, $entry[0] . "\t" . $entry[1] . "\t" . $entry[2] . "\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions -- streamed sidecar write.
            $ok = $ok && false !== fwrite( $pc, $entry[3] . "\t" . $entry[0] . "\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions -- streamed sidecar write.
            if ( ! $ok ) {
                break;
            }
        }

        $ok = fclose( $fl ) && $ok; // phpcs:ignore WordPress.WP.AlternativeFunctions -- streamed sidecar write.
        $ok = fclose( $pc ) && $ok; // phpcs:ignore WordPress.WP.AlternativeFunctions -- streamed sidecar write.

        if ( ! $ok ) {
            wp_delete_file( $files_list ); // NOSONAR .
            wp_delete_file( $paths_cache ); // NOSONAR .
            throw new MainWP_Exception( esc_html( MainWP_Backup_Error::message( MainWP_Backup_Error::ERR_DISK_FULL ) ), null, MainWP_Backup_Error::ERR_DISK_FULL ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- registry messages are pre-escaped.
        }

        return array(
            'file'  => self::FILES_LIST,
            'size'  => (int) filesize( $files_list ),
            'count' => count( $entries ),
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
     * Collect every included file as a sorted entry list.
     *
     * @return array[] Entries of [ relpath, size, mtime, component ], sorted by relpath.
     */
    protected function collect_entries() {
        $entries = array();

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
                $this->walk_dir( $node, $entries );
            } elseif ( is_file( $node ) ) {
                $this->record_file( $node, $relpath, $entries );
            }
        }

        usort(
            $entries,
            function ( $a, $b ) {
                return strcmp( $a[0], $b[0] );
            }
        );

        return $entries;
    }

    /**
     * Recursively walk one directory, recording included files.
     *
     * @param string $dir     Directory (absolute).
     * @param array  $entries Entry accumulator.
     */
    protected function walk_dir( $dir, &$entries ) {
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
                $this->walk_dir( $node, $entries );
            } elseif ( is_file( $node ) ) {
                $this->record_file( $node, $relpath, $entries );
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
     * Record one file entry.
     *
     * @param string $node    Absolute path.
     * @param string $relpath Site-relative path.
     * @param array  $entries Entry accumulator.
     */
    protected function record_file( $node, $relpath, &$entries ) {
        if ( $this->excludezip && MainWP_Helper::ends_with( $node, '.zip' ) ) {
            return;
        }

        $size  = @filesize( $node ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- a file deleted mid-walk is skipped, not fatal.
        $mtime = @filemtime( $node ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- a file deleted mid-walk is skipped, not fatal.
        if ( false === $size || false === $mtime ) {
            return;
        }

        $entries[] = array( $relpath, (int) $size, (int) $mtime, self::classify_component( $relpath ) );
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
