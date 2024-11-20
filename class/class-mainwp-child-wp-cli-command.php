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
}
