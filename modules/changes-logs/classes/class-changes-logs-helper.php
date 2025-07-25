<?php
/**
 * Changes Logs Class
 *
 * @since 5.5
 *
 * @package MainWP\Child
 */

namespace MainWP\Child\Changes;

// Exit.
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Changes Logs Helper class.
 */
class Changes_Logs_Helper {

    /**
     * Method get_post_change_logs_events().
     *
     * @return array Logs.
     */
    public static function get_post_change_logs_events() {

        $cats_change_logs = array(
            'Content'       => array( 1200, 1205, 1210, 1215, 1420, 1425, 1225, 1230, 1240, 1245, 1250, 1290, 1295, 1255, 1260, 1265, 1270, 1300, 1280, 1285, 1305, 1310, 1315, 1320, 1321, 1325, 1326, 1330, 1335, 1340 ),
            'Tags'          => array( 1395, 1400, 1405, 1410, 1415 ),
            'Categories'    => array( 1235, 1370, 1375, 1380, 1385, 1390 ),
            'Custom Fields' => array( 1275, 1355, 1360, 1365 ),
            'Comments'      => array( 1520, 1525, 1530, 1535, 1540, 1545, 1550, 1555, 1560, 1565 ),
        );

        $post_logs = array();

        foreach ( $cats_change_logs as  $type_ids ) {
            foreach ( $type_ids as $type_id ) {
                if ( isset( static::get_changes_logs_types()[ $type_id ] ) ) {
                    $post_logs[ $type_id ] = static::get_changes_logs_types()[ $type_id ];
                }
            }
        }

        return $post_logs;
    }

    /**
     * Method get_changes_logs_types().
     *
     * @return array data.
     */
    public static function get_changes_logs_types() { //phpcs:ignore -- NOSONAR - long function.
        $tran_loc = 'mainwp-child'; //phpcs:ignore -- NOSONAR - used in default-logs.php.
        static $defaults;
        if ( null === $defaults ) {
            $defaults = include CHANGES_LOGS_MODULE_BASE_DIR . 'includes/default-logs.php'; //phpcs:ignore -- NOSONAR ok.
        }
        if ( ! is_array( $defaults ) ) {
            $defaults = array();
        }
        return $defaults;
    }
}
