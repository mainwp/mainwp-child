<?php
/**
 * Preserve dependencies of configuration that the snapshot cannot replace.
 *
 * @package MainWP\Child
 */

namespace MainWP\Child;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Keep restore slices independent of the PHP files loaded by their current request.
 */
class MainWP_Child_Backup_Config_Includes extends MainWP_Child_Backup_Incremental_Diff {

    const MAX_PATHS = 100;
    const MAX_BYTES = 1048576;

    /**
     * WordPress also accepts a parent config when the parent is not another install.
     *
     * @param string $root Site root.
     * @return string|null Live config path.
     */
    public static function locate( $root ) {
        $root = untrailingslashit( $root );
        if ( file_exists( $root . '/wp-config.php' ) ) {
            return $root . '/wp-config.php';
        }
        $parent = dirname( $root );
        return file_exists( $parent . '/wp-config.php' ) && ! file_exists( $parent . '/wp-settings.php' ) ? $parent . '/wp-config.php' : null;
    }

    /**
     * Inspect PHP tokens without running any configuration code.
     *
     * @param string $file Live config.
     * @param string $root Site root used for ABSPATH.
     * @return array Absolute candidates and unresolved statement count.
     * @throws \RuntimeException Caught locally when config bytes are unavailable.
     */
    public function scan( $file, $root ) {
        $result = array(
            'paths'      => array(),
            'unresolved' => 0,
        );
        try {
            // PHP resolves config symlinks before assigning __DIR__ and __FILE__.
            $file = realpath( $file );
            if ( false === $file ) {
                throw new \RuntimeException( 'Config unavailable.' );
            }
            $source = $this->read_config( $file );
            if ( false === $source || strlen( $source ) > self::MAX_BYTES ) {
                throw new \RuntimeException( 'Config unavailable.' );
            }
            $tokens = $this->tokenize( $source );
            $count  = count( $tokens );
            for ( $i = 0; $i < $count; ++$i ) {
                if ( ! is_array( $tokens[ $i ] ) || ! in_array( $tokens[ $i ][0], array( T_REQUIRE, T_REQUIRE_ONCE, T_INCLUDE, T_INCLUDE_ONCE ), true ) ) {
                    continue;
                }
                $expression = array();
                for ( $j = $i + 1; $j < $count; ++$j ) {
                    $token = $tokens[ $j ];
                    if ( ';' === $token || ( is_array( $token ) && T_CLOSE_TAG === $token[0] ) ) {
                        break;
                    }
                    if ( ! is_array( $token ) || ! in_array( $token[0], array( T_WHITESPACE, T_COMMENT, T_DOC_COMMENT ), true ) ) {
                        $expression[] = $token;
                    }
                }
                $path = $this->resolve_expression( $expression, $root, dirname( $file ) );
                if ( null === $path ) {
                    ++$result['unresolved'];
                } elseif ( $this->is_absolute( $path ) ) {
                    $result['paths'][] = $path;
                } else {
                    $result['paths'][] = trailingslashit( $root ) . $path;
                    $result['paths'][] = dirname( $file ) . '/' . $path;
                }
            }
        } catch ( \Throwable $e ) {
            $this->debug( '[MainWP Backup] Config includes: scan_failed=1 unresolved=0 skipped=0' );
            return array(
                'paths'      => array(),
                'unresolved' => 0,
            );
        }
        return $result;
    }

    /**
     * Bound the read even if the config grows between filesystem checks.
     *
     * @param string $file Config path.
     * @return string|false Config bytes.
     */
    protected function read_config( $file ) {
        return @file_get_contents( $file, false, null, 0, self::MAX_BYTES + 1 ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents, WordPress.PHP.NoSilencedErrors.Discouraged -- bounded local read; failure is logged without config contents.
    }

    /**
     * Parse errors must discard the scan rather than preserve a partial result.
     *
     * @param string $source Config bytes.
     * @return array PHP tokens.
     * @throws \RuntimeException When the tokenizer is unavailable.
     */
    protected function tokenize( $source ) {
        if ( ! function_exists( 'token_get_all' ) ) {
            throw new \RuntimeException( 'Tokenizer unavailable.' );
        }
        return token_get_all( $source, TOKEN_PARSE );
    }

    /**
     * Accept only concatenated literals and the config's two directory anchors.
     *
     * @param array  $tokens Expression tokens without comments or whitespace.
     * @param string $root Site root.
     * @param string $directory Config directory.
     * @return string|null Resolved path, or null for a dynamic expression.
     */
    private function resolve_expression( $tokens, $root, $directory ) {
        if ( '(' === reset( $tokens ) && ')' === end( $tokens ) ) {
            $tokens = array_slice( $tokens, 1, -1 );
        }
        $path = '';
        $size = count( $tokens );
        for ( $i = 0; $i < $size; ++$i ) {
            $token = $tokens[ $i ];
            if ( ! is_array( $token ) ) {
                return null;
            }
            if ( T_CONSTANT_ENCAPSED_STRING === $token[0] ) {
                $path .= $this->literal( $token[1] );
            } elseif ( T_DIR === $token[0] ) {
                $path .= $directory;
            } elseif ( T_STRING === $token[0] && 'ABSPATH' === $token[1] ) {
                $path .= trailingslashit( $root );
            } elseif ( T_STRING === $token[0] && 'dirname' === strtolower( $token[1] ) && '(' === ( $tokens[ $i + 1 ] ?? null ) && is_array( $tokens[ $i + 2 ] ?? null ) && T_FILE === $tokens[ $i + 2 ][0] && ')' === ( $tokens[ $i + 3 ] ?? null ) ) {
                $path .= $directory;
                $i    += 3;
            } else {
                return null;
            }
            if ( $i + 1 < $size && '.' !== $tokens[ ++$i ] ) {
                return null;
            }
        }
        return 0 === $size || '.' === end( $tokens ) ? null : $path;
    }

    /**
     * PHP leaves unknown double-quoted escapes intact.
     *
     * @param string $token Quoted literal.
     * @return string Literal value.
     */
    private function literal( $token ) {
        if ( 'b' === strtolower( $token[0] ) ) {
            $token = substr( $token, 1 );
        }
        $value = substr( $token, 1, -1 );
        if ( "'" === $token[0] ) {
            return str_replace( array( '\\\\', "\\'" ), array( '\\', "'" ), $value );
        }
        return preg_replace_callback(
            '/\\\\(x[0-9a-fA-F]{1,2}|[0-7]{1,3}|u\{[0-9a-fA-F]+\}|[nrtvef\\\\$"])/',
            static function ( $matches ) {
                $escape = $matches[1];
                if ( 'u' === $escape[0] ) {
                    $code = hexdec( substr( $escape, 2, -1 ) );
                    $json = $code <= 0xffff ? sprintf( '"\\u%04x"', $code ) : sprintf( '"\\u%04x\\u%04x"', 0xd800 + ( ( $code - 0x10000 ) >> 10 ), 0xdc00 + ( ( $code - 0x10000 ) & 0x3ff ) );
                    return json_decode( $json );
                }
                if ( 'e' === $escape ) {
                    return chr( 27 );
                }
                return '$' === $escape ? '$' : stripcslashes( $matches[0] );
            },
            $value
        );
    }

    /**
     * Capture once because later slices can run from a different cron request.
     *
     * @param string      $root Site root.
     * @param string|null $manifest Verified leaf manifest.
     * @return array Persistable relpaths and config source.
     */
    public function capture( $root, $manifest ) {
        $state      = array(
            'paths'  => array(),
            'config' => 'none',
        );
        $scan       = array(
            'paths'      => array(),
            'unresolved' => 0,
        );
        $config     = self::locate( $root );
        $in_root    = untrailingslashit( $root ) . '/wp-config.php';
        $candidates = array();

        $in_manifest = false;
        // Extraction keeps live links, so their config remains authoritative.
        if ( is_file( $in_root ) && ! is_link( $in_root ) && null !== $manifest ) {
            try {
                $in_manifest = $this->manifest_has_config( $manifest );
            } catch ( \RuntimeException $e ) {
                $this->debug( '[MainWP Backup] Config includes: manifest_failed=1' );
            }
        }
        if ( $in_manifest ) {
            $state['config'] = 'manifest';
        } elseif ( null !== $config ) {
            $state['config'] = 'live';
            $scan            = $this->scan( $config, $root );
        }

        // The server loads this before wp-config.php, including an archived config.
        $prepend = $this->auto_prepend_file();
        $skipped = 0;
        if ( is_string( $prepend ) && '' !== $prepend ) {
            $resolved = $this->is_absolute( $prepend ) ? $prepend : stream_resolve_include_path( $prepend );
            if ( false !== $resolved ) {
                $candidates[] = $resolved;
            } else {
                ++$skipped;
            }
        }
        foreach ( array_merge( $candidates, $scan['paths'] ) as $candidate ) {
            $relative = preg_match( '/[\x00-\x1f\x7f]/', $candidate ) ? null : MainWP_Child_Backup_Mu_Plugins::relative_directory( $root, $candidate );
            if ( null === $relative || ( ! isset( $state['paths'][ $relative ] ) && count( $state['paths'] ) >= self::MAX_PATHS ) ) {
                ++$skipped;
                continue;
            }
            $state['paths'][ $relative ] = $relative;
            // A case-insensitive filesystem loads 'Host.php' from host.php; tombstones and reconcile compare directory spellings.
            $spelling = file_exists( untrailingslashit( $root ) . '/' . $relative ) ? $this->on_disk_spelling( $root, $relative ) : null;
            if ( null !== $spelling && ! isset( $state['paths'][ $spelling ] ) ) {
                if ( count( $state['paths'] ) >= self::MAX_PATHS ) {
                    ++$skipped;
                } else {
                    $state['paths'][ $spelling ] = $spelling;
                }
            }
        }
        $state['paths'] = array_values( $state['paths'] );
        sort( $state['paths'], SORT_STRING );
        if ( $scan['unresolved'] || $skipped ) {
            $this->debug( '[MainWP Backup] Config includes: unresolved=' . $scan['unresolved'] . ' skipped=' . $skipped );
        }
        return $state;
    }

    /**
     * Directory entries hold the spelling the walker records.
     *
     * @param string $root     Site root.
     * @param string $relative Existing relpath under the root.
     * @return string|null Relpath spelled as listed on disk.
     */
    private function on_disk_spelling( $root, $relative ) {
        $dir      = untrailingslashit( $root );
        $segments = array();
        foreach ( explode( '/', $relative ) as $segment ) {
            $entries = @scandir( $dir ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- an unreadable directory keeps the literal spelling only.
            if ( ! is_array( $entries ) ) {
                return null;
            }
            $match = in_array( $segment, $entries, true ) ? $segment : null;
            if ( null === $match ) {
                foreach ( $entries as $entry ) {
                    if ( 0 === strcasecmp( $entry, $segment ) ) {
                        $match = $entry;
                        break;
                    }
                }
            }
            if ( null === $match ) {
                return null;
            }
            $segments[] = $match;
            $dir       .= '/' . $match;
        }
        return implode( '/', $segments );
    }

    /**
     * Stream membership without loading a site's whole file list into memory.
     *
     * @param string $manifest Verified manifest.
     * @return bool Whether it contains the root config.
     * @throws \RuntimeException When the manifest cannot be read.
     */
    private function manifest_has_config( $manifest ) {
        $handle = @fopen( $manifest, 'rb' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen, WordPress.PHP.NoSilencedErrors.Discouraged -- a vanished sidecar falls back to live config without exposing its path.
        if ( false === $handle ) {
            throw new \RuntimeException( 'Could not read the leaf manifest.' );
        }
        try {
            while ( false !== ( $line = fgets( $handle ) ) ) {
                $row = $this->parse_list_line( rtrim( $line, "\r\n" ) );
                if ( null !== $row && 'wp-config.php' === $row[0] ) {
                    return true;
                }
            }
            if ( ! feof( $handle ) ) {
                throw new \RuntimeException( 'Could not read the leaf manifest.' );
            }
        } finally {
            fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- local sidecar.
        }
        return false;
    }

    /**
     * Filesystem anchors distinguish literals from relative candidates.
     *
     * @param string $path Candidate path.
     * @return bool Whether the path has an absolute filesystem anchor.
     */
    private function is_absolute( $path ) {
        return '/' === substr( $path, 0, 1 ) || (bool) preg_match( '/^[a-z]:[\\\\\/]/i', $path );
    }

    /**
     * The setting remains relevant even when the snapshot supplies wp-config.php.
     *
     * @return string|false Server prepend setting.
     */
    protected function auto_prepend_file() {
        return ini_get( 'auto_prepend_file' );
    }

    /**
     * Config paths and contents can contain credentials.
     *
     * @param string $message Counts only; config contents can contain credentials.
     */
    protected function debug( $message ) {
        MainWP_Helper::log_debug( $message );
    }

    /**
     * Match destination aliases without broadening the set to directories.
     *
     * @param string $path Mapped destination relpath.
     * @param array  $state Persisted capture.
     * @return bool Whether the exact destination is protected.
     */
    public static function contains( $path, $state ) {
        $path = wp_normalize_path( $path );
        if ( '' === $path || '/' === $path[0] || in_array( '..', explode( '/', $path ), true ) ) {
            return false;
        }
        $path = implode( '/', array_diff( explode( '/', $path ), array( '', '.' ) ) );
        return in_array( $path, $state['paths'] ?? array(), true );
    }
}
