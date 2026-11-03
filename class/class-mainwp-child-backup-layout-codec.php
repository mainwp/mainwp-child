<?php
/**
 * Private wrapped-tar format codec.
 *
 * @package MainWP\Child
 */

namespace MainWP\Child;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/** Fixture and future engine seam; no registered request dispatch. */
class MainWP_Child_Backup_Layout_Codec extends MainWP_Child_Backup_File_Restorer {
    const MAGIC = "MWPBACKUP2\n";

    /**
     * Write a complete small fixture through the resumable codec seam.
     *
     * @param string $path Private output path.
     * @param array  $descriptor Layout descriptor.
     * @param array  $entries Qualified identities to explicit source paths.
     */
    public function write( $path, $descriptor, $entries ) {
        $cursor = array();
        do {
            $result = $this->write_slice( $path, $descriptor, $entries, $cursor, PHP_FLOAT_MAX );
            $cursor = $result['cursor'];
        } while ( ! $result['done'] );
        unlink( $path . '.tar' ); // phpcs:ignore WordPress.WP.AlternativeFunctions -- completed fixture releases its private scratch.
    }

    /**
     * Append bounded entries, then wrap bounded bytes without enumerating roots.
     *
     * Each file uses the existing tar writer's buffered copy. File capture and
     * part rotation remain the responsibility of the later root-aware engine.
     *
     * @param string $path Private output path.
     * @param array  $descriptor Layout descriptor.
     * @param array  $entries Qualified identities to explicit local files.
     * @param array  $cursor Private resume state.
     * @param float  $deadline Slice deadline, checked between entries.
     * @param int    $max_entries Maximum entries per slice.
     * @param int    $max_bytes Maximum wrapper bytes per slice.
     * @param bool   $allow_omissions Omit vanished files and links for task capture.
     * @return array Completion and private cursor.
     */
    public function write_slice( $path, $descriptor, $entries, $cursor, $deadline, $max_entries = 100, $max_bytes = 4194304, $allow_omissions = false ) {
        $digest = MainWP_Child_Backup_Layout::digest( $descriptor );
        if ( ! is_int( $max_entries ) || $max_entries < 1 || ! is_int( $max_bytes ) || $max_bytes < 1 || is_link( $path ) || is_link( $path . '.tar' ) ) {
            MainWP_Child_Backup_Layout::refuse();
        }
        // Validate every name before creating or truncating any archive bytes.
        foreach ( array_keys( $entries ) as $identity ) {
            MainWP_Child_Backup_Layout::parse_identity( $identity );
        }
        $entry_count  = count( $entries );
        $entry_digest = hash( 'sha256', wp_json_encode( $entries ) );
        $plain        = $path . '.tar';
        $tar          = new Tar_Archiver( null, 'tar' );
        if ( empty( $cursor ) ) {
            $tar->create( $plain );
            MainWP_Helper::protect_backup_path( $plain );
            try {
                $tar->add_layout_descriptor( $descriptor );
            } finally {
                $tar->close();
            }
            $cursor = array(
                'format'                   => 2,
                'layout_descriptor_sha256' => $digest,
                'entries_sha256'           => $entry_digest,
                'entry_index'              => 0,
                'tar_size'                 => filesize( $plain ),
                'wrapped_offset'           => 0,
            );
            if ( $allow_omissions ) {
                $cursor['omission_policy'] = true;
            }
        } elseif ( ! isset( $cursor['format'], $cursor['layout_descriptor_sha256'], $cursor['entries_sha256'], $cursor['entry_index'], $cursor['tar_size'], $cursor['wrapped_offset'] ) || 2 !== $cursor['format'] || $digest !== $cursor['layout_descriptor_sha256'] || $entry_digest !== $cursor['entries_sha256'] ) {
            MainWP_Child_Backup_Layout::refuse( MainWP_Backup_Error::ERR_LAYOUT_CHANGED );
        }
        if ( ( $cursor['omission_policy'] ?? false ) !== $allow_omissions ) {
            MainWP_Child_Backup_Layout::refuse( MainWP_Backup_Error::ERR_LAYOUT_CHANGED );
        }
        foreach ( array( 'entry_index', 'tar_size', 'wrapped_offset' ) as $key ) {
            if ( ! is_int( $cursor[ $key ] ) || $cursor[ $key ] < 0 ) {
                MainWP_Child_Backup_Layout::refuse();
            }
        }
        if ( $cursor['entry_index'] > $entry_count || $cursor['wrapped_offset'] > $cursor['tar_size'] || ! is_file( $plain ) || filesize( $plain ) < $cursor['tar_size'] ) {
            MainWP_Child_Backup_Layout::refuse();
        }
        if ( $cursor['entry_index'] < $entry_count ) {
            $this->truncate_private( $plain, $cursor['tar_size'] );
            $tar->append( $plain );
            try {
                $identities = array_keys( $entries );
                $processed  = 0;
                do {
                    $identity     = $identities[ $cursor['entry_index'] ];
                    $source       = $entries[ $identity ];
                    list( $role ) = MainWP_Child_Backup_Layout::parse_identity( $identity );
                    clearstatcache( true, $source );
                    $omitted = $allow_omissions && 'config' !== $role && ( is_link( $source ) || ! file_exists( $source ) );
                    if ( ! $omitted && ! $tar->add_layout_file( $source, $identity, array( dirname( $source ) ) ) ) {
                        clearstatcache( true, $source );
                        $omitted = $allow_omissions && 'config' !== $role && ( is_link( $source ) || ! file_exists( $source ) );
                        if ( ! $omitted ) {
                            MainWP_Child_Backup_Layout::refuse();
                        }
                    }
                    if ( $omitted ) {
                        $cursor['omitted_count'] = ( $cursor['omitted_count'] ?? 0 ) + 1;
                        if ( count( $cursor['omitted'] ?? array() ) < 1000 ) {
                            $cursor['omitted'][ $identity ] = is_link( $source ) ? 'link' : 'unstatable';
                        } else {
                            $cursor['omitted_overflow'] = true;
                        }
                    }
                    ++$cursor['entry_index'];
                    ++$processed;
                } while ( $cursor['entry_index'] < $entry_count && $processed < $max_entries && microtime( true ) < $deadline );
            } finally {
                $tar->close();
            }
            clearstatcache( true, $plain );
            $cursor['tar_size'] = filesize( $plain );
            return array(
                'done'   => false,
                'cursor' => $cursor,
            );
        }
        $input  = fopen( $plain, 'rb' ); // phpcs:ignore WordPress.WP.AlternativeFunctions -- private streamed backup scratch uses native handles.
        $output = fopen( $path, 'c+b' ); // phpcs:ignore WordPress.WP.AlternativeFunctions -- private streamed backup scratch uses native handles.
        MainWP_Helper::protect_backup_path( $path );
        if ( false === $input || false === $output ) {
            MainWP_Child_Backup_Layout::refuse( MainWP_Backup_Error::ERR_TAR_WRITE_FAILED );
        }
        try {
            $offset = $cursor['wrapped_offset'];
            if ( 0 === $offset ) {
                if ( ! ftruncate( $output, 0 ) || strlen( self::MAGIC ) !== fwrite( $output, self::MAGIC ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions -- private streamed backup scratch uses native handles.
                    MainWP_Child_Backup_Layout::refuse( MainWP_Backup_Error::ERR_TAR_WRITE_FAILED );
                }
            } elseif ( ! ftruncate( $output, strlen( self::MAGIC ) + $offset ) || 0 !== fseek( $output, strlen( self::MAGIC ) + $offset ) ) {
                MainWP_Child_Backup_Layout::refuse();
            }
            if ( 0 !== fseek( $input, $offset ) ) {
                MainWP_Child_Backup_Layout::refuse();
            }
            $copied = stream_copy_to_stream( $input, $output, $max_bytes );
            if ( false === $copied ) {
                MainWP_Child_Backup_Layout::refuse( MainWP_Backup_Error::ERR_TAR_WRITE_FAILED );
            }
            $cursor['wrapped_offset'] += $copied;
        } finally {
            fclose( $input ); // phpcs:ignore WordPress.WP.AlternativeFunctions -- private streamed backup scratch uses native handles.
            fclose( $output ); // phpcs:ignore WordPress.WP.AlternativeFunctions -- private streamed backup scratch uses native handles.
        }
        $done = $cursor['wrapped_offset'] === $cursor['tar_size'];
        return array(
            'done'   => $done,
            'cursor' => $cursor,
        );
    }

    /**
     * Discard uncheckpointed bytes before resuming an entry boundary.
     *
     * @param string $path Private scratch file.
     * @param int    $size Checkpointed byte count.
     */
    private function truncate_private( $path, $size ) {
        $handle = fopen( $path, 'r+b' ); // phpcs:ignore WordPress.WP.AlternativeFunctions -- private streamed backup scratch uses native handles.
        if ( false === $handle ) {
            MainWP_Child_Backup_Layout::refuse();
        }
        try {
            if ( ! ftruncate( $handle, $size ) ) {
                MainWP_Child_Backup_Layout::refuse();
            }
        } finally {
            fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions -- private streamed backup scratch uses native handles.
        }
    }

    /**
     * Unwrap bounded bytes and extract only into private resumable scratch.
     *
     * @param string $path Path.
     * @param string $scratch Scratch.
     * @param string $digest Digest.
     * @param array  $cursor Cursor.
     * @param float  $deadline Deadline.
     * @param int    $max_bytes Max bytes.
     */
    public function read_slice( $path, $scratch, $digest, $cursor, $deadline, $max_bytes = 4194304 ) {
        if ( ! is_dir( $scratch ) || is_link( preg_replace( '~(?:/\.?)+$~', '', $scratch ) ) || ! MainWP_Child_Backup_Layout::is_digest( $digest ) || ! is_int( $max_bytes ) || $max_bytes < 1 ) {
            MainWP_Child_Backup_Layout::refuse();
        }
        $source = fopen( $path, 'rb' ); // phpcs:ignore WordPress.WP.AlternativeFunctions -- private streamed backup scratch uses native handles.
        if ( false === $source ) {
            MainWP_Child_Backup_Layout::refuse();
        }
        try {
            if ( self::MAGIC !== fread( $source, strlen( self::MAGIC ) ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions -- private streamed backup scratch uses native handles.
                MainWP_Child_Backup_Layout::refuse( MainWP_Backup_Error::ERR_UNSUPPORTED_BACKUP_FORMAT );
            }
            $stat    = fstat( $source );
            $binding = array_intersect_key( $stat, array_flip( array( 'dev', 'ino', 'size', 'mtime', 'ctime' ) ) );
            if ( empty( $cursor ) ) {
                $cursor = array(
                    'format'                   => 2,
                    'layout_descriptor_sha256' => $digest,
                    'source_stat'              => $binding,
                );
            } elseif ( ! isset( $cursor['format'], $cursor['layout_descriptor_sha256'], $cursor['source_stat'] ) || 2 !== $cursor['format'] || $digest !== $cursor['layout_descriptor_sha256'] || $binding !== $cursor['source_stat'] ) {
                MainWP_Child_Backup_Layout::refuse( MainWP_Backup_Error::ERR_LAYOUT_CHANGED );
            }
            $plain  = $scratch . '/payload.tar';
            $offset = $cursor['offset'] ?? 0;
            if ( ! is_int( $offset ) || $offset < 0 || is_link( $plain ) || ( $offset > 0 && ( ! is_file( $plain ) || filesize( $plain ) !== $offset ) ) ) {
                MainWP_Child_Backup_Layout::refuse();
            }
            if ( empty( $cursor['unwrapped'] ) ) {
                $output = fopen( $plain, 0 === $offset ? 'wb' : 'ab' ); // phpcs:ignore WordPress.WP.AlternativeFunctions -- private streamed backup scratch uses native handles.
                MainWP_Helper::protect_backup_path( $plain );
                if ( false === $output || 0 !== fseek( $source, strlen( self::MAGIC ) + $offset ) ) {
                    MainWP_Child_Backup_Layout::refuse();
                }
                try {
                    $copied = stream_copy_to_stream( $source, $output, $max_bytes );
                    if ( false === $copied ) {
                        MainWP_Child_Backup_Layout::refuse();
                    }
                    $offset             += $copied;
                    $stat                = fstat( $source );
                    $cursor['offset']    = $offset;
                    $cursor['unwrapped'] = $offset + strlen( self::MAGIC ) === $stat['size'];
                } finally {
                    fclose( $output ); // phpcs:ignore WordPress.WP.AlternativeFunctions -- private streamed backup scratch uses native handles.
                }
                if ( ! $cursor['unwrapped'] ) {
                    return array(
                        'done'   => false,
                        'cursor' => $cursor,
                    );
                }
            }
        } finally {
            fclose( $source ); // phpcs:ignore WordPress.WP.AlternativeFunctions -- private streamed backup scratch uses native handles.
        }
        if ( empty( $cursor['validated'] ) ) {
            $cursor['descriptor']     = $this->validate_payload( $plain, $digest );
            $cursor['payload_sha256'] = $this->payload_digest( $plain );
            $cursor['validated']      = true;
        }
        $descriptor = $cursor['descriptor'];
        $target     = $scratch . '/extracted';
        if ( is_link( $target ) ) {
            MainWP_Child_Backup_Layout::refuse();
        }
        if ( ! is_dir( $target ) && ! mkdir( $target, 0700 ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions -- private streamed backup scratch uses native handles.
            MainWP_Child_Backup_Layout::refuse();
        }
        $result = $this->restore_slice(
            $cursor['extract_cursor'] ?? array(),
            array(
                'parts'      => array( $plain ),
                'hashes'     => array( 'payload.tar' => $cursor['payload_sha256'] ),
                'extract_to' => $target,
            ),
            $deadline
        );
        if ( null !== $result['error'] ) {
            MainWP_Child_Backup_Layout::refuse( $result['error_code'] );
        }
        $cursor['extract_cursor'] = $result['cursor'];
        if ( $result['done'] && is_file( $target . '/mainwp-layout.json' ) ) {
            unlink( $target . '/mainwp-layout.json' ); // phpcs:ignore WordPress.WP.AlternativeFunctions -- private streamed backup scratch uses native handles.
        }
        return array(
            'done'       => $result['done'],
            'cursor'     => $cursor,
            'descriptor' => $descriptor,
        );
    }

    /**
     * Validate the completed private payload once before extraction starts.
     *
     * @param string $plain Unwrapped private tar.
     * @param string $digest Expected descriptor digest.
     * @return array Validated descriptor.
     */
    private function validate_payload( $plain, $digest ) {
        $members = $this->tar_header_members( $plain );
        if ( ! is_array( $members ) ) {
            MainWP_Child_Backup_Layout::refuse();
        }
        foreach ( $members['members'] as $count ) {
            if ( 1 !== $count ) {
                MainWP_Child_Backup_Layout::refuse();
            }
        }
        try {
            $phar = new \PharData( $plain );
            if ( ! isset( $phar['mainwp-layout.json'] ) || $phar['mainwp-layout.json']->isDir() || $phar['mainwp-layout.json']->getSize() > 65536 ) {
                MainWP_Child_Backup_Layout::refuse();
            }
            $bytes = $phar['mainwp-layout.json']->getContent();
        } catch ( \Exception $error ) {
            MainWP_Child_Backup_Layout::refuse();
        }
        $descriptor = MainWP_Child_Backup_Layout::decode( $bytes, $digest );
        $roles      = array_column( $descriptor['roots'], 'coverage', 'role' );
        try {
            foreach ( new \RecursiveIteratorIterator( $phar, \RecursiveIteratorIterator::SELF_FIRST ) as $file ) {
                $identity = rtrim( substr( $file->getPathname(), strlen( 'phar://' . $phar->getPath() . '/' ) ), '/' );
                if ( 'mainwp-layout.json' === $identity ) {
                    continue;
                }
                if ( $file->isDir() ) {
                    if ( 'roots' === $identity ) {
                        continue;
                    }
                    $parts = explode( '/', $identity );
                    if ( 2 === count( $parts ) && 'roots' === $parts[0] && isset( $roles[ $parts[1] ] ) ) {
                        continue;
                    }
                }
                list( $role ) = MainWP_Child_Backup_Layout::parse_identity( $identity );
                if ( ! isset( $roles[ $role ] ) || $file->isLink() ) {
                    MainWP_Child_Backup_Layout::refuse();
                }
            }
        } catch ( \RuntimeException $error ) {
            // Phar can reject a member while advancing the iterator, before our name check.
            MainWP_Child_Backup_Layout::refuse();
        }
        return $descriptor;
    }

    /**
     * Hash the private payload once at the validation checkpoint.
     *
     * @param string $plain Unwrapped private tar.
     * @return string Payload digest.
     */
    protected function payload_digest( $plain ) {
        $digest = hash_file( 'sha256', $plain );
        if ( false === $digest ) {
            MainWP_Child_Backup_Layout::refuse();
        }
        return $digest;
    }

    /**
     * Qualified scratch names must survive without legacy clone aliases.
     *
     * @param string $name Archive member name.
     * @param array  $real_htaccess_entries Unused legacy alias map.
     * @return string Original qualified member name.
     */
    protected static function map_entry_name( $name, $real_htaccess_entries = array() ) {
        return $name;
    }

    /**
     * Scratch extraction retains all verified members; live preservation is separate.
     *
     * @param string $name Verified qualified member name.
     * @return bool No live-plugin exclusion is applied to scratch.
     */
    protected static function should_skip_entry( $name ) {
        return false;
    }
}
