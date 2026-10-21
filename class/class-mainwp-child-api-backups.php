<?php
/**
 * MainWP Child Site Api Backups
 *
 * Manages MainWP API Backups child site actions when needed.
 *
 * @package MainWP\Child
 */

namespace MainWP\Child;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Class MainWP_Child_Api_Backups
 *
 * This class handles all the MainWP API Backups child site actions when needed.
 *
 * @package MainWP\Child
 */
class MainWP_Child_Api_Backups {

    /**
     * Public variable to state if supported plugin is installed on the child site.
     *
     * @var bool If supported plugin is installed, return true, if not, return false.
     */
    public $is_plugin_installed = false;

    /**
     * Public static variable to hold the single instance of the class.
     *
     * @var mixed Default null
     */
    protected static $instance = null;

    /**
     * Method instance()
     *
     * Create a public static instance.
     *
     * @return mixed Class instance.
     */
    public static function instance() {
        if ( null === static::$instance ) {
            static::$instance = new self();
        }

        return static::$instance;
    }

    /**
     * MainWP_Child_Api_Backups constructor.
     *
     * Run any time class is called.
     */
    public function __construct() {
        // Constructor.
    }

    /**
     * Create a backup of the database for the given child site.
     *
     * @return void
     */
    public function api_backups_mysqldump() {

        $uploads = wp_get_upload_dir();
        MainWP_Helper::write( $this->run_mysqldump( DB_NAME, DB_USER, DB_PASSWORD, DB_HOST, $uploads['basedir'] . '/mainwp/api_db_backups/' ) );
    }

    // phpcs:disable WordPress.WP.AlternativeFunctions -- Native handles keep credentials outside WordPress storage and reserve dump names exclusively.
    // phpcs:disable WordPress.PHP.NoSilencedErrors.Discouraged -- Filesystem warnings can disclose private paths; callers receive only a fixed failure response.

    /**
     * Keep credentials and partial dumps scoped to one request.
     *
     * @param string $database Database name.
     * @param string $user Database user.
     * @param string $password Database password.
     * @param string $host WordPress database host.
     * @param string $directory Dump directory.
     * @return array Dashboard response.
     * @throws \RuntimeException Handled internally to keep diagnostics private.
     */
    protected function run_mysqldump( $database, $user, $password, $host, $directory ) {
        $defaults = false;
        $partial  = false;
        $result   = 1;
        try {
            $this->protect_dump_directory( $directory );
            $host_options = $this->dump_host_options( $host );
            $defaults     = $this->create_dump_defaults( $user, $password );
            $site_url     = str_replace( '/', '.', preg_replace( '#^https?://#i', '', get_bloginfo( 'url' ) ) );
            $prefix       = sanitize_file_name( $database . '_' . $site_url . '_' . current_datetime()->format( 'm-d-Y_H.i.s.A' ) );
            $destination  = $directory . $prefix . '_' . bin2hex( random_bytes( 16 ) ) . '.sql.gz';
            $handle       = @fopen( $destination, 'x' );
            if ( false === $handle ) {
                throw new \RuntimeException( 'Cannot reserve database dump.' );
            }
            $partial = $destination;
            if ( ! @fclose( $handle ) ) {
                throw new \RuntimeException( 'Cannot reserve database dump.' );
            }
            $result = $this->execute_dump_command( $this->build_dump_command( $database, $host_options, $defaults, $destination ) );
        } catch ( \Throwable $error ) {
            // Tool diagnostics can include credentials, SQL, or private paths.
            $result = 1;
        } finally {
            if ( false !== $defaults ) {
                @unlink( $defaults );
            }
            if ( 0 !== $result && false !== $partial ) {
                @unlink( $partial );
            }
        }
        return array(
            'result' => 0 === $result ? 'GOOD' : 'ERROR',
            'output' => array(),
            'res'    => $result,
        );
    }

    /**
     * Existing cPanel folders need the same protection as new ones.
     *
     * @param string $directory Dump directory.
     * @throws \RuntimeException When protection cannot be written.
     */
    protected function protect_dump_directory( $directory ) {
        if ( ! is_dir( $directory ) && ! @wp_mkdir_p( $directory ) ) {
            throw new \RuntimeException( 'Cannot protect database dump directory.' );
        }
        $files = array(
            'index.php' => '<?php // Silence is golden.',
            '.htaccess' => "<IfModule mod_authz_core.c>\nRequire all denied\n</IfModule>\n<IfModule !mod_authz_core.c>\nOrder allow,deny\nDeny from all\n</IfModule>\n",
        );
        foreach ( $files as $name => $contents ) {
            $path = $directory . $name;
            if ( ! file_exists( $path ) && strlen( $contents ) !== @file_put_contents( $path, $contents, LOCK_EX ) ) {
                @unlink( $path );
                throw new \RuntimeException( 'Cannot protect database dump directory.' );
            }
        }
    }

    /**
     * Use WordPress host semantics rather than guessing at colons.
     *
     * @param string $host WordPress database host.
     * @return array Separate client options.
     * @throws \RuntimeException When WordPress cannot parse the host.
     */
    protected function dump_host_options( $host ) {
        global $wpdb;
        $parsed = $wpdb->parse_db_host( $host );
        if ( false === $parsed ) {
            throw new \RuntimeException( 'Invalid database host.' );
        }
        list( $hostname, $port, $socket ) = $parsed;
        $options                          = array( '--host=' . $hostname );
        if ( null !== $port ) {
            $options[] = '--port=' . $port;
        }
        if ( null !== $socket ) {
            $options[] = '--socket=' . $socket;
        }
        return $options;
    }

    /**
     * Option files avoid exposing credentials in the process list.
     *
     * @param string $user Database user.
     * @param string $password Database password.
     * @return string Private temporary filename.
     * @throws \RuntimeException When private storage is unavailable.
     */
    protected function create_dump_defaults( $user, $password ) {
        $contents = "[client]\nuser=" . $this->quote_dump_option( $user ) . "\npassword=" . $this->quote_dump_option( $password ) . "\n";
        $temp_dir = realpath( sys_get_temp_dir() );
        $web_root = realpath( ABSPATH );
        if ( false === $temp_dir || false === $web_root || 0 === strpos( trailingslashit( $temp_dir ), trailingslashit( $web_root ) ) ) {
            throw new \RuntimeException( 'Private database credentials storage unavailable.' );
        }
        $path = @tempnam( $temp_dir, 'mainwp-db-' );
        if ( false === $path ) {
            throw new \RuntimeException( 'Private database credentials storage unavailable.' );
        }
        try {
            if ( dirname( $path ) !== $temp_dir || ! @chmod( $path, 0600 ) || 0600 !== ( @fileperms( $path ) & 0777 ) || strlen( $contents ) !== @file_put_contents( $path, $contents, LOCK_EX ) ) {
                throw new \RuntimeException( 'Private database credentials storage unavailable.' );
            }
        } catch ( \Throwable $error ) {
            @unlink( $path );
            throw new \RuntimeException( 'Private database credentials storage unavailable.' );
        }
        return $path;
    }

    /**
     * Newlines and NUL could inject options or truncate credentials.
     *
     * @param string $value Option value.
     * @return string Quoted MySQL option-file value.
     * @throws \RuntimeException When the value cannot be represented safely.
     */
    protected function quote_dump_option( $value ) {
        if ( false !== strpbrk( $value, "\r\n\0" ) ) {
            throw new \RuntimeException( 'Invalid database credentials.' );
        }
        return '"' . str_replace( array( '\\', '"' ), array( '\\\\', '\\"' ), $value ) . '"';
    }

    /**
     * Preserve both pipeline statuses without requiring shell pipefail.
     *
     * @param string $database Database name.
     * @param array  $host_options Host, port and socket options.
     * @param string $defaults Private options filename.
     * @param string $destination Reserved dump filename.
     * @return string POSIX shell command.
     */
    protected function build_dump_command( $database, $host_options, $defaults, $destination ) {
        $arguments = array_merge( array( 'mysqldump', '--defaults-extra-file=' . $defaults ), $host_options, array( '--', $database ) );
        $dump      = implode( ' ', array_map( 'escapeshellarg', $arguments ) );
        // FD 3 carries the dump status past gzip, which otherwise hides dump failures.
        return '{ status=$( { { ' . $dump . ' 2>/dev/null; printf \'%s\' "$?" >&3; } | ' . escapeshellarg( 'gzip' ) . ' > ' . escapeshellarg( $destination ) . ' 2>/dev/null; printf \' %s\' "$?"; } 3>&1 ); [ "$status" = \'0 0\' ]; } 2>/dev/null';
    }

    /**
     * Discard diagnostics because clients may echo private options or paths.
     *
     * @param string $command Escaped shell command.
     * @return int Process status.
     */
    protected function execute_dump_command( $command ) {
        if ( ! function_exists( 'exec' ) ) {
            return 1;
        }
        $result = 1;
        @exec( $command, $output, $result ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.system_calls_exec -- cPanel database exports require the external client.
        return $result;
    }

    // phpcs:enable WordPress.PHP.NoSilencedErrors.Discouraged
    // phpcs:enable WordPress.WP.AlternativeFunctions
}
