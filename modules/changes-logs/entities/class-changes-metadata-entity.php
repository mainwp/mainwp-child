<?php
/**
 * Entity: Metadata.
 *
 * Changes Logs Class
 *
 * @author  WP Activity Log plugin.
 *
 * @since 5.4.1
 *
 * @package MainWP\Child
 */

declare(strict_types=1);

namespace MainWP\Child\Changes\Entities;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Responsible for the events metadata.
 */
class Changes_Metadata_Entity extends Changes_Abstract_Entity {
    /**
     * Contains the table name.
     *
     * @var string
     */
    protected static $table = 'changes_meta';

    /**
     * Keeps the info about the columns of the table - name, type.
     *
     * @var array
     */
    protected static $fields = array(
        'id'     => 'int',
        'log_id' => 'int',
        'name'   => 'string',
        'value'  => 'string',
    );

    /**
     * Holds all the default values for the columns.
     *
     * @var array
     */
    protected static $fields_values = array(
        'id'     => 0,
        'log_id' => 0,
        'name'   => '',
        'value'  => '',
    );

    /**
     * Get sql create table.
     *
     * @param string $currentVersion - Current DB version.
     */
    public static function get_entity_table_create( $currentVersion = null ) {

        $collate = self::get_connection()->get_charset_collate();

        $table_name = self::get_table_name();

        $wp_entity_sql = 'CREATE TABLE `' . $table_name . '` (
                `id` bigint(20) NOT NULL AUTO_INCREMENT,
                `log_id` bigint(20) NOT NULL,
                `name` varchar(100) NOT NULL,
                `value` longtext NOT NULL';

        if ( empty( $currentVersion ) ) {
            $wp_entity_sql .= ',
                PRIMARY KEY (`id`),
                KEY `log_name` (`log_id`,`name`),
                KEY `name_value` (`name`,`value`(64))';
        }

        $wp_entity_sql .= ') ' . $collate;

        return $wp_entity_sql;
    }

    /**
     * Sets an index (if not there already)
     *
     * @param \wpdb $connection - \wpdb connection to be used for name extraction.
     *
     * @return void
     */
    public static function create_indexes( $connection = null ) {
        if ( null !== $connection ) {
            if ( $connection instanceof \wpdb ) {
                $_wpdb = $connection;
            }
        } else {
            $_wpdb = self::get_connection();
        }
        // check if an index exists.
        $index_exists = false;
        if ( $_wpdb->query( 'SELECT COUNT(1) IndexIsThere FROM INFORMATION_SCHEMA.STATISTICS WHERE table_schema=DATABASE() AND table_name="' . self::get_table_name() . '" AND index_name="name_value"' ) ) {
            // query succeeded, does index exist?
            $index_exists = ( isset( $_wpdb->last_result[0]->IndexIsThere ) ) ? $_wpdb->last_result[0]->IndexIsThere : false;
        }
        // if no index exists then make one.
        if ( ! $index_exists ) {
            $_wpdb->query( 'CREATE INDEX name_value ON ' . self::get_table_name() . ' (name, value(64))' );
        }
    }

    /**
     * Load meta by name and occurrence id.
     *
     * @param string $meta_name - Meta name.
     * @param int    $log_id - Occurrence ID.
     *
     * @return array
     */
    public static function load_by_name_and_log_id( $meta_name, $log_id ) {
        // Make sure to grab the migrated meta fields from the occurrence table.
        if ( in_array( $meta_name, array_keys( self::$migrated_meta ), true ) ) {
            $column_name = self::$migrated_meta[ $meta_name ];

            return self::get_fields_values()[ $column_name ];
        }

        return self::load( 'log_id = %d AND name = %s', array( $log_id, $meta_name ) );
    }

    /**
     * Deletes records from metadata table using the Occurrences IDs
     *
     * @param array $log_ids - The ids of the log.
     * @param \wpdb $connection - \wpdb connection to be used for name extraction.
     *
     * @return void
     */
    public static function delete_by_log_ids( $log_ids, $connection = null ) {
        if ( null !== $connection ) {
            if ( $connection instanceof \wpdb ) {
                $_wpdb = $connection;
            }
        } else {
            $_wpdb = self::get_connection();
        }
        if ( ! empty( $log_ids ) ) {
            $sql = 'DELETE FROM ' . self::get_table_name() . ' WHERE log_id IN (' . implode( ',', \array_map( 'intval', $log_ids ) ) . ')';
            // Execute query.
            self::delete_query( $sql, array(), $_wpdb );
        }
    }

    /**
     * Update Metadata by name and log_id.
     *
     * @param string  $name          - Meta name.
     * @param mixed   $value         - Meta value.
     * @param integer $log_id - log_id.
     */
    public static function update_by_name_and_log_id( $name, $value, $log_id ) {
        $meta = self::load_by_name_and_log_id( $name, $log_id );
        if ( empty( $meta ) ) {

            $meta_insert = array(
                'log_id' => $log_id,
                'name'   => $name,
                'value'  => maybe_serialize( $value ),
            );

            self::save( $meta_insert );
        } else {

            $meta_insert = array(
                'id'     => $meta['id'],
                'log_id' => $log_id,
                'name'   => $name,
                'value'  => maybe_serialize( $value ),
            );

            self::save( $meta_insert );
        }
    }

    /**
     * Loads all the meta records for the given log ids
     *
     * @param array $log_ids - Array with Occurrences IDs to search for.
     * @param \wpdb $connection - \wpdb connection to be used for name extraction.
     *
     * @return array
     */
    public static function load_by_logs_ids( array $log_ids, $connection = null ): array {
        $results = array();

        if ( null !== $connection ) {
            if ( $connection instanceof \wpdb ) {
                $_wpdb = $connection;
            }
        } else {
            $_wpdb = self::get_connection();
        }

        $sql = 'SELECT * FROM ' . self::get_table_name() . ' WHERE log_id in (' . implode( ',', $log_ids ) . ')';

        $_wpdb->suppress_errors( true );
        $results = $_wpdb->get_results( $sql, \ARRAY_A );
        $_wpdb->suppress_errors( false );

        return $results;
    }

    /**
     * Extracts the user data from the migration table based on given occurrences
     *
     * @param string $log_ids - String with Occurrences IDs to search for.
     * @param \wpdb  $connection - \wpdb connection to be used for name extraction.
     *
     * @return array
     */
    public static function get_user_data_by_occ_ids( string $log_ids, $connection = null ): array {
        $results = array();

        if ( null !== $connection ) {
            if ( $connection instanceof \wpdb ) {
                $_wpdb = $connection;
            }
        } else {
            $_wpdb = self::get_connection();
        }

        $sql = 'SELECT value FROM ' . self::get_table_name() . ' WHERE log_id in (' . $log_ids . ') AND ( name = "NewUserData" )';

        $_wpdb->suppress_errors( true );
        $results = $_wpdb->get_results( $sql, \ARRAY_A );
        $_wpdb->suppress_errors( false );

        return $results;
    }
}
