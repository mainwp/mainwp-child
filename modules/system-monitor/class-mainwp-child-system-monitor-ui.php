<?php
/**
 * MainWP Child System Monitor.
 *
 * @since 6.1.4
 *
 * @package     MainWP/Child
 */

namespace MainWP\Child\SystemMonitor;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}


/**
 * MainWP Child System Monitor UI.
 */
class MainWP_Child_System_Monitor_UI {

    /**
     * UI init.
     */
    public static function init() {
        add_action( 'mainwp_child_top_server_information', array( self::class, 'render_issues' ) );
    }

    /**
     * Render all monitor issues.
     */
    public static function render_issues() {

        $issues = MainWP_Child_System_Monitor_Storage::get_issues( 'cron' );

        if ( empty( $issues ) ) {
            return;
        }

        ?>
        <div class="mainwp-child-page-content message wrap" style="margin: 1em;">
            <?php
            foreach ( $issues as $issue ) {
                self::render_issue( $issue );
            }
            ?>
        </div>
        <?php
    }

    /**
     * Render a single issue.
     *
     * @param array $issue Issue record to render.
     */
    private static function render_issue( array $issue ) {

        echo '<div class="mainwp-child-system-monitor-issue notice notice-warning">';

        echo '<strong>' .
            esc_html(
                MainWP_Child_System_Monitor_Issues::get_title(
                    $issue['issue_code']
                )
            ) .
            '</strong>';

        echo '<p>' .
            esc_html(
                MainWP_Child_System_Monitor_Issues::get_message(
                    $issue['issue_code'],
                    $issue['payload']
                )
            ) .
            '</p>';

        $url = MainWP_Child_System_Monitor_Issues::get_help_url(
            $issue['issue_code']
        );

        if ( ! empty( $url ) ) {

            printf(
                '<p><a href="%1$s" target="_blank" rel="noopener noreferrer">%2$s</a></p>',
                esc_url( $url ),
                esc_html__( 'Learn more', 'mainwp-child' )
            );
        }

        echo '</div>';
        echo '<div style="clear:both"></div>';
    }
}
