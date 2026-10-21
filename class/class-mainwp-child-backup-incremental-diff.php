<?php
/**
 * MainWP Child Backup Incremental Diff - size+mtime change detection.
 *
 * Compares the current walk's files.list against the parent snapshot's
 * files.list. A file is new when absent from the parent list, changed
 * when its size or mtime differs, and carried forward otherwise - file
 * contents are never read or hashed, so the diff costs one stat-record
 * comparison per file. New and changed files stream to changes.list
 * (same relpath/size/mtime format as files.list); parent entries the
 * walk never produced stream to tombstones.list (one relpath per line)
 * so restore can apply deletions.
 *
 * The default path loads the parent list into one short string per file.
 * Past roughly 100k files that map presses on shared-host memory limits,
 * so a bounded-memory sorted-merge over the two sorted lists replaces it.
 * Both paths emit identical output.
 *
 * A parent list that is missing, malformed, or empty when it should have
 * entries makes the caller force a full backup - a wrong parent map
 * would classify deletions as carried-forward files and silently corrupt
 * the chain.
 *
 * @package MainWP\Child
 */

namespace MainWP\Child;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Class MainWP_Child_Backup_Incremental_Diff
 *
 * @package MainWP\Child
 */
class MainWP_Child_Backup_Incremental_Diff { // phpcs:ignore Generic.Classes.OpeningBraceSameLine.ContentAfterBrace -- NOSONAR.

    /**
     * Parent line count above which the in-memory map gives way to the
     * sorted-merge diff.
     */
    const SORTED_MERGE_THRESHOLD = 100000;

    /**
     * Changes sidecar name.
     */
    const CHANGES_LIST = 'changes.list';

    /**
     * Tombstones sidecar name.
     */
    const TOMBSTONES_LIST = 'tombstones.list';

    /**
     * Load a parent files.list into a relpath map.
     *
     * @param string $parent_files_list_path Parent list path.
     *
     * @return array {
     *     Load result.
     *
     *     @type array $map   relpath => "size\tmtime" strings.
     *     @type int   $count Entries parsed.
     *     @type bool  $valid False when the file is missing or any line is malformed.
     * }
     */
    public function load_parent_map( $parent_files_list_path ) {
        $result = array(
            'map'   => array(),
            'count' => 0,
            'valid' => false,
        );

        if ( ! is_readable( $parent_files_list_path ) ) {
            return $result;
        }

        $handle = fopen( $parent_files_list_path, 'rb' ); // phpcs:ignore WordPress.WP.AlternativeFunctions -- streamed list read.
        if ( false === $handle ) {
            return $result;
        }

        $valid = true;
        while ( false !== ( $line = fgets( $handle ) ) ) { // phpcs:ignore Generic.CodeAnalysis.AssignmentInCondition -- streamed read.
            $line = rtrim( $line, "\r\n" );
            if ( '' === $line ) {
                continue;
            }
            $fields = $this->parse_list_line( $line );
            if ( null === $fields ) {
                $valid = false;
                break;
            }
            $result['map'][ $fields[0] ] = $fields[1] . "\t" . $fields[2];
            ++$result['count'];
        }

        fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions -- streamed list read.

        if ( ! $valid ) {
            $result['map']   = array();
            $result['count'] = 0;
            return $result;
        }

        $result['valid'] = true;
        return $result;
    }

    /**
     * Classify one current file against the parent map.
     *
     * @param string $relpath Site-relative path.
     * @param int    $size    Current size.
     * @param int    $mtime   Current mtime.
     * @param array  $map     Parent map from load_parent_map().
     *
     * @return string One of 'new', 'changed', 'carry'.
     */
    public function classify( $relpath, $size, $mtime, $map ) {
        if ( ! isset( $map[ $relpath ] ) ) {
            return 'new';
        }
        return ( $map[ $relpath ] === (int) $size . "\t" . (int) $mtime ) ? 'carry' : 'changed';
    }

    /**
     * Stream the diff of a current files.list against the parent map.
     *
     * Writes changes.list (new and changed files, current size/mtime) and
     * tombstones.list (parent entries the walk never produced). The map is
     * consumed: entries seen during the walk are removed so the residue is
     * exactly the deletion set.
     *
     * @param string $current_files_list_path Current walk's files.list.
     * @param array  $parent_map              Parent map (consumed).
     * @param string $changes_path            changes.list output path.
     * @param string $tombstones_path         tombstones.list output path.
     * @param array  $unarchived              Relative unreadable prefixes.
     * @param bool   $suppress_tombstones     Whether unreadable coverage is unlistable.
     *
     * @throws MainWP_Exception When an output file cannot be written.
     *
     * @return array Counts: changes, tombstones, carried.
     */
    public function stream_diff( $current_files_list_path, $parent_map, $changes_path, $tombstones_path, $unarchived = array(), $suppress_tombstones = false ) {
        $current = fopen( $current_files_list_path, 'rb' ); // phpcs:ignore WordPress.WP.AlternativeFunctions -- streamed list read.
        $changes = fopen( $changes_path, 'wb' ); // phpcs:ignore WordPress.WP.AlternativeFunctions -- streamed list write.
        MainWP_Helper::protect_backup_path( $changes_path );
        if ( false === $current || false === $changes ) {
            $this->close_handles( array( $current, $changes ) );
            throw new MainWP_Exception( esc_html( MainWP_Backup_Error::message( MainWP_Backup_Error::ERR_DIR_NOT_WRITABLE ) ), null, MainWP_Backup_Error::ERR_DIR_NOT_WRITABLE ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- registry messages are pre-escaped.
        }

        $counts = array(
            'changes'    => 0,
            'tombstones' => 0,
            'carried'    => 0,
        );

        while ( false !== ( $line = fgets( $current ) ) ) { // phpcs:ignore Generic.CodeAnalysis.AssignmentInCondition -- streamed read.
            $line = rtrim( $line, "\r\n" );
            if ( '' === $line ) {
                continue;
            }
            $fields = $this->parse_list_line( $line );
            if ( null === $fields ) {
                continue; // The current list is this run's own output; skip noise defensively.
            }

            $kind = $this->classify( $fields[0], $fields[1], $fields[2], $parent_map );
            unset( $parent_map[ $fields[0] ] );

            if ( 'carry' === $kind ) {
                ++$counts['carried'];
                continue;
            }

            $this->write_line( $changes, $fields[0] . "\t" . $fields[1] . "\t" . $fields[2] . "\n" );
            ++$counts['changes'];
        }

        fclose( $current ); // phpcs:ignore WordPress.WP.AlternativeFunctions -- streamed list read.
        fclose( $changes ); // phpcs:ignore WordPress.WP.AlternativeFunctions -- streamed list write.

        $prefixes = MainWP_Child_Backup_Path_Cover::prefix_set( $unarchived );
        foreach ( $parent_map as $path => $entry ) {
            if ( $suppress_tombstones || MainWP_Child_Backup_Path_Cover::contains( $path, $prefixes ) ) {
                // Missing bytes under unreadable coverage do not establish that the live file was deleted.
                ++$counts['carried'];
                unset( $parent_map[ $path ] );
            }
        }
        $counts['tombstones'] = $this->emit_tombstones( array_keys( $parent_map ), $tombstones_path );

        return $counts;
    }

    /**
     * Bounded-memory diff of two sorted files.lists via a two-pointer
     * merge. Output is identical to stream_diff() for the same inputs.
     *
     * @param string $parent_files_list_path  Parent files.list (sorted by relpath).
     * @param string $current_files_list_path Current files.list (sorted by relpath).
     * @param string $changes_path            changes.list output path.
     * @param string $tombstones_path         tombstones.list output path.
     * @param array  $unarchived              Relative unreadable prefixes.
     * @param bool   $suppress_tombstones     Whether unreadable coverage is unlistable.
     *
     * @throws MainWP_Exception When an output file cannot be written.
     *
     * @return array Counts (changes, tombstones, carried) plus valid: false
     *               when the parent list is missing or malformed.
     */
    public function sorted_merge_diff( $parent_files_list_path, $current_files_list_path, $changes_path, $tombstones_path, $unarchived = array(), $suppress_tombstones = false ) { // phpcs:ignore -- NOSONAR - two-pointer merge.
        $result = array(
            'changes'    => 0,
            'tombstones' => 0,
            'carried'    => 0,
            'valid'      => false,
        );

        $parent  = is_readable( $parent_files_list_path ) ? fopen( $parent_files_list_path, 'rb' ) : false; // phpcs:ignore WordPress.WP.AlternativeFunctions -- streamed list read.
        $current = fopen( $current_files_list_path, 'rb' ); // phpcs:ignore WordPress.WP.AlternativeFunctions -- streamed list read.
        $changes = fopen( $changes_path, 'wb' ); // phpcs:ignore WordPress.WP.AlternativeFunctions -- streamed list write.
        MainWP_Helper::protect_backup_path( $changes_path );
        $tombs = fopen( $tombstones_path, 'wb' ); // phpcs:ignore WordPress.WP.AlternativeFunctions -- streamed list write.
        MainWP_Helper::protect_backup_path( $tombstones_path );

        if ( false === $parent || false === $current || false === $changes || false === $tombs ) {
            $this->close_handles( array( $parent, $current, $changes, $tombs ) );
            if ( false === $changes || false === $tombs ) {
                throw new MainWP_Exception( esc_html( MainWP_Backup_Error::message( MainWP_Backup_Error::ERR_DIR_NOT_WRITABLE ) ), null, MainWP_Backup_Error::ERR_DIR_NOT_WRITABLE ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- registry messages are pre-escaped.
            }
            return $result; // Unreadable parent - the caller forces a full backup.
        }

        $prefixes = MainWP_Child_Backup_Path_Cover::prefix_set( $unarchived );
        $corrupt  = false;
        $p        = $this->next_entry( $parent, $corrupt );
        $c        = $this->next_entry( $current );

        while ( null !== $p || null !== $c ) {
            if ( $corrupt ) {
                break;
            }

            if ( null === $p ) {
                $cmp = 1; // Parent exhausted - everything left is new.
            } elseif ( null === $c ) {
                $cmp = -1; // Current exhausted - everything left is deleted.
            } else {
                $cmp = strcmp( $p[0], $c[0] );
            }

            if ( 0 === $cmp ) {
                if ( $p[1] === $c[1] && $p[2] === $c[2] ) {
                    ++$result['carried'];
                } else {
                    $this->write_line( $changes, $c[0] . "\t" . $c[1] . "\t" . $c[2] . "\n" );
                    ++$result['changes'];
                }
                $p = $this->next_entry( $parent, $corrupt );
                $c = $this->next_entry( $current );
            } elseif ( $cmp < 0 ) {
                if ( $suppress_tombstones || MainWP_Child_Backup_Path_Cover::contains( $p[0], $prefixes ) ) {
                    ++$result['carried'];
                } else {
                    $this->write_line( $tombs, $p[0] . "\n" );
                    ++$result['tombstones'];
                }
                $p = $this->next_entry( $parent, $corrupt );
            } else {
                $this->write_line( $changes, $c[0] . "\t" . $c[1] . "\t" . $c[2] . "\n" );
                ++$result['changes'];
                $c = $this->next_entry( $current );
            }
        }

        $this->close_handles( array( $parent, $current, $changes, $tombs ) );

        if ( $corrupt ) {
            return array(
                'changes'    => 0,
                'tombstones' => 0,
                'carried'    => 0,
                'valid'      => false,
            );
        }

        $result['valid'] = true;
        return $result;
    }

    /**
     * Write one sidecar line, refusing a short or failed write.
     *
     * A full disk truncates changes.list or tombstones.list without an error,
     * and the run would still report success with a chain that silently drops
     * those files. Fail the diff instead.
     *
     * @param resource $handle Open write handle.
     * @param string   $line   Line to write (newline included).
     *
     * @throws MainWP_Exception When the write is short or fails.
     *
     * @return void
     */
    protected function write_line( $handle, $line ) {
        $written = fwrite( $handle, $line ); // phpcs:ignore WordPress.WP.AlternativeFunctions -- streamed list write.
        if ( false === $written || strlen( $line ) !== $written ) {
            throw new MainWP_Exception( esc_html( MainWP_Backup_Error::message( MainWP_Backup_Error::ERR_DISK_FULL ) ), null, MainWP_Backup_Error::ERR_DISK_FULL ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- registry messages are pre-escaped.
        }
    }

    /**
     * Whether the parent list state demands a full backup instead of an
     * incremental: a corrupt list always does; an empty list does when the
     * parent was expected to have entries.
     *
     * @param array $load_result      Result from load_parent_map().
     * @param bool  $parent_had_files Whether the parent source claimed entries.
     *
     * @return bool True to force a full backup.
     */
    public function should_force_full( $load_result, $parent_had_files ) {
        if ( empty( $load_result['valid'] ) ) {
            return true;
        }
        return $parent_had_files && 0 === (int) $load_result['count'];
    }

    /**
     * Write the deletion set, one relpath per line.
     *
     * @param array  $relpaths        Deleted relpaths.
     * @param string $tombstones_path Output path.
     *
     * @throws MainWP_Exception When the output file cannot be written.
     *
     * @return int Tombstones written.
     */
    public function emit_tombstones( $relpaths, $tombstones_path ) {
        $handle = fopen( $tombstones_path, 'wb' ); // phpcs:ignore WordPress.WP.AlternativeFunctions -- streamed list write.
        MainWP_Helper::protect_backup_path( $tombstones_path );
        if ( false === $handle ) {
            throw new MainWP_Exception( esc_html( MainWP_Backup_Error::message( MainWP_Backup_Error::ERR_DIR_NOT_WRITABLE ) ), null, MainWP_Backup_Error::ERR_DIR_NOT_WRITABLE ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- registry messages are pre-escaped.
        }

        $count = 0;
        foreach ( $relpaths as $relpath ) {
            $this->write_line( $handle, $relpath . "\n" );
            ++$count;
        }

        fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions -- streamed list write.

        return $count;
    }

    /**
     * Parse one files.list line into [relpath, size, mtime].
     *
     * @param string $line Raw line without the trailing newline.
     *
     * @return array|null Parsed fields, null when malformed.
     */
    protected function parse_list_line( $line ) {
        $fields = explode( "\t", $line );
        if ( 3 !== count( $fields ) || '' === $fields[0] || ! ctype_digit( $fields[1] ) || ! ctype_digit( $fields[2] ) ) {
            return null;
        }
        return array( $fields[0], (int) $fields[1], (int) $fields[2] );
    }

    /**
     * Read the next parsed entry from a sorted list handle.
     *
     * @param resource  $handle  Open list handle.
     * @param bool|null $corrupt Set true when a malformed line is hit.
     *
     * @return array|null Entry fields, null at end of file.
     */
    protected function next_entry( $handle, &$corrupt = null ) {
        while ( false !== ( $line = fgets( $handle ) ) ) { // phpcs:ignore Generic.CodeAnalysis.AssignmentInCondition -- streamed read.
            $line = rtrim( $line, "\r\n" );
            if ( '' === $line ) {
                continue;
            }
            $fields = $this->parse_list_line( $line );
            if ( null === $fields && null !== $corrupt ) {
                $corrupt = true;
                return null;
            }
            if ( null !== $fields ) {
                return $fields;
            }
        }
        return null;
    }

    /**
     * Close any open handles from a partially failed open sequence.
     *
     * @param array $handles Mixed handles/false values.
     */
    protected function close_handles( $handles ) {
        foreach ( $handles as $handle ) {
            if ( is_resource( $handle ) ) {
                fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions -- handle cleanup.
            }
        }
    }
}
