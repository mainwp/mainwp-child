<?php
/**
 * MainWP Child System Monitor Cron.
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
 * MainWP Child System Monitor - Cron Monitor.
 *
 * Responsibilities:
 *
 * - Execute the Cron scanner.
 * - Execute the Cron validator.
 * - Convert issues into monitor results.
 *
 * Does NOT:
 *
 * - Store results.
 * - Generate UI.
 * - Access the database.
 */
class MainWP_Child_System_Monitor_Cron {

    const ISSUE_MONITOR_STALE    = 'monitor_stale';
    const ISSUE_MONITOR_FALLBACK = 'monitor_fallback_used';

    /**
     * Monitor name.
     */
    const NAME = 'cron';

    /**
     * Execute the monitor.
     *
     * @param array $context Running context data.
     */
    public function run( array $context = array() ) {

        $scanner = new MainWP_Child_System_Monitor_Cron_Scanner();

        $scan = $scanner->scan();

        $validator = new MainWP_Child_System_Monitor_Cron_Validator();

        $issues = $validator->validate( $scan, $context );

        $issues = $this->prepare_issues( $issues );

        MainWP_Child_System_Monitor_Storage::save_results(
            self::NAME,
            $issues
        );
    }

    /**
     *  Prepare monitor issues.
     *
     * @param array $issues Validator issues.
     *
     * @return array
     */
    private function prepare_issues( array $issues ) {

        $results = array();

        foreach ( $issues as $issue ) {
            $results[] = array(
                'monitor'    => self::NAME,
                'check_name' => $issue['check_name'] ?? 'heartbeat',
                'entity'     => $issue['entity'] ?? 'wp_cron',
                'issue_code' => $issue['code'],
                'payload'    => $issue['data'] ?? array(),
                'severity'   => $issue['severity'] ?? null,
            );
        }

        return $results;
    }
}
