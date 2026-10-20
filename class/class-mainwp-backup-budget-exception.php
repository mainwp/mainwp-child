<?php
/**
 * Budget-exceeded control-flow exception for the Tier-1 snapshot dump.
 *
 * Raised when the consistent-snapshot dump crosses its per-request time
 * budget at a table boundary. The caller auto-downgrades to the Tier-2
 * paginated dump - having wasted at most one request.
 *
 * @package MainWP\Child
 */

namespace MainWP\Child;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Class MainWP_Backup_Budget_Exception
 *
 * @package MainWP\Child
 */
class MainWP_Backup_Budget_Exception extends MainWP_Exception { // phpcs:ignore Generic.Classes.OpeningBraceSameLine.ContentAfterBrace -- NOSONAR.
}
