<?php
/**
 * Custom Reinstaller Handler
 *
 * @package MainWP/Child
 */

namespace MainWP\Child;

/**
 * Class MainWP_Child_Custom_Reinstaller
 *
 * @package MainWP\Child
 */
class MainWP_Child_Custom_Reinstaller { // phpcs:ignore Generic.Classes.OpeningBraceSameLine.ContentAfterBrace -- NOSONAR.

    // phpcs:disable WordPress.WP.AlternativeFunctions -- use system functions

    /**
     * Private static variable to hold the single instance of the class.
     *
     * @static
     *
     * @var mixed Default null
     */
    private static $instance = null;

    /**
     * Method instance()
     *
     * Create public static instance.
     *
     * @static
     * @return MainWP_Child_Custom_Reinstaller
     */
    public static function instance() {
        if ( null === static::$instance ) {
            static::$instance = new self();
        }

        return static::$instance;
    }

    /**
     * MainWP_Custom_Reinstaller constructor.
     *
     * Run each time the class is called.
     *
     * @uses \MainWP\Dashboard\MainWP_Extensions_Handler::get_class_name()
     */
    public function __construct() {
        // Constructor code here.
    }

    /**
     * Add a "Reinstall" action link to the plugin action links.
     *
     * @param array $links Array of existing action links for the plugin.
     * @return array Modified array of action links including the reinstall link.
     */
    public function reinstall_actions_link( $links ) {
        $url                = wp_nonce_url(
            add_query_arg(
                array(
                    'action' => 'reinstall_stable',
                    'plugin' => plugin_basename( MAINWP_CHILD_FILE ),
                ),
                admin_url( 'plugins.php' )
            ),
            'reinstall_stable'
        );
        $links['reinstall'] = '<a href="' . esc_url( $url ) . '">' . esc_html__( 'Reinstall', 'mainwp-child' ) . '</a>';
        return $links;
    }

    /**
     * Method handle_reinstall_request()
     */
    public function handle_reinstall_request() { // phpcs:ignore Generic.CodeAnalysis.UselessOverridingMethod -- NOSONAR.
        if ( ! ( isset( $_GET['action'] ) && 'reinstall_stable' === $_GET['action'] ) ) {
            return;
        }
        check_admin_referer( 'reinstall_stable' );

        if ( ! current_user_can( 'install_plugins' ) ) {
            wp_die( 'No permission.' );
        }

        $plugin = sanitize_text_field( wp_unslash( $_GET['plugin'] ?? '' ) );

        if ( empty( $plugin ) ) {
            wp_safe_redirect( admin_url( 'plugins.php?reinstall=error' ) );
            exit;
        }

        // --- safer reinstall: move to backup, install, restore on fail, then activate ---

        // $plugin expected like "mainwp/mainwp.php" and $was_active boolean available.

        require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php'; //phpcs:ignore -- NOSONAR - ok.
        require_once ABSPATH . 'wp-admin/includes/plugin.php'; //phpcs:ignore -- NOSONAR - ok.
        require_once ABSPATH . 'wp-admin/includes/file.php'; //phpcs:ignore -- NOSONAR - ok.

        // Check if active.
        $was_active = is_plugin_active( $plugin );
        if ( $was_active ) {
            deactivate_plugins( $plugin );
        }
        // Determine plugin dir and slug.
        $parts      = explode( '/', $plugin );
        $slug       = $parts[0] ?? '';
        $plugin_dir = WP_PLUGIN_DIR . '/' . $slug;

        // Prepare backup.
        $backup_root = WP_CONTENT_DIR . '/plugin-backups';
        if ( ! file_exists( $backup_root ) ) {
            wp_mkdir_p( $backup_root );
        }
        $timestamp   = time();
        $backup_name = ( $slug ? $slug : 'plugin' ) . '-' . gmdate( 'Ymd-His', $timestamp );
        $backup_path = trailingslashit( $backup_root ) . $backup_name;

        // 1) move plugin dir to backup (atomic rename preferred)
        $moved_to_backup = false;
        if ( is_dir( $plugin_dir ) ) {
            if ( @rename( $plugin_dir, $backup_path ) ) {
                $moved_to_backup = true;
                self::plugin_reinstall_log( $plugin, 'moved_to_backup', 'Renamed to backup', array( 'backup_path' => $backup_path ) );
            } else {
                // Try recursive copy then we'll delete original (best-effort)
                $rcopy = function ( $src, $dst ) use ( &$rcopy ) {
                    if ( is_file( $src ) ) {
                        $dstdir = dirname( $dst );
                        if ( ! file_exists( $dstdir ) ) {
                            wp_mkdir_p( $dstdir );
                        }
                        return @copy( $src, $dst );
                    }
                    if ( ! is_dir( $dst ) ) {
                        wp_mkdir_p( $dst );
                    }
                    $dh = opendir( $src );
                    if ( ! $dh ) {
                        return false;
                    }
                    $ok = true;
                    while ( false !== ( $file = readdir( $dh ) ) ) {
                        if ( '.' === $file || '..' === $file ) {
                            continue;
                        }
                        $s = $src . DIRECTORY_SEPARATOR . $file;
                        $d = $dst . DIRECTORY_SEPARATOR . $file;
                        if ( is_dir( $s ) ) {
                            $ok = $ok && $rcopy( $s, $d );
                        } else {
                            $dstdir = dirname( $d );
                            if ( ! file_exists( $dstdir ) ) {
                                wp_mkdir_p( $dstdir );
                            }
                            $ok = $ok && @copy( $s, $d );
                        }
                    }
                    closedir( $dh );
                    return $ok;
                };

                $rrmdir = function ( $dir ) use ( &$rrmdir ) {
                    if ( ! is_dir( $dir ) ) {
                        return false;
                    }
                    $items = scandir( $dir );
                    foreach ( $items as $item ) {
                        if ( '.' === $item || '..' === $item ) {
                            continue;
                        }
                        $path = $dir . DIRECTORY_SEPARATOR . $item;
                        if ( is_dir( $path ) ) {
                            $rrmdir( $path );
                        } else {
                            @unlink( $path );
                        }
                    }
                    return @rmdir( $dir );
                };

                if ( $rcopy( $plugin_dir, $backup_path ) ) {
                    // try remove original dir (best-effort).
                    $rrmdir( $plugin_dir );
                    $moved_to_backup = true;
                    self::plugin_reinstall_log( $plugin, 'copied_to_backup', 'Copied to backup (rename failed)', array( 'backup_path' => $backup_path ) );
                } else {
                    // Could not backup; abort and report error.
                    $msg = "Reinstall: failed to move or copy plugin directory to backup for {$plugin}";
                    self::plugin_reinstall_log( $plugin, 'backup_failed', $msg );
                    wp_safe_redirect( admin_url( 'plugins.php?reinstall=backup_failed' ) );
                    exit;
                }
            }
        } else {
            // No existing plugin dir — that's okay, just continue.
            self::plugin_reinstall_log( $plugin, 'no_existing_dir', 'No existing plugin dir to backup' );
        }

        // 2) Log a snapshot of plugin dir before install (for debugging)
        $before = @scandir( WP_PLUGIN_DIR );
        self::plugin_reinstall_log( $plugin, 'snapshot_before', 'plugins dir before install', array( 'snapshot' => $before ) );

        // 3) Install package
        $package  = 'https://downloads.wordpress.org/plugin/' . rawurlencode( $slug ) . '.zip';
        $upgrader = new \Plugin_Upgrader( new \Automatic_Upgrader_Skin() );
        $res      = $upgrader->install( esc_url_raw( $package ) );

        // Log upgrader messages and result.
        $skin_msgs = method_exists( $upgrader->skin, 'get_error_messages' ) ? $upgrader->skin->get_error_messages() : array();
        self::plugin_reinstall_log(
            $plugin,
            'upgrader_result',
            'Upgrader result',
            array(
                'result'   => $res,
                'messages' => $skin_msgs,
            )
        );

        // 4) Snapshot after install
        $after = @scandir( WP_PLUGIN_DIR );
        self::plugin_reinstall_log( $plugin, 'snapshot_after', 'plugins dir after install', array( 'snapshot' => $after ) );

        // 5) If install failed, restore backup and report
        if ( is_wp_error( $res ) || $res === false ) {
            // attempt restore if we backed up.
            if ( $moved_to_backup && is_dir( $backup_path ) ) {
                // if an install created a (possibly empty) directory at $plugin_dir, remove it first,
                if ( is_dir( $plugin_dir ) ) {
                    // best-effort removal of install directory.
                    $rrmdir = function ( $dir ) use ( &$rrmdir ) {
                        if ( ! is_dir( $dir ) ) {
                            return false;
                        }
                        $items = scandir( $dir );
                        foreach ( $items as $item ) {
                            if ( '.' === $item || '..' === $item ) {
                                continue;
                            }
                            $path = $dir . DIRECTORY_SEPARATOR . $item;
                            if ( is_dir( $path ) ) {
                                $rrmdir( $path );
                            } else {
                                @unlink( $path );
                            }
                        }
                        return @rmdir( $dir );
                    };
                    $rrmdir( $plugin_dir );
                }

                // try rename backup back.
                if ( @rename( $backup_path, $plugin_dir ) ) {
                    self::plugin_reinstall_log( $plugin, 'restored_backup', 'Restored backup after install failed', array( 'backup_path' => $backup_path ) );
                } else {
                    // copy back as fallback.
                    $rcopy2 = function ( $src, $dst ) use ( &$rcopy2 ) {
                        if ( is_file( $src ) ) {
                            $dstdir = dirname( $dst );
                            if ( ! file_exists( $dstdir ) ) {
                                wp_mkdir_p( $dstdir );
                            }
                            return @copy( $src, $dst );
                        }
                        if ( ! is_dir( $dst ) ) {
                            wp_mkdir_p( $dst );
                        }
                        $dh = opendir( $src );
                        if ( ! $dh ) {
                            return false;
                        }
                        $ok = true;
                        while ( false !== ( $f = readdir( $dh ) ) ) {
                            if ( '.' === $f || '..' === $f ) {
                                continue;
                            }
                            $s = $src . DIRECTORY_SEPARATOR . $f;
                            $d = $dst . DIRECTORY_SEPARATOR . $f;
                            if ( is_dir( $s ) ) {
                                $ok = $ok && $rcopy2( $s, $d );
                            } else {
                                $dstdir = dirname( $d );
                                if ( ! file_exists( $dstdir ) ) {
                                    wp_mkdir_p( $dstdir );
                                }
                                $ok = $ok && @copy( $s, $d );
                            }
                        }
                        closedir( $dh );
                        return $ok;
                    };
                    if ( $rcopy2( $backup_path, $plugin_dir ) ) {
                        self::plugin_reinstall_log( $plugin, 'copied_backup_restored', 'Copied backup back after install failed', array( 'backup_path' => $backup_path ) );
                    } else {
                        self::plugin_reinstall_log( $plugin, 'restore_failed', 'Failed to restore backup', array( 'backup_path' => $backup_path ) );
                    }
                }
            }

            wp_safe_redirect( admin_url( 'plugins.php?reinstall=install_failed' ) );
            exit;
        }

        // 6) Install succeeded — locate installed plugin main file
        $found = null;
        if ( method_exists( $this, 'locate_installed_plugin_basename_recursive' ) ) {
            $found = self::locate_installed_plugin_basename_recursive( $plugin, 600, 6 );
        }
        if ( ! $found && method_exists( $this, 'locate_installed_plugin_basename' ) && $slug ) {
            $found = self::locate_installed_plugin_basename( $slug );
        }

        // final fallback: scan recently modified plugin files.
        if ( ! $found ) {
            $now         = time();
            $all_plugins = get_plugins();
            foreach ( $all_plugins as $basename => $data ) {
                $full  = WP_PLUGIN_DIR . '/' . $basename;
                $mtime = @filemtime( $full );
                if ( $mtime !== false && ( $now - $mtime ) <= 600 ) {
                    $found = $basename;
                    break;
                }
            }
        }

        // If not found, keep backup (do not delete) and report missing main.
        if ( ! $found ) {
            $msg = "Reinstall: install completed but unable to locate main plugin file for expected {$plugin}. Backup kept at {$backup_path}.";
            self::plugin_reinstall_log( $plugin, 'installed_missing_main_but_backup', $msg, array( 'backup_path' => $backup_path ) );
            // return backup path in URL so admin can inspect.
            wp_safe_redirect( admin_url( 'plugins.php?reinstall=installed_missing_main&backup=' . rawurlencode( $backup_path ) ) );
            exit;
        }

        // 7) Activation if needed
        if ( $was_active ) {
            $act = activate_plugin( $found );
            if ( is_wp_error( $act ) ) {
                // leave backup in place for debug.
                $msg = "Reinstall: installed but activation failed for {$found}. Backup kept at {$backup_path}. error: " . $act->get_error_message();
                self::plugin_reinstall_log(
                    $plugin,
                    'activate_failed',
                    $msg,
                    array(
                        'backup_path'    => $backup_path,
                        'activate_error' => $act->get_error_messages(),
                    )
                );
                wp_safe_redirect( admin_url( 'plugins.php?reinstall=installed_but_activate_failed&plugin=' . rawurlencode( $found ) . '&backup=' . rawurlencode( $backup_path ) ) );
                exit;
            }
        }

        // After successful install & activation
        // $backup_path is the absolute path that was created earlier (e.g. WP_CONTENT_DIR . '/plugin-backups/<name>').
        if ( ! empty( $backup_path ) ) {
            // Attempt to delete the backup; ignore result but log it.
            $deleted = self::delete_plugin_backup_dir( $backup_path );
            if ( $deleted ) {
                // optional: add an extra log entry.
                self::plugin_reinstall_log( $plugin, 'backup_removed_after_success', 'Backup removed after successful reinstall', array( 'backup_path' => $backup_path ) );
            }
        }

        // then final redirect.
        wp_safe_redirect( admin_url( 'plugins.php?reinstall=success' ) );
        exit;
    }

    /**
     * Locate installed plugin basename by slug.
     *
     * @param string $slug The plugin slug (e.g., 'mainwp').
     * @return string|false Plugin basename (e.g., 'mainwp/mainwp.php') or false if not found.
     */
    public function locate_installed_plugin_basename( $slug ) {
        if ( ! function_exists( 'get_plugins' ) ) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }

        $plugins = get_plugins();

        foreach ( $plugins as $basename => $data ) {
            // Extract folder from basename: "mainwp/mainwp.php" → "mainwp"
            $folder = dirname( $basename );
            if ( $folder === $slug || strpos( $basename, $slug . '/' ) === 0 ) {
                return $basename;
            }
        }

        return false;
    }


    /**
     * More aggressive locator: recursively scan newly-modified plugin directories and files
     * to find any PHP file containing a valid plugin header. Returns plugin basename like
     * "dir/file.php" or "file.php" or false if not found.
     *
     * @param string $expected_basename e.g. "my-plugin/my-plugin.php"
     * @param int    $new_install_window_secs time window (seconds) to consider directories "new"
     * @param int    $max_depth recursion depth when scanning directories
     * @return string|false
     */
    public static function locate_installed_plugin_basename_recursive( $expected_basename, $new_install_window_secs = 600, $max_depth = 6 ) {
        $expected_basename = trim( (string) $expected_basename );
        if ( empty( $expected_basename ) ) {
            return false;
        }

        // quick return if expected exists.
        if ( file_exists( WP_PLUGIN_DIR . '/' . $expected_basename ) ) {
            return $expected_basename;
        }

        $now   = time();
        $parts = explode( '/', $expected_basename );
        $slug  = $parts[0] ?? '';

        // helper: check a given PHP file for plugin header.
        $has_plugin_header = function ( $file ) {
            if ( ! is_readable( $file ) ) {
                return false;
            }
            $headers = get_file_data( $file, array( 'Name' => 'Plugin Name' ), 'plugin' );
            $name    = trim( (string) ( $headers['Name'] ?? '' ) );
            return ! empty( $name );
        };

        // helper: recursively scan a directory up to $depth for any php file with plugin header.
        $recursive_scan_dir = function ( $dir, $depth ) use ( &$recursive_scan_dir, $has_plugin_header ) {
            if ( $depth < 0 ) {
                return false;
            }
            if ( ! is_dir( $dir ) ) {
                return false;
            }
            $dh = @opendir( $dir );
            if ( ! $dh ) {
                return false;
            }
            while ( false !== ( $entry = readdir( $dh ) ) ) {
                if ( '.' === $entry || '..' === $entry ) {
                    continue;
                }
                $path = $dir . DIRECTORY_SEPARATOR . $entry;
                if ( is_file( $path ) && ( substr( $entry, -4 ) === '.php' ) ) {
                    if ( $has_plugin_header( $path ) ) {
                        closedir( $dh );
                        // return path relative to WP_PLUGIN_DIR.
                        return ltrim( str_replace( WP_PLUGIN_DIR, '', $path ), '/\\' );
                    }
                } elseif ( is_dir( $path ) ) {
                    $found = $recursive_scan_dir( $path, $depth - 1 );
                    if ( $found ) {
                        closedir( $dh );
                        return $found;
                    }
                }
            }
            closedir( $dh );
            return false;
        };

        $candidates_checked = array();

        // 1) If slug dir exists, scan it deeply first (most common)
        if ( $slug && is_dir( WP_PLUGIN_DIR . '/' . $slug ) ) {
            $candidates_checked[] = $slug;
            $scan                 = $recursive_scan_dir( WP_PLUGIN_DIR . '/' . $slug, $max_depth );
            if ( $scan ) {
                // ensure returned format is dirname/file.php.
                if ( strpos( $scan, '/' ) === false ) {
                    $scan = $slug . '/' . $scan;
                }
                self::plugin_reinstall_log( $expected_basename, 'locate_found', 'Found inside slug dir', array( 'found' => $scan ) );
                return $scan;
            }
        }

        // 2) Look for recently modified directories and scan each recursively
        $dirs = @scandir( WP_PLUGIN_DIR );
        if ( $dirs && is_array( $dirs ) ) {
            foreach ( $dirs as $d ) {
                if ( '.' === $d || '..' === $d ) {
                    continue;
                }
                $path = WP_PLUGIN_DIR . '/' . $d;
                if ( ! is_dir( $path ) ) {
                    continue;
                }
                $mtime = @filemtime( $path );
                if ( $mtime === false ) {
                    $mtime = 0;
                }
                // only consider directories modified recently (or the slug which we already checked)
                if ( ( $now - $mtime ) <= $new_install_window_secs ) {
                    // avoid rescanning slug if already done
                    if ( in_array( $d, $candidates_checked, true ) ) {
                        continue;
                    }
                    $candidates_checked[] = $d;
                    $scan                 = $recursive_scan_dir( $path, $max_depth );
                    if ( $scan ) {
                        if ( strpos( $scan, '/' ) === false ) {
                            $scan = $d . '/' . $scan;
                        }
                        self::plugin_reinstall_log(
                            $expected_basename,
                            'locate_found',
                            'Found in recent dir',
                            array(
                                'found' => $scan,
                                'dir'   => $d,
                            )
                        );
                        return $scan;
                    }
                }
            }
        }

        // 3) Fallback: scan whole plugins dir (heavy) but limited by depth and only for directories we haven't checked
        // Use this as last resort because it can be slow on large sites.
        $dirs = @scandir( WP_PLUGIN_DIR );
        if ( $dirs && is_array( $dirs ) ) {
            foreach ( $dirs as $d ) {
                if ( '.' === $d || '..' === $d ) {
                    continue;
                }
                if ( in_array( $d, $candidates_checked, true ) ) {
                    continue;
                }
                $path = WP_PLUGIN_DIR . '/' . $d;
                if ( ! is_dir( $path ) ) {
                    continue;
                }
                $scan = $recursive_scan_dir( $path, $max_depth );
                if ( $scan ) {
                    if ( strpos( $scan, '/' ) === false ) {
                        $scan = $d . '/' . $scan;
                    }
                    self::plugin_reinstall_log(
                        $expected_basename,
                        'locate_found',
                        'Found during full scan',
                        array(
                            'found' => $scan,
                            'dir'   => $d,
                        )
                    );
                    return $scan;
                }
            }
        }

        // 4) Finally check root-level PHP plugin files (single-file plugins)
        $all_plugins = get_plugins();
        foreach ( $all_plugins as $basename => $data ) {
            if ( strpos( $basename, '/' ) === false ) {
                $full  = WP_PLUGIN_DIR . '/' . $basename;
                $mtime = @filemtime( $full );
                if ( $mtime !== false && ( $now - $mtime ) <= $new_install_window_secs ) {
                    self::plugin_reinstall_log( $expected_basename, 'locate_found', 'Found root-level file', array( 'found' => $basename ) );
                    return $basename;
                }
            }
        }

        // Nothing found — log diagnostics
        self::plugin_reinstall_log( $expected_basename, 'locate_not_found', 'Could not locate installed plugin file', array( 'checked_dirs' => $candidates_checked ) );

        return false;
    }

    /**
     * Delete a plugin backup directory (safe).
     *
     * @param string $backup_path Absolute path to backup directory.
     * @return bool True on success or if dir doesn't exist, false on failure.
     */
    protected static function delete_plugin_backup_dir( $backup_path ) { // phpcs:ignore Generic.CodeAnalysis.UselessOverridingMethod -- NOSONAR.
        $backup_path = wp_normalize_path( (string) $backup_path );
        if ( empty( $backup_path ) ) {
            return false;
        }

        // Ensure path is inside expected backup root to avoid accidental deletes.
        $backup_root = wp_normalize_path( WP_CONTENT_DIR . '/plugin-backups' );
        if ( strpos( $backup_path, $backup_root ) !== 0 ) {
            self::plugin_reinstall_log( 'backup_cleanup', 'delete_refused', 'Refused to delete outside backup root', array( 'path' => $backup_path ) );
            return false;
        }

        // If it doesn't exist already, treat as success.
        if ( ! is_dir( $backup_path ) ) {
            self::plugin_reinstall_log( 'backup_cleanup', 'not_found', 'Backup not found (already removed)', array( 'path' => $backup_path ) );
            return true;
        }

        // Try WP_Filesystem (preferred).
        MainWP_Helper::get_wp_filesystem();

        global $wp_filesystem;

        if ( isset( $wp_filesystem ) && is_object( $wp_filesystem ) && $wp_filesystem->is_dir( $backup_path ) ) {
            // rmdir with recursive=true.
            $ok = (bool) $wp_filesystem->rmdir( $backup_path, true );
            if ( $ok ) {
                self::plugin_reinstall_log( 'backup_cleanup', 'deleted', 'Deleted backup via WP_Filesystem', array( 'path' => $backup_path ) );
                return true;
            }
            // fall through to PHP fallback.
        }

        // PHP recursive delete fallback (best-effort).
        $rrmdir = function ( $dir ) use ( &$rrmdir ) {
            if ( ! is_dir( $dir ) ) {
                return false;
            }
            $items = scandir( $dir );
            foreach ( $items as $item ) {
                if ( '.' === $item || '..' === $item ) {
                    continue;
                }
                $path = $dir . DIRECTORY_SEPARATOR . $item;
                if ( is_dir( $path ) ) {
                    $rrmdir( $path );
                } else {
                    @unlink( $path );
                }
            }
            return @rmdir( $dir );
        };

        $ok = $rrmdir( $backup_path );
        if ( $ok ) {
            self::plugin_reinstall_log( 'backup_cleanup', 'deleted_fallback', 'Deleted backup via PHP fallback', array( 'path' => $backup_path ) );
            return true;
        }

        // failed.
        self::plugin_reinstall_log( 'backup_cleanup', 'delete_failed', 'Failed to delete backup directory', array( 'path' => $backup_path ) );
        return false;
    }


    /**
     * Method reinstall_admin_notices()
     */
    public function reinstall_admin_notices() {
        if ( empty( $_GET['reinstall'] ) ) { //phpcs:ignore WordPress.Security.NonceVerification,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
            return;
        }
        $code  = sanitize_text_field( wp_unslash( $_GET['reinstall'] ) ); //phpcs:ignore WordPress.Security.NonceVerification,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
        $msgs  = array(
            'success'                       => __( 'Plugin reinstalled successfully.', 'mainwp-child' ),
            'delete_failed'                 => __( 'Failed to remove old plugin files.', 'mainwp-child' ),
            'install_failed'                => __( 'Failed to install plugin from WordPress.org.', 'mainwp-child' ),
            'installed_but_activate_failed' => __( 'Installed but activation failed.', 'mainwp-child' ),
            'error'                         => __( 'Reinstall failed (invalid plugin).', 'mainwp-child' ),
        );
        $class = strpos( $code, 'success' ) === 0 ? 'infor' : 'red';
        $text  = $msgs[ $code ] ?? __( 'Unknown status.', 'mainwp-child' );
        echo '<div class="ui message ' . esc_attr( $class ) . '"><p>' . esc_html( $text ) . '</p></div>';
    }

    /**
     * Simple logger helper that records into an option (capped) and also supports message context.
     *
     * @param string $plugin_basename Plugin basename (e.g., 'mainwp/mainwp.php').
     * @param string $status         Log status identifier.
     * @param string $message        Human-readable message describing the event.
     * @param array  $data           Optional context data for diagnostics.
     * @return void
     */
    protected static function plugin_reinstall_log( $plugin_basename, $status, $message, $data = array() ) {

        if ( ! apply_filters( 'mainwp_child_reinstall_enable_logs', false ) ) {
            return;
        }

        $logs = (array) get_option( 'mainwp_plugin_reinstall_logs', array() );

        $entry = array(
            'time'           => time(),
            'plugin'         => $plugin_basename,
            'status'         => $status,
            'message'        => $message,
            'data'           => $data,
            'server_request' => $_SERVER ?? array(),
        );

        // push to head.
        array_unshift( $logs, $entry );

        // cap logs to last 50 entries.
        $logs = array_slice( $logs, 0, 50 );

        update_option( 'mainwp_child_plugin_reinstall_logs', $logs );
    }
}
