<?php
/**
 * MainWP-Child-CLI
 *
 * This file extends the WP-CLI and provides a set of SubCommands to Control your
 * Child Sites that are added to the MainWP Child.
 *
 * @package     MainWP/Child
 */

namespace MainWP\Child;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

// Exit if access directly.
if ( ! defined( 'WP_CLI' ) ) {
    return; // NOSONAR - jump to avoid CLI.
}

/**
 * Class MainWP_Child_WP_CLI_Command
 *
 * @package MainWP\Child
 */
class MainWP_Child_WP_CLI_Command extends \WP_CLI_Command { // phpcs:ignore Generic.Classes.OpeningBraceSameLine.ContentAfterBrace -- NOSONAR.

    /**
     * Method init()
     *
     * Initiate the MainWP CLI after all Plugins have loaded.
     */
    public static function init() {
        add_action( 'plugins_loaded', array( static::class, 'init_wpcli_commands' ), 99999 );
    }

    /**
     * Method init_wpcli_commands
     *
     * Adds the MainWP WP CLI Commands via WP_CLI::add_command
     */
    public static function init_wpcli_commands() {
        \WP_CLI::add_command( 'mainwp-child', static::class );
    }

    /**
     * Settings.
     *
     * ## OPTIONS
     *
     * [--disable-pwd-auth]
     *  : Disable connect passwd authentication
     *
     *
     * ## EXAMPLES
     *
     *     wp mainwp-child settings --disable-pwd-auth [<username>] [<disabled>].
     *
     * ## Synopsis [--disable-pwd-auth] [<username>] [<disabled>].
     *
     * @param array $args Function arguments.
     * @param array $assoc_args Function associate arguments.
     */
    public function settings( $args, $assoc_args ) { //phpcs:ignore -- NOSONAR - complexity.

        if ( isset( $assoc_args['disable-pwd-auth'] ) ) {
            if ( empty( $args ) || empty( $args[0] ) ) {
                \WP_CLI::error( 'Please enter the username to apply this setting. A valid username is required to proceed.' );
                return true;
            } else {
                $user_name = $args[0];
                $user      = get_user_by( 'login', $user_name );

                if ( ! $user || empty( $user->ID ) ) {
                    \WP_CLI::error( 'The username you entered does not match any account. Please verify the username and try again.' );
                    return true;
                }

                $disabled = ! isset( $args[1] ) || '1' === $args[1] ? true : false;

                update_user_option( $user->ID, 'mainwp_child_user_enable_passwd_auth_connect', $disabled ? 0 : 1 );
                \WP_CLI::success( 'Your changes have been saved successfully!' );
                return true;
            }
        }

        \WP_CLI::error( 'Invalid arguments. Please try again, or contact support if the issue persists.' );
    }

    /**
     * Backup operations.
     *
     * Restores a backup archive without the dashboard or wp-admin, for
     * recovering a site whose admin is down. It reuses the same restore engine
     * the dashboard drives, so there is no parallel restore path.
     *
     * For a plaintext local archive, the file must already sit in this site's
     * backup directory; the dashboard keeps a local copy of each native backup
     * until retention prunes it. For a total-loss recovery where the local copy
     * is gone, pull the encrypted offsite copy (the snapshot's manifest, its
     * HMAC, and its sealed file or chunks) into a directory and pass that with
     * --bundle and set MAINWP_BACKUP_PASSPHRASE in the environment to decrypt it.
     * The environment value is used verbatim when --passphrase is absent.
     *
     * There is no automatic pre-restore backup on this path. A database
     * restore overwrites the live database, so it requires --yes.
     *
     * ## OPTIONS
     *
     * <command>
     *  : The backup subcommand. Only "restore" is supported.
     *
     * [<archive>]
     *  : The archive file name. For an encrypted bundle, the artifact file name
     *  : recorded in the manifest.
     *
     * [--type=<type>]
     *  : What to restore: full, db, or files. Default: full.
     * ---
     * default: full
     * options:
     *   - full
     *   - db
     *   - files
     * ---
     *
     * [--passphrase=<passphrase>]
     *  : Legacy recovery input; exposes the secret in process arguments and shell history.
     *  : Prefer MAINWP_BACKUP_PASSPHRASE in the environment. Requires --bundle.
     *
     * [--bundle=<dir>]
     *  : Directory holding the pulled offsite bundle (manifest.json,
     *  : manifest.json.hmac, and the sealed file or chunks).
     *
     * [--yes]
     *  : Confirm a database-bearing restore. Required for full and db.
     *
     * ## EXAMPLES
     *
     *     wp mainwp-child backup restore backup-1718900000.zip --type=full --yes
     *
     *     IFS= read -r -s MAINWP_BACKUP_PASSPHRASE
     *     export MAINWP_BACKUP_PASSPHRASE
     *     wp mainwp-child backup restore backup-1718900000.zip --type=db --yes \
     *       --bundle=/home/recover/4/37
     *     unset MAINWP_BACKUP_PASSPHRASE
     *
     * @param array $args       Positional arguments.
     * @param array $assoc_args Associative arguments.
     */
    public function backup( $args, $assoc_args ) {
        $command = isset( $args[0] ) ? $args[0] : '';
        if ( 'restore' !== $command ) {
            \WP_CLI::error( 'Unknown subcommand. Usage: wp mainwp-child backup restore <archive> [--type=full|db|files] [--bundle=<dir>] [--yes]' );
            return;
        }

        $archive = isset( $args[1] ) ? $args[1] : '';
        if ( '' === $archive ) {
            \WP_CLI::error( 'Provide the archive file name. Usage: wp mainwp-child backup restore <archive> [--type=full|db|files] [--bundle=<dir>] [--yes]' );
            return;
        }

        $type       = isset( $assoc_args['type'] ) ? $assoc_args['type'] : 'full';
        $confirmed  = isset( $assoc_args['yes'] );
        $passphrase = isset( $assoc_args['passphrase'] ) ? (string) $assoc_args['passphrase'] : (string) getenv( 'MAINWP_BACKUP_PASSPHRASE' );
        if ( isset( $assoc_args['passphrase'] ) ) {
            \WP_CLI::warning( '--passphrase exposes the secret in process arguments and shell history. Prefer MAINWP_BACKUP_PASSPHRASE in the environment.' );
        }
        $bundle = isset( $assoc_args['bundle'] ) ? (string) $assoc_args['bundle'] : '';

        // The restore engine reads files through WP_Filesystem, which CLI does
        // not initialise on its own.
        if ( ! function_exists( 'WP_Filesystem' ) ) {
            require_once ABSPATH . 'wp-admin/includes/file.php'; // NOSONAR - WP compatible.
        }
        WP_Filesystem();

        if ( 'files' !== $type && $confirmed ) {
            \WP_CLI::log( 'No automatic pre-restore backup is taken on this path. Make sure you have a separate backup before continuing.' );
        }

        $runner = new MainWP_Child_CLI_Restore_Runner(
            MainWP_Child_Restore_Task_Runner::instance(),
            MainWP_Child_Backup_Task::instance()
        );

        $progress = function ( $task ) {
            \WP_CLI::log( '  phase: ' . $task->phase );
        };

        $result = $runner->run( $archive, $type, $confirmed, $progress, $passphrase, $bundle );

        if ( ! empty( $result['ok'] ) ) {
            \WP_CLI::success( 'Restore completed.' );
            return;
        }

        if ( ! empty( $result['maintenance_left'] ) ) {
            \WP_CLI::warning( 'The site was left in maintenance mode. After investigating, clear it with: rm ' . ABSPATH . '.maintenance' );
        }

        $message = '' !== $result['error'] ? $result['error'] : 'The restore failed.';
        if ( '' !== $result['error_code'] ) {
            $message .= ' (' . $result['error_code'] . ')';
        }
        \WP_CLI::error( $message );
    }
}
