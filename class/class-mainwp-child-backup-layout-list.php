<?php
/**
 * Private v2 sidecar adapter for the existing diff engines.
 *
 * @package MainWP\Child
 */

namespace MainWP\Child;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/** Explicit v2 entry points leave every headerless reader unchanged. */
class MainWP_Child_Backup_Layout_List {
    /**
     * Write format bytes to private backup storage.
     *
     * @param string $path Path.
     * @param string $digest Digest.
     * @param array  $rows Rows.
     * @param bool   $tombstones Tombstones.
     */
    public function write( $path, $digest, $rows, $tombstones = false ) {
        if ( ! MainWP_Child_Backup_Layout::is_digest( $digest ) ) {
            MainWP_Child_Backup_Layout::refuse();
        }
        ksort( $rows, SORT_STRING );
        $handle = fopen( $path, 'wb' ); // phpcs:ignore WordPress.WP.AlternativeFunctions -- private streamed backup scratch uses native handles.
        if ( false === $handle ) {
            MainWP_Child_Backup_Layout::refuse();
        }
        MainWP_Helper::protect_backup_path( $path );
        try {
            $this->put( $handle, $this->header( $digest, $tombstones ) );
            foreach ( $rows as $identity => $fields ) {
                MainWP_Child_Backup_Layout::parse_identity( $identity );
                if ( ! $tombstones && ( ! is_array( $fields ) || 2 !== count( $fields ) || ! is_int( $fields[0] ) || ! is_int( $fields[1] ) || $fields[0] < 0 || $fields[1] < 0 ) ) {
                    MainWP_Child_Backup_Layout::refuse();
                }
                $this->put( $handle, $identity . ( $tombstones ? '' : "\t" . $fields[0] . "\t" . $fields[1] ) . "\n" );
            }
        } finally {
            fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions -- private streamed backup scratch uses native handles.
        }
    }

    /**
     * Keep the component column beside each qualified identity.
     *
     * @param string $path Path.
     * @param array  $rows Rows.
     */
    public function write_paths_cache( $path, $rows ) {
        ksort( $rows, SORT_STRING );
        $handle = fopen( $path, 'wb' ); // phpcs:ignore WordPress.WP.AlternativeFunctions -- private streamed backup scratch uses native handles.
        if ( false === $handle ) {
            MainWP_Child_Backup_Layout::refuse();
        }
        MainWP_Helper::protect_backup_path( $path );
        try {
            foreach ( $rows as $identity => $component ) {
                MainWP_Child_Backup_Layout::parse_identity( $identity );
                if ( ! is_string( $component ) || ! in_array( $component, array( 'core', 'plugins', 'themes', 'uploads', 'wp-content' ), true ) ) {
                    MainWP_Child_Backup_Layout::refuse();
                }
                $this->put( $handle, $component . "\t" . $identity . "\n" );
            }
        } finally {
            fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions -- private streamed backup scratch uses native handles.
        }
    }

    /**
     * Read an explicitly selected v2 namespace.
     *
     * @param string $path Path.
     * @param string $digest Digest.
     * @param bool   $tombstones Tombstones.
     */
    public function read( $path, $digest, $tombstones = false ) {
        $rows = array();
        $this->each(
            $path,
            $digest,
            $tombstones,
            function ( $identity, $fields ) use ( &$rows ) {
                $rows[ $identity ] = $fields;
            }
        );
        return $rows;
    }

    /**
     * Validate sorted rows while streaming them to a callback.
     *
     * @param string   $path Path.
     * @param string   $digest Digest.
     * @param bool     $tombstones Tombstones.
     * @param callable $callback Callback.
     */
    private function each( $path, $digest, $tombstones, $callback ) {
        $handle = fopen( $path, 'rb' ); // phpcs:ignore WordPress.WP.AlternativeFunctions -- private streamed backup scratch uses native handles.
        if ( false === $handle ) {
            MainWP_Child_Backup_Layout::refuse();
        }
        try {
            $header = fgets( $handle, 256 );
            if ( ! is_string( $header ) || ! preg_match( '/^# mainwp-' . ( $tombstones ? 'tombstones' : 'files' ) . ' 2 ([a-f0-9]{64})\n$/D', $header, $match ) ) {
                MainWP_Child_Backup_Layout::refuse( MainWP_Backup_Error::ERR_UNSUPPORTED_BACKUP_FORMAT );
            }
            if ( ! MainWP_Child_Backup_Layout::is_digest( $digest ) || ! hash_equals( $digest, $match[1] ) ) {
                MainWP_Child_Backup_Layout::refuse();
            }
            $previous = null;
            while ( false !== ( $line = fgets( $handle, 131073 ) ) ) {
                if ( "\n" !== substr( $line, -1 ) ) {
                    MainWP_Child_Backup_Layout::refuse();
                }
                $fields   = explode( "\t", substr( $line, 0, -1 ) );
                $identity = $fields[0];
                MainWP_Child_Backup_Layout::parse_identity( $identity );
                if ( ( null !== $previous && strcmp( $previous, $identity ) >= 0 ) || ( $tombstones ? 1 !== count( $fields ) : ( 3 !== count( $fields ) || ! ctype_digit( $fields[1] ) || ! ctype_digit( $fields[2] ) ) ) ) {
                    MainWP_Child_Backup_Layout::refuse();
                }
                $previous = $identity;
                call_user_func( $callback, $identity, $tombstones ? true : array( (int) $fields[1], (int) $fields[2] ) );
            }
            if ( ! feof( $handle ) ) {
                MainWP_Child_Backup_Layout::refuse();
            }
        } finally {
            fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions -- private streamed backup scratch uses native handles.
        }
    }

    /**
     * Compare qualified coverage for an internally enabled task.
     *
     * @param MainWP_Child_Backup_Task_Runner $runner Task runner.
     * @param string                          $parent_path Parent list.
     * @param string                          $current Current list.
     * @param string                          $changes Changes list.
     * @param string                          $tombstones Tombstone list.
     * @param string                          $digest Descriptor digest.
     * @param array                           $walk Qualified coverage.
     * @return array Diff counts.
     */
    public function diff_for_task( $runner, $parent_path, $current, $changes, $tombstones, $digest, $walk ) {
        if ( ! $runner instanceof MainWP_Child_Backup_Task_Runner || ! $runner->allows_v2_capture() ) {
            MainWP_Child_Backup_Layout::refuse( MainWP_Backup_Error::ERR_UNSUPPORTED_BACKUP_FORMAT );
        }
        return $this->diff_walk( $parent_path, $current, $changes, $tombstones, $digest, $walk );
    }

    /**
     * Compare fixture-only v2 walks without interpreting incomplete coverage as deletion.
     *
     * @param string $parent_path Parent sidecar.
     * @param string $current Current sidecar.
     * @param string $changes Changes output.
     * @param string $tombstones Tombstone output.
     * @param string $digest Descriptor digest.
     * @param array  $walk Qualified coverage from the walker.
     * @param bool   $merge Use the sorted-merge engine.
     * @return array Diff counts.
     */
    private function diff_walk( $parent_path, $current, $changes, $tombstones, $digest, $walk, $merge = false ) {
        if ( ! is_array( $walk ) || 2 !== ( $walk['backup_format'] ?? null ) ) {
            MainWP_Child_Backup_Layout::refuse( MainWP_Backup_Error::ERR_UNSUPPORTED_BACKUP_FORMAT );
        }
        $unarchived = array();
        foreach ( array( 'excludes', 'unarchived', 'skipped_paths' ) as $key ) {
            if ( isset( $walk[ $key ] ) && ! is_array( $walk[ $key ] ) ) {
                MainWP_Child_Backup_Layout::refuse();
            }
            foreach ( $walk[ $key ] ?? array() as $path ) {
                if ( ! is_string( $path ) ) {
                    MainWP_Child_Backup_Layout::refuse();
                }
                $unarchived[] = $path;
            }
        }
        // A failed or unfinished walk cannot establish any deletion, including roots not yet visited.
        $suppress = true !== ( $walk['complete'] ?? false ) || ! empty( $walk['failed'] ) || ! empty( $walk['unarchived_overflow'] );
        return $this->diff( $parent_path, $current, $changes, $tombstones, $digest, $merge, $unarchived, $suppress );
    }

    /**
     * Bind headers before calling either existing diff engine.
     *
     * @param string $parent_path Parent path.
     * @param string $current Current.
     * @param string $changes Changes.
     * @param string $tombstones Tombstones.
     * @param string $digest Digest.
     * @param bool   $merge Merge.
     * @param array  $unarchived Unarchived.
     * @param bool   $overflow Overflow.
     */
    public function diff( $parent_path, $current, $changes, $tombstones, $digest, $merge = false, $unarchived = array(), $overflow = false ) {
        if ( ! is_dir( dirname( $changes ) ) || ! is_writable( dirname( $changes ) ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions -- private streams must not fall back to the system temporary directory.
            MainWP_Child_Backup_Layout::refuse( MainWP_Backup_Error::ERR_DIR_NOT_WRITABLE );
        }
        foreach ( $unarchived as $prefix ) {
            // A whole missing role is coverage, not an empty file list.
            MainWP_Child_Backup_Layout::parse_identity( 1 === substr_count( $prefix, '/' ) ? $prefix . '/coverage' : $prefix );
        }
        $scratch = array();
        try {
            foreach ( array( $parent_path, $current ) as $input ) {
                $plain     = tempnam( dirname( $changes ), 'mwp-list-' );
                $scratch[] = $plain;
                MainWP_Helper::protect_backup_path( $plain );
                $output = fopen( $plain, 'wb' ); // phpcs:ignore WordPress.WP.AlternativeFunctions -- private streamed backup scratch uses native handles.
                try {
                    $this->each(
                        $input,
                        $digest,
                        false,
                        function ( $identity, $fields ) use ( $output ) {
                            $this->put( $output, $identity . "\t" . $fields[0] . "\t" . $fields[1] . "\n" );
                        }
                    );
                } finally {
                    fclose( $output ); // phpcs:ignore WordPress.WP.AlternativeFunctions -- private streamed backup scratch uses native handles.
                }
            }
            $engine        = new MainWP_Child_Backup_Incremental_Diff();
            $plain_changes = tempnam( dirname( $changes ), 'mwp-change-' );
            $plain_tombs   = tempnam( dirname( $changes ), 'mwp-tomb-' );
            $scratch[]     = $plain_changes;
            $scratch[]     = $plain_tombs;
            if ( $merge ) {
                $counts = $engine->sorted_merge_diff( $scratch[0], $scratch[1], $plain_changes, $plain_tombs, $unarchived, $overflow );
                if ( empty( $counts['valid'] ) ) {
                    MainWP_Child_Backup_Layout::refuse();
                }
                unset( $counts['valid'] );
            } else {
                $map = $engine->load_parent_map( $scratch[0] );
                if ( ! $map['valid'] ) {
                    MainWP_Child_Backup_Layout::refuse();
                }
                $counts = $engine->stream_diff( $scratch[1], $map['map'], $plain_changes, $plain_tombs, $unarchived, $overflow );
            }
            $this->wrap_list( $plain_changes, $changes, $digest, false );
            $this->wrap_list( $plain_tombs, $tombstones, $digest, true );
            return $counts;
        } finally {
            foreach ( $scratch as $path ) {
                if ( is_file( $path ) ) {
                    unlink( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions -- private streamed backup scratch uses native handles.
                }
            }
        }
    }

    /**
     * Add a descriptor header to streamed diff output.
     *
     * @param string $plain Plain.
     * @param string $path Path.
     * @param string $digest Digest.
     * @param bool   $tombstones Tombstones.
     */
    private function wrap_list( $plain, $path, $digest, $tombstones ) {
        $input  = fopen( $plain, 'rb' ); // phpcs:ignore WordPress.WP.AlternativeFunctions -- private streamed backup scratch uses native handles.
        $output = fopen( $path, 'wb' ); // phpcs:ignore WordPress.WP.AlternativeFunctions -- private streamed backup scratch uses native handles.
        if ( false === $input || false === $output ) {
            MainWP_Child_Backup_Layout::refuse();
        }
        MainWP_Helper::protect_backup_path( $path );
        try {
            $this->put( $output, $this->header( $digest, $tombstones ) );
            if ( false === stream_copy_to_stream( $input, $output ) ) {
                MainWP_Child_Backup_Layout::refuse();
            }
        } finally {
            fclose( $input ); // phpcs:ignore WordPress.WP.AlternativeFunctions -- private streamed backup scratch uses native handles.
            fclose( $output ); // phpcs:ignore WordPress.WP.AlternativeFunctions -- private streamed backup scratch uses native handles.
        }
    }

    /**
     * Return the exact versioned sidecar header.
     *
     * @param string $digest Digest.
     * @param bool   $tombstones Tombstones.
     */
    private function header( $digest, $tombstones ) {
        return '# mainwp-' . ( $tombstones ? 'tombstones' : 'files' ) . ' 2 ' . $digest . "\n";
    }

    /**
     * Refuse short or failed sidecar writes.
     *
     * @param resource $handle Handle.
     * @param string   $bytes Bytes.
     */
    private function put( $handle, $bytes ) {
        if ( strlen( $bytes ) !== fwrite( $handle, $bytes ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions -- private streamed backup scratch uses native handles.
            MainWP_Child_Backup_Layout::refuse( MainWP_Backup_Error::ERR_TAR_WRITE_FAILED );
        }
    }
}
