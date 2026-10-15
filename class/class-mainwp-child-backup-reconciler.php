<?php
/**
 * Reconcile the live tree against the leaf snapshot's file list.
 *
 * @package MainWP\Child
 */

namespace MainWP\Child;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Uses the incremental parser so backup and restore agree on manifest rows.
 */
class MainWP_Child_Backup_Reconciler extends MainWP_Child_Backup_Incremental_Diff {

    /**
     * Uploads kept because a files-only restore retains the live database.
     *
     * @var string|null
     */
    private $uploads_relpath = null;

    /**
     * Directory containment uses the same policy as extraction and tombstones.
     *
     * @var string|null
     */
    private $mu_plugins_relpath = null;

    /**
     * Exact manifest keys whose snapshot has no archived bytes.
     *
     * @var array
     */
    private $unarchived_paths = array();

    /**
     * Invalid coverage must never become a broader deletion scope.
     *
     * @param mixed $raw Wire options.
     * @return array Valid options, a skip reason, or an empty array.
     */
    public static function sanitize_options( $raw ) {
        if ( ! is_array( $raw ) || ! isset( $raw['manifest'] ) || ! is_string( $raw['manifest'] ) || '' === $raw['manifest'] || MainWP_Helper::sanitize_filename( $raw['manifest'] ) !== $raw['manifest'] ) {
            return array();
        }
        $clean = array( 'manifest' => $raw['manifest'] );
        // The callable and restore slices sanitize separately; a refusal must survive both.
        if ( isset( $raw['skip_reason'] ) && in_array( $raw['skip_reason'], array( 'unarchived_invalid', 'unarchived_overflow' ), true ) ) {
            $clean['skip_reason'] = $raw['skip_reason'];
            return $clean;
        }
        if ( ! isset( $raw['walk'] ) || ! is_array( $raw['walk'] ) ) {
            return $clean;
        }
        $walk = $raw['walk'];
        if ( array_key_exists( 'unarchived_overflow', $walk ) ) {
            if ( ! is_bool( $walk['unarchived_overflow'] ) ) {
                $clean['skip_reason'] = 'unarchived_invalid';
                return $clean;
            }
            if ( $walk['unarchived_overflow'] ) {
                $clean['skip_reason'] = 'unarchived_overflow';
                return $clean;
            }
        }
        if ( array_key_exists( 'unarchived', $walk ) ) {
            $paths = $walk['unarchived'];
            if ( ! is_array( $paths ) || array_values( $paths ) !== $paths ) {
                $clean['skip_reason'] = 'unarchived_invalid';
                return $clean;
            }
            foreach ( $paths as $path ) {
                if ( ! is_string( $path ) || ! self::safe_relpath( $path ) || sanitize_text_field( $path ) !== $path ) {
                    $clean['skip_reason'] = 'unarchived_invalid';
                    return $clean;
                }
            }
        }
        if ( ! isset( $walk['excludes'], $walk['excludenonwp'], $walk['excludezip'] ) || ! is_array( $walk['excludes'] ) ) {
            return $clean;
        }
        foreach ( array( 'excludenonwp', 'excludezip' ) as $flag ) {
            if ( ! in_array( $walk[ $flag ], array( 0, 1, '0', '1' ), true ) ) {
                return $clean;
            }
        }
        $excludes = array();
        foreach ( $walk['excludes'] as $exclude ) {
            if ( ! is_string( $exclude ) ) {
                return $clean;
            }
            $sanitized = sanitize_text_field( rtrim( $exclude, '/' ) );
            if ( rtrim( $exclude, '/' ) !== $sanitized ) {
                return $clean;
            }
            // An empty user exclude list arrives as one empty entry; it excludes nothing and must not void the block.
            if ( '' === $sanitized ) {
                continue;
            }
            $excludes[] = $sanitized;
        }
        $clean['walk'] = array(
            'excludes'     => $excludes,
            'excludenonwp' => (int) $walk['excludenonwp'],
            'excludezip'   => (int) $walk['excludezip'],
        );
        foreach ( array( 'unarchived', 'unarchived_overflow' ) as $key ) {
            if ( array_key_exists( $key, $walk ) ) {
                $clean['walk'][ $key ] = $walk[ $key ];
            }
        }
        return $clean;
    }

    /**
     * Validate before deleting, then merge two completed sorted lists.
     *
     * @param array $cursor Persisted progress.
     * @param array $config Manifest, coverage, roots and checkpoint callback.
     * @param int   $deadline Slice deadline.
     * @throws \RuntimeException Caught locally and reported as an aborted outcome.
     * @return array Cursor, outcome and completion flags.
     */
    public function reconcile_slice( $cursor, $config, $deadline ) {
        $this->unarchived_paths   = array_fill_keys( $config['walk']['unarchived'] ?? array(), true );
        $this->mu_plugins_relpath = isset( $config['root'] ) ? MainWP_Child_Backup_Mu_Plugins::relative_directory( $config['root'], isset( $config['mu_plugins_directory'] ) ? $config['mu_plugins_directory'] : null ) : null;
        $this->uploads_relpath    = null;
        if ( isset( $config['task_type'] ) && 'restore_files' === $config['task_type'] ) {
            $uploads = wp_upload_dir( null, false );
            $root    = trailingslashit( wp_normalize_path( $config['root'] ) );
            $basedir = wp_normalize_path( $uploads['basedir'] );
            if ( 0 === strpos( $basedir, $root ) ) {
                $relative = rtrim( substr( $basedir, strlen( $root ) ), '/' );
                if ( self::safe_relpath( $relative ) ) {
                    $this->uploads_relpath = $relative;
                }
            }
        }
        $cursor   = array_merge(
            array(
                'offset'          => 0,
                'previous'        => '',
                'rows'            => 0,
                'validated'       => false,
                'walk_done'       => false,
                'live_offset'     => 0,
                'manifest_offset' => 0,
                'last'            => '',
                'removed'         => 0,
                'examined'        => 0,
                'failed'          => 0,
                'skipped_unsafe'  => 0,
                'kept'            => 0,
                'kept_by_reason'  => array(),
                'kept_paths'      => array(),
            ),
            $cursor
        );
        $manifest = fopen( $config['manifest'], 'rb' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- streaming local artifact.
        if ( false === $manifest ) {
            return $this->result(
                $cursor,
                array(
                    'status' => 'skipped',
                    'reason' => 'manifest_unverified',
                )
            );
        }
        $live = null;
        try {
            if ( ! $cursor['validated'] ) {
                if ( 0 !== fseek( $manifest, $cursor['offset'] ) ) {
                    throw new \RuntimeException( 'The manifest cursor could not be read.' );
                }
                while ( ! $this->budget_exhausted( $deadline ) ) {
                    $row = $this->read_row( $manifest );
                    if ( null === $row ) {
                        $cursor['validated'] = true;
                        break;
                    }
                    if ( '' !== $cursor['previous'] && strcmp( $cursor['previous'], $row[0] ) >= 0 ) {
                        throw new \RuntimeException( 'The manifest is not strictly sorted.' );
                    }
                    $cursor['previous'] = $row[0];
                    $cursor['offset']   = ftell( $manifest );
                    ++$cursor['rows'];
                }
                if ( ! $cursor['validated'] ) {
                    return $this->result( $cursor );
                }
                if ( 0 === $cursor['rows'] ) {
                    return $this->result(
                        $cursor,
                        array(
                            'status' => 'skipped',
                            'reason' => 'empty_manifest',
                        )
                    );
                }
            }
            if ( ! $cursor['walk_done'] ) {
                $walk   = $config['walk'];
                $walker = new MainWP_Child_Backup_File_Walker( $config['root'], $walk['excludes'], $walk['excludezip'], $walk['excludenonwp'], array( $config['backup_dir'] ) );
                $walker->set_progress_callback( $config['progress_callback'] );
                $out                   = $walker->emit_sidecars_slice( $config['scratch'], isset( $cursor['walk_cursor'] ) ? $cursor['walk_cursor'] : null, $deadline );
                $cursor['walk_cursor'] = $out['cursor'];
                $cursor['walk_done']   = ! empty( $out['done'] );
                // Persist the sorted input before any deletion can change the live walk.
                return $this->result( $cursor );
            }

            $live = fopen( $config['scratch'] . MainWP_Child_Backup_File_Walker::FILES_LIST . MainWP_Child_Backup_File_Walker::STAGED_SUFFIX, 'rb' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- streaming local sidecar.
            if ( false === $live || 0 !== fseek( $live, $cursor['live_offset'] ) || 0 !== fseek( $manifest, $cursor['manifest_offset'] ) ) {
                throw new \RuntimeException( 'The reconciliation cursor could not be read.' );
            }
            $expected_offset    = ftell( $manifest );
            $expected           = $this->read_row( $manifest );
            $checkpoint_changes = $cursor['removed'] + $cursor['failed'];
            while ( ! $this->budget_exhausted( $deadline ) ) {
                $row = $this->read_row( $live );
                if ( null === $row ) {
                    $outcome = array(
                        'status'   => 'done',
                        'removed'  => $cursor['removed'],
                        'examined' => $cursor['examined'],
                    );
                    if ( $cursor['failed'] ) {
                        $outcome['failed'] = $cursor['failed'];
                    }
                    if ( $cursor['skipped_unsafe'] ) {
                        $outcome['skipped_unsafe'] = $cursor['skipped_unsafe'];
                    }
                    return $this->result( $cursor, $outcome );
                }
                while ( null !== $expected && strcmp( $expected[0], $row[0] ) < 0 ) {
                    if ( $this->budget_exhausted( $deadline ) ) {
                        $cursor['manifest_offset'] = $expected_offset;
                        return $this->result( $cursor );
                    }
                    $expected_offset = ftell( $manifest );
                    $expected        = $this->read_row( $manifest );
                }
                if ( '' !== $cursor['last'] && strcmp( $cursor['last'], $row[0] ) >= 0 ) {
                    throw new \RuntimeException( 'The live file list is not strictly sorted.' );
                }
                if ( ! self::safe_relpath( $row[0] ) ) {
                    ++$cursor['skipped_unsafe'];
                } elseif ( null === $expected || $expected[0] !== $row[0] ) {
                    $this->remove_candidate( $row[0], $config['root'], $cursor );
                }
                ++$cursor['examined'];
                $cursor['last']            = $row[0];
                $cursor['live_offset']     = ftell( $live );
                $cursor['manifest_offset'] = $expected_offset;
                $changes                   = $cursor['removed'] + $cursor['failed'];
                $checkpoint                = $changes - $checkpoint_changes >= 100 || 0 === $cursor['examined'] % 500;
                if ( $checkpoint ) {
                    if ( ! call_user_func( $config['checkpoint_cursor'], $cursor ) ) {
                        $out            = $this->result( $cursor );
                        $out['abandon'] = true;
                        return $out;
                    }
                    $checkpoint_changes = $changes;
                }
            }
            return $this->result( $cursor );
        } catch ( \Throwable $e ) {
            return $this->result(
                $cursor,
                array(
                    'status' => 'aborted',
                    'reason' => 'unsafe_manifest_or_path',
                )
            );
        } finally {
            fclose( $manifest ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- streaming local artifact.
            if ( is_resource( $live ) ) {
                fclose( $live ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- streaming local sidecar.
            }
        }
    }

    /**
     * A failed read cannot be interpreted as the end of a deletion authority.
     *
     * @param resource $handle Open list.
     * @return array|null Parsed row, or EOF.
     * @throws \RuntimeException On malformed input.
     */
    private function read_row( $handle ) {
        $line = fgets( $handle );
        if ( false === $line ) {
            if ( ! feof( $handle ) ) {
                throw new \RuntimeException( 'Could not read a reconciliation list.' );
            }
            return null;
        }
        $row = $this->parse_list_line( rtrim( $line, "\r\n" ) );
        if ( null === $row ) {
            throw new \RuntimeException( 'The reconciliation list contains a malformed entry.' );
        }
        return $row;
    }

    /**
     * Reject alternate path spellings before filesystem access.
     *
     * @param string $path Site-relative path.
     * @return bool Whether the path is safe.
     */
    private static function safe_relpath( $path ) {
        if ( '' === $path || '/' === $path[0] || false !== strpos( $path, '\\' ) ) {
            return false;
        }
        if ( preg_match( '/^[a-z]:/i', $path ) || preg_match( '/[\x00-\x1f\x7f]/', $path ) ) {
            return false;
        }
        return ! in_array( '..', explode( '/', $path ), true );
    }

    /**
     * Restore infrastructure must survive even an old or incomplete exclude list.
     * object-cache.php is knowingly kept as a host-managed drop-in, even if stale or malicious.
     * advanced-cache.php and db.php remain deletable: these plugin-written drop-ins can fatal without their plugins.
     *
     * @param string $path Site-relative path.
     * @return bool Whether deletion is forbidden.
     */
    protected function exempt( $path ) {
        if ( null !== $this->uploads_relpath && ( $path === $this->uploads_relpath || 0 === strpos( $path, $this->uploads_relpath . '/' ) ) ) {
            return true;
        }
        $mainwp = MainWP_Helper::get_mainwp_dir();
        $paths  = array(
            'wp-config.php',
            'wp-content/object-cache.php',
            'wp-content/uploads/mainwp',
            rtrim( str_replace( wp_normalize_path( ABSPATH ), '', wp_normalize_path( MAINWP_CHILD_PLUGIN_DIR ) ), '/' ),
            rtrim( str_replace( wp_normalize_path( ABSPATH ), '', wp_normalize_path( $mainwp[0] ) ), '/' ),
            str_replace( wp_normalize_path( ABSPATH ), '', wp_normalize_path( WP_CONTENT_DIR ) ) . '/object-cache.php',
        );
        if ( MainWP_Helper::in_excludes( $paths, $path ) || MainWP_Helper::is_dump_litter( $path ) ) {
            return true;
        }
        foreach ( explode( '/', $path ) as $part ) {
            if ( MainWP_Child_Backup_File_Restorer::is_reserved_temp_name( $part ) ) {
                return true;
            }
        }
        return false;
    }

    /**
     * Recheck containment after enumeration because the live tree can change.
     *
     * @param string $path Site-relative candidate.
     * @param string $root Site root.
     * @param array  $cursor Running counts.
     * @throws \RuntimeException On an unsafe deletion path.
     */
    private function remove_candidate( $path, $root, &$cursor ) {
        if ( $this->exempt( $path ) ) {
            return;
        }
        if ( ! self::safe_relpath( $path ) ) {
            throw new \RuntimeException( 'Unsafe reconciliation path.' );
        }
        $target = trailingslashit( $root ) . $path;
        clearstatcache( true, $target );
        if ( is_link( $target ) || ! is_file( $target ) ) {
            return;
        }
        $real_root   = realpath( $root );
        $real_parent = realpath( dirname( $target ) );
        if ( false === $real_root || false === $real_parent || 0 !== strpos( $real_parent . '/', $real_root . '/' ) ) {
            throw new \RuntimeException( 'Reconciliation path leaves the site root.' );
        }
        $parent        = dirname( $target );
        $root_length   = strlen( rtrim( $root, '/' ) );
        $parent_length = strlen( $parent );
        while ( $parent_length > $root_length ) {
            if ( is_link( $parent ) ) {
                return;
            }
            $parent        = dirname( $parent );
            $parent_length = strlen( $parent );
        }
        // Mu-plugins takes precedence so overlapping protections count the file once.
        $reason = null;
        if ( MainWP_Child_Backup_Mu_Plugins::contains( $path, $this->mu_plugins_relpath ) ) {
            $reason = 'mu_plugins';
        } elseif ( isset( $this->unarchived_paths[ $path ] ) ) {
            $reason = 'unarchived';
        }
        if ( null !== $reason ) {
            ++$cursor['kept'];
            $cursor['kept_by_reason'][ $reason ] = isset( $cursor['kept_by_reason'][ $reason ] ) ? $cursor['kept_by_reason'][ $reason ] + 1 : 1;
            if ( count( $cursor['kept_paths'] ) < 20 ) {
                $cursor['kept_paths'][] = $path;
            }
            return;
        }
        if ( @unlink( $target ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.unlink_unlink -- count failures without corrupting the response with warnings.
            ++$cursor['removed'];
        } else {
            ++$cursor['failed'];
        }
    }

    /**
     * Match the task's slice deadline; overridable for deterministic slice tests.
     *
     * @param int $deadline Slice deadline.
     * @return bool Whether to yield.
     */
    protected function budget_exhausted( $deadline ) {
        return time() >= $deadline;
    }

    /**
     * Keep progress separate from the public verification outcome.
     *
     * @param array      $cursor Resume cursor.
     * @param array|null $outcome Terminal outcome.
     * @return array Slice result.
     */
    private function result( $cursor, $outcome = null ) {
        if ( null !== $outcome && ! empty( $cursor['kept'] ) ) {
            $outcome['kept']           = $cursor['kept'];
            $outcome['kept_by_reason'] = $cursor['kept_by_reason'];
            $outcome['kept_paths']     = $cursor['kept_paths'];
        }
        return array(
            'cursor'  => $cursor,
            'done'    => null !== $outcome,
            'outcome' => $outcome,
            'abandon' => false,
        );
    }
}
