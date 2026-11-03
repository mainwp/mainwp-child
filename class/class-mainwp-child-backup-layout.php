<?php
/**
 * Private v2 descriptor and qualified identity rules.
 *
 * @package MainWP\Child
 */

namespace MainWP\Child;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/** Internal primitives; production admission does not call these. */
class MainWP_Child_Backup_Layout {
    const ROLES    = array( 'site', 'content', 'uploads', 'plugins', 'mu_plugins', 'config' );
    const COVERAGE = array( 'restore', 'recovery_only', 'preserved' );
    /**
     * Pinned local physical roots.
     *
     * @var array
     */
    private $roots = array();

    /**
     * Original configured paths for capture binding checks.
     *
     * @var array
     */
    private $capture_sources = array();

    /**
     * Exact locally discovered configuration files, keyed by logical name.
     *
     * @var array
     */
    private $config_files = array();

    /**
     * Pin locally discovered roots, resolving configured root symlinks once.
     *
     * @param array $roots Directory roles to local directories; excludes config.
     * @param array $config_files Exact logical config names to local files.
     */
    public function __construct( $roots, $config_files = array() ) {
        foreach ( $roots as $role => $path ) {
            if ( 'config' === $role || ! in_array( $role, self::ROLES, true ) || ! is_string( $path ) || ! is_dir( $path ) ) {
                self::refuse();
            }
            $physical = realpath( $path );
            if ( false === $physical ) {
                self::refuse();
            }
            $this->roots[ $role ]           = rtrim( $physical, '/' );
            $this->capture_sources[ $role ] = $path;
        }
        foreach ( $config_files as $name => $path ) {
            self::parse_identity( 'roots/config/' . $name );
            if ( ! is_string( $path ) || is_link( $path ) || ! is_file( $path ) ) {
                self::refuse();
            }
            $physical = realpath( $path );
            if ( false === $physical || in_array( $physical, $this->config_files, true ) ) {
                self::refuse();
            }
            $this->config_files[ $name ]                = $physical;
            $this->capture_sources[ 'config/' . $name ] = $path;
        }
    }

    /**
     * Return captured bindings only after checking their configured targets.
     *
     * @param array $descriptor Validated descriptor.
     * @return array Role directories and exact configuration files.
     */
    public function capture_bindings( $descriptor ) {
        $bindings = array();
        foreach ( $descriptor['roots'] as $entry ) {
            $role = $entry['role'];
            if ( 'preserved' === $entry['coverage'] ) {
                continue;
            }
            $paths = 'config' === $role ? $this->config_files : array( '' => $this->roots[ $role ] ?? null );
            if ( empty( $paths ) || in_array( null, $paths, true ) ) {
                self::refuse( MainWP_Backup_Error::ERR_UNSUPPORTED_LAYOUT );
            }
            foreach ( $paths as $name => $physical ) {
                $source = $this->capture_sources[ 'config' === $role ? 'config/' . $name : $role ];
                clearstatcache( true, $source );
                if ( realpath( $source ) !== $physical || ( 'config' === $role && is_link( $source ) ) ) {
                    self::refuse( MainWP_Backup_Error::ERR_LAYOUT_CHANGED );
                }
                if ( ! is_readable( $source ) || ( 'config' === $role ? ! is_file( $source ) : ! is_dir( $source ) ) ) {
                    self::refuse( MainWP_Backup_Error::ERR_UNSUPPORTED_LAYOUT );
                }
            }
            $bindings[ $role ] = $paths;
        }
        return $bindings;
    }

    /**
     * Build a descriptor without physical root bindings.
     *
     * @param array  $roots Roots.
     * @param string $layout_id Layout id.
     */
    public static function descriptor( $roots, $layout_id ) {
        return self::normalize(
            array(
                'format'        => 2,
                'roots'         => $roots,
                'layout_id'     => $layout_id,
                'core_policy'   => 'managed_preserve',
                'list_format'   => 2,
                'path_encoding' => 'role-prefix',
            )
        );
    }

    /**
     * Validate and order descriptor fields; omit observational core_version.
     *
     * @param array $descriptor Descriptor.
     */
    private static function normalize( $descriptor ) {
        if ( ! is_array( $descriptor ) || 2 !== ( $descriptor['format'] ?? null ) || 2 !== ( $descriptor['list_format'] ?? null ) ) {
            self::refuse( MainWP_Backup_Error::ERR_UNSUPPORTED_BACKUP_FORMAT );
        }
        if ( 'role-prefix' !== ( $descriptor['path_encoding'] ?? null ) || 'managed_preserve' !== ( $descriptor['core_policy'] ?? null ) || ! self::is_digest( $descriptor['layout_id'] ?? null ) || empty( $descriptor['roots'] ) || ! is_array( $descriptor['roots'] ) ) {
            self::refuse();
        }
        $roles = array();
        foreach ( $descriptor['roots'] as $row ) {
            if ( ! is_array( $row ) || ! in_array( $row['role'] ?? null, self::ROLES, true ) || ! in_array( $row['coverage'] ?? null, self::COVERAGE, true ) || isset( $roles[ $row['role'] ] ) || 2 !== count( $row ) ) {
                self::refuse();
            }
            $roles[ $row['role'] ] = $row['coverage'];
        }
        $roots = array();
        foreach ( self::ROLES as $role ) {
            if ( isset( $roles[ $role ] ) ) {
                $roots[] = array(
                    'role'     => $role,
                    'coverage' => $roles[ $role ],
                );
            }
        }
        return array(
            'format'        => 2,
            'roots'         => $roots,
            'layout_id'     => $descriptor['layout_id'],
            'core_policy'   => 'managed_preserve',
            'list_format'   => 2,
            'path_encoding' => 'role-prefix',
        );
    }

    /**
     * Serialize canonical descriptor bytes in the approved field order.
     *
     * @param array $descriptor Descriptor.
     */
    public static function serialize( $descriptor ) {
        $bytes = wp_json_encode( self::normalize( $descriptor ), JSON_UNESCAPED_SLASHES );
        if ( false === $bytes ) {
            self::refuse();
        }
        return $bytes;
    }

    /**
     * Hash canonical bytes, excluding observational core_version.
     *
     * @param array $descriptor Descriptor.
     */
    public static function digest( $descriptor ) {
        return hash( 'sha256', self::serialize( $descriptor ) );
    }

    /**
     * Append observational metadata after the fields covered by the digest.
     *
     * @param array $descriptor Descriptor with an optional observed core version.
     * @return string Archive metadata JSON.
     */
    public static function metadata_bytes( $descriptor ) {
        $metadata = self::normalize( $descriptor );
        if ( array_key_exists( 'core_version', $descriptor ) ) {
            if ( ! is_string( $descriptor['core_version'] ) ) {
                self::refuse();
            }
            $metadata['core_version'] = $descriptor['core_version'];
        }
        $bytes = wp_json_encode( $metadata, JSON_UNESCAPED_SLASHES );
        if ( false === $bytes ) {
            self::refuse();
        }
        return $bytes;
    }

    /**
     * Validate descriptor metadata against its expected digest.
     *
     * @param string $bytes Bytes.
     * @param string $digest Digest.
     */
    public static function decode( $bytes, $digest ) {
        if ( ! is_string( $bytes ) || strlen( $bytes ) > 65536 ) {
            self::refuse();
        }
        $decoded = json_decode( $bytes, true );
        if ( JSON_ERROR_NONE !== json_last_error() || ! is_array( $decoded ) ) {
            self::refuse();
        }
        $descriptor = self::normalize( $decoded );
        if ( array_diff( array_keys( $decoded ), array( 'format', 'roots', 'layout_id', 'core_policy', 'list_format', 'path_encoding', 'core_version' ) ) ) {
            self::refuse();
        }
        if ( ! self::is_digest( $digest ) || ! hash_equals( $digest, self::digest( $descriptor ) ) ) {
            self::refuse();
        }
        if ( array_key_exists( 'core_version', $decoded ) ) {
            if ( ! is_string( $decoded['core_version'] ) ) {
                self::refuse();
            }
            $descriptor['core_version'] = $decoded['core_version'];
        }
        return $descriptor;
    }

    /**
     * Check a lowercase SHA-256 value.
     *
     * @param mixed $digest Digest.
     */
    public static function is_digest( $digest ) {
        return is_string( $digest ) && 1 === preg_match( '/^[a-f0-9]{64}$/D', $digest );
    }

    /**
     * Parse one canonical role-qualified identity.
     *
     * @param string $identity Identity.
     */
    public static function parse_identity( $identity ) {
        // Phar cannot open literal backslashes, even in valid Linux filenames.
        if ( ! is_string( $identity ) || false !== strpos( $identity, "\0" ) || false !== strpos( $identity, '\\' ) ) {
            self::refuse();
        }
        if ( in_array( '..', preg_split( '#[\\\\/]#', $identity ), true ) ) {
            self::refuse();
        }
        $parts = explode( '/', $identity, 3 );
        if ( 3 !== count( $parts ) || 'roots' !== $parts[0] || ! in_array( $parts[1], self::ROLES, true ) || '' === $parts[2] ) {
            self::refuse();
        }
        if ( 0 === strpos( $parts[2], '/' ) || preg_match( '/^[A-Za-z]:|^\\\\/', $parts[2] ) ) {
            self::refuse();
        }
        foreach ( explode( '/', $parts[2] ) as $segment ) {
            if ( '' === $segment || '.' === $segment || false !== strpos( $segment, "\t" ) || false !== strpos( $segment, "\n" ) || false !== strpos( $segment, "\r" ) ) {
                self::refuse();
            }
        }
        return array( $parts[1], $parts[2] );
    }

    /**
     * Select semantic ownership, followed by content and site.
     *
     * @param string $path Path.
     */
    public function identity( $path ) {
        $config_name = array_search( $path, $this->config_files, true );
        if ( false !== $config_name ) {
            return 'roots/config/' . $config_name;
        }
        $owner  = null;
        $length = -1;
        foreach ( array( array( 'uploads', 'plugins', 'mu_plugins' ), array( 'content' ), array( 'site' ) ) as $group ) {
            foreach ( $group as $role ) {
                if ( ! isset( $this->roots[ $role ] ) ) {
                    continue;
                }
                $root = $this->roots[ $role ];
                if ( 0 === strpos( $path, $root . '/' ) && strlen( $root ) > $length ) {
                    $owner  = $role;
                    $length = strlen( $root );
                }
            }
            if ( null !== $owner ) {
                break;
            }
        }
        if ( null === $owner ) {
            self::refuse();
        }
        $identity = 'roots/' . $owner . '/' . substr( $path, $length + 1 );
        self::parse_identity( $identity );
        return $identity;
    }

    /**
     * Resolve a pinned identity without creating directories or changing files.
     *
     * @param string $identity Identity.
     */
    public function resolve( $identity ) {
        list( $role, $relative ) = self::parse_identity( $identity );
        if ( 'config' === $role ) {
            if ( ! isset( $this->config_files[ $relative ] ) ) {
                self::refuse();
            }
            $target = $this->config_files[ $relative ];
        } else {
            if ( ! isset( $this->roots[ $role ] ) ) {
                self::refuse();
            }
            $target = $this->roots[ $role ] . '/' . $relative;
        }
        if ( is_link( $target ) ) {
            self::refuse();
        }
        $parent = dirname( $target );
        $tail   = basename( $target );
        while ( ! file_exists( $parent ) && ! is_link( $parent ) && dirname( $parent ) !== $parent ) {
            $tail   = basename( $parent ) . '/' . $tail;
            $parent = dirname( $parent );
        }
        $physical_parent = realpath( $parent );
        if ( false === $physical_parent || $this->identity( $physical_parent . '/' . $tail ) !== $identity ) {
            self::refuse();
        }
        return $target;
    }

    /**
     * Stop decoding with the existing registry exception shape.
     *
     * @param string $code Code.
     * @throws MainWP_Exception Always.
     */
    public static function refuse( $code = MainWP_Backup_Error::ERR_FILE_CORRUPT ) {
        throw new MainWP_Exception( esc_html( MainWP_Backup_Error::message( $code ) ), null, $code ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- registry message is escaped.
    }
}
