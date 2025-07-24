<?php
/**
 * Changes Logs DB Handle.
 *
 * @since 5.5
 *
 * @package MainWP\Child
 */

namespace MainWP\Child\Changes;

use MainWP\Child\MainWP_Child_Actions;
use MainWP\Child\MainWP_Child_DB_Base;
use MainWP\Child\MainWP_Helper;

// Exit.
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Handle for the events.
 */
class Changes_Logs_DB_Log {


    /**
     * Private static variable to hold the single instance of the class.
     *
     * @static
     *
     * @var mixed Default null
     */
    private static $instance = null;

    /**
     * DB helper variable.
     *
     * @var object DB helper variable.
     */
    protected $db = null;

    /**
     * Private variable to hold the database version info.
     *
     * @var string DB version info.
     */
    protected $db_version = '1.0.6'; // NOSONAR - no IP.

    /**
     * Method instance()
     *
     * Return public static instance.
     *
     * @static
     * @return Changes_Logs
     */
    public static function instance() {
        if ( null === static::$instance ) {
            static::$instance = new self();
        }
        return static::$instance;
    }

    /**
     * Constructor.
     *
     * Run any time class is called.
     */
    public function __construct() {
        $this->db = MainWP_Child_DB_Base::instance();
    }


    /**
     * Method install()
     *
     * Installs the new DB.
     *
     * @return void
     */
    public function install() { // phpcs:ignore -- NOSONAR - complex function. Current complexity is the only way to achieve desired results, pull request solutions appreciated.

        $currentVersion = get_option( Changes_Helper::CHANGES_LOGS_DB_OPTION_NAME );

        if ( $currentVersion === $this->db_version ) {
            return;
        }

        $collate = $this->db->wpdb->get_charset_collate();

        $sqls = array();

        $sql = ' CREATE TABLE `' . static::table_name( 'changes_logs' ) . '` (
                `id` bigint NOT NULL AUTO_INCREMENT,
                `blog_id` bigint NOT NULL,
                `log_type_id` bigint NOT NULL,
                `created_on` double NOT NULL,
                `client_ip` varchar(255) NOT NULL,
                `context` varchar(255) NOT NULL,
                `action_name` varchar(255) NOT NULL,
                `user_agent` varchar(255) NOT NULL,
                `user_roles` varchar(255) NOT NULL,
                `username` varchar(255) DEFAULT NULL,
                `user_id` bigint DEFAULT NULL,
                `duration` float(11,4) NOT NULL DEFAULT 0,
                `post_status` varchar(255) NOT NULL,
                `post_type` varchar(255) NOT NULL,
                `post_id` bigint NOT NULL';

        if ( empty( $currentVersion ) ) {
            $sql .= ',
				PRIMARY KEY (`id`),
				KEY `site_created` (`blog_id`,`created_on`),
				KEY `created_on` (`created_on`),
				KEY `changes_users` (`user_id`,`username`(191))';
        }
        $sql .= ')
        ' . $collate;

        $sqls[] = $sql;

        $sql = 'CREATE TABLE `' . static::table_name( 'changes_meta' ) . '` (
                `id` bigint(20) NOT NULL AUTO_INCREMENT,
                `log_id` bigint(20) NOT NULL,
                `name` varchar(100) NOT NULL,
                `value` longtext NOT NULL';
        if ( empty( $currentVersion ) ) {
            $sql .= ',
                PRIMARY KEY (`id`),
                KEY `log_name` (`log_id`,`name`),
                KEY `name_value` (`name`,`value`(64))';
        }
        $sql .= ') ' . $collate;

        $sqls[] = $sql;

        global $wpdb;

        require_once ABSPATH . 'wp-admin/includes/upgrade.php'; // NOSONAR - WP compatible.

        $suppress = $wpdb->suppress_errors();
        foreach ( $sqls as $query ) {
            \dbDelta( $query );
        }
        $wpdb->suppress_errors( $suppress );

        $this->update_db( $currentVersion );

        MainWP_Helper::update_option( Changes_Helper::CHANGES_LOGS_DB_OPTION_NAME, $this->db_version );
    }


    /**
     * List of meta fields.
     *
     * @var string[]
     */
    public static $mapping_db_fields = array(
        'mapping_fields' => array(
            'clientip'         => 'client_ip',
            'context'          => 'context',
            'actionname'       => 'action_name',
            'useragent'        => 'user_agent',
            'currentuserroles' => 'user_roles',
            'username'         => 'username',
            'currentuserid'    => 'user_id',
            'poststatus'       => 'post_status',
            'posttype'         => 'post_type',
            'postid'           => 'post_id',
        ),
        'logs_db_fields' => array(
            'id'          => 'bigint',
            'bog_id'      => 'bigint',
            'log_type_id' => 'bigint',
            'created_on'  => 'double',
            'client_ip'   => 'varchar(255)',
            'context'     => 'varchar(255)',
            'action_name' => 'varchar(255)',
            'user_agent'  => 'varchar(255)',
            'user_roles'  => 'varchar(255)',
            'username'    => 'varchar(255)',
            'user_id'     => 'bigint',
            'duration'    => 'float(11,4)',
            'post_status' => 'varchar(255)',
            'post_type'   => 'varchar(255)',
            'post_id'     => 'bigint',
        ),
        'meta_db_fields' => array(
            'id'     => 'int',
            'log_id' => 'int',
            'name'   => 'string',
            'value'  => 'string',
        ),
        'logs_db_values' => array(
            'id'          => 0,
            'bog_id'      => 0,
            'log_type_id' => 0,
            'created_on'  => 0.0,
            'client_ip'   => '',
            'context'     => '',
            'action_name' => '',
            'user_agent'  => '',
            'user_roles'  => '',
            'username'    => null,
            'user_id'     => null,
            'duration'    => 0,
            'post_status' => '',
            'post_type'   => '',
            'post_id'     => 0,
        ),
        'meta_db_values' => array(
            'id'     => 0,
            'log_id' => 0,
            'name'   => '',
            'value'  => '',
        ),
    );

    /**
     * Returns the the table name
     *
     * @param string $table Table prefix
     *
     * @return string
     */
    public static function table_name( $table ) {
        return MainWP_Child_DB_Base::instance()->get_table_name( $table );
    }

    /**
     * Update db if needed.
     *
     * @param string $cur_version Current db version.
     */
    public function update_db( $cur_version ) {
        if ( version_compare( $cur_version, '1.0.0', '>' ) && version_compare( $cur_version, '1.0.5', '<' ) ) {
            $this->db->wpdb->query( 'ALTER TABLE ' . static::table_name( 'changes_logs' ) . ' CHANGE COLUMN site_id blog_id double NOT NULL' ); //phpcs:ignore -- ok.
            $this->db->wpdb->query( 'ALTER TABLE ' . static::table_name( 'changes_logs' ) . ' DROP COLUMN session_id' ); //phpcs:ignore -- ok.
        }

        if ( ! empty( $cur_version ) && version_compare( $cur_version, '1.0.6', '<' ) ) {
            $this->db->wpdb->query( 'ALTER TABLE ' . static::table_name( 'changes_logs' ) . ' RENAME `object` TO `context` ' ); //phpcs:ignore -- ok.
        }
    }

    /**
     * Handle for storing the information in both log table and metadata table.
     * That one is optimized for DB performance
     *
     * @param array $data - The data to be stored.
     * @param int   $type_id - The event ID.
     * @param float $date - Formatted to UNIX timestamp date.
     * @param int   $blog_id - The site ID to store data for.
     *
     * @return void
     */
    public function save_record( $data, $type_id, $date, $blog_id ) {
        $record     = array();
        $map_fields = static::$mapping_db_fields['mapping_fields'];
        foreach ( (array) $data as $name => $value ) {
            if ( ( '0' === $value || ! empty( $value ) ) && isset( $map_fields[ $name ] ) ) {
                if ( 'currentuserroles' === $name ) {
                    $value = maybe_unserialize( $value );
                    if ( is_array( $value ) && ! empty( $value ) ) {
                        $record[ $map_fields[ $name ] ] = implode( ',', $value );
                    } elseif ( ! empty( $value ) ) {
                        $record[ $map_fields[ $name ] ] = $value;
                    }
                } else {
                    $record[ $map_fields[ $name ] ] = $value;
                }
                unset( $data[ $name ] );
            }

            if ( 'currentuserid' === $name && ! \is_object( $value ) && 0 === (int) $value ) {
                $record[ $map_fields[ $name ] ] = $value;
            }
        }

        if ( ! empty( $record ) ) {
            $record['created_on']  = $date;
            $record['log_type_id'] = $type_id;
            $record['blog_id']     = ! is_null( $blog_id ) ? $blog_id : Changes_Helper::get_blog_id();

            if ( in_array( $type_id, array( 1570, 1575 ) ) ) {
                if ( ! empty( $record['user_id'] ) ) {
                    $user = \get_user_by( 'ID', $record['user_id'] );
                    if ( $user ) {
                        $record['username'] = $user->user_login;
                    } else {
                        $record['username'] = 'Deleted';
                    }
                } elseif ( ! empty( $record['username'] ) ) {
                    $user = \get_user_by( 'login', $record['username'] );
                    if ( $user ) {
                        $record['user_id'] = $user->ID;
                    } else {
                        $record['user_id'] = 0;
                    }
                } else {
                    $record['user_id']  = 0;
                    $record['username'] = 'Unknown user';
                }
            }

            $record['duration'] = MainWP_Child_Actions::get_instance()->get_exec_time();

            $log_id = $this->save_log( $record );

            if ( 0 !== $log_id && ! empty( $data ) ) {
                $sqls = '';
                foreach ( (array) $data as $name => $value ) {
                    $meta_insert = array(
                        'log_id' => $log_id,
                        'name'   => $name,
                        'value'  => maybe_serialize( $value ),
                    );

                    $data_prepared = $this->prepare_meta_data( $meta_insert );

                    $fields  = '`' . implode( '`, `', array_keys( $data_prepared[0] ) ) . '`';
                    $formats = implode( ', ', $data_prepared[1] );

                    $sql = "($formats),";

                    $sqls .= $this->db->wpdb->prepare( $sql, $data_prepared[0] );
                }

                if ( ! empty( $sqls ) ) {
                    $sqls = 'INSERT INTO `' . static::table_name( 'changes_meta' ) . "` ($fields) VALUES " . rtrim( $sqls, ',' );
                    $this->db->wpdb->query( $sqls );
                }
            }
        }
    }


    /**
     * Prepares the data array
     *
     * @param array $data - The data.
     *
     * @return array
     */
    public function prepare_meta_data( $data ) {

        $format      = array();
        $insert_data = array();
        $meta_fields = static::$mapping_db_fields['meta_db_fields'];

        foreach ( $data as $key => $val ) {
            if ( isset( $meta_fields[ $key ] ) ) {
                $insert_data[ $key ] = $val;
                $format[ $key ]      = '%s';
                if ( 'int' === $meta_fields[ $key ] ) {
                    $format[ $key ] = '%d';
                }
                if ( 'float' === $meta_fields[ $key ] ) {
                    $format[ $key ] = '%f';
                }
            }
        }

        return array( $insert_data, $format );
    }

    /**
     * Saves the given data into the table
     *
     * @param array $data - The data to be saved.
     *
     * @return int
     */
    public function save_log( $data ) {
        if ( ! isset( $data['created_on'] ) ) {
            $data['created_on'] = microtime( true );

        }
        return $this->save_to_db( $data, static::table_name( 'changes_logs' ) );
    }

    /**
     * Saves the data into the table
     *
     * @param array $data - The data to be saved.
     *
     * @return int
     */
    public function save_to_db( $data ) {

        $format      = array();
        $insert_data = array();
        $log_fields  = static::$mapping_db_fields['logs_db_fields'];

        foreach ( $data as $key => $val ) {
            if ( isset( $log_fields[ $key ] ) ) {
                $insert_data[ $key ] = $val;
                $format[ $key ]      = '%s';
                if ( 'int' === $log_fields[ $key ] ) {
                    $format[ $key ] = '%d';
                }
                if ( 'float' === $log_fields[ $key ] ) {
                    $format[ $key ] = '%f';
                }
            }
        }

        if ( ! empty( $format ) ) {
            $this->db->wpdb->replace( static::table_name( 'changes_logs' ), $insert_data, $format );
            return $this->db->wpdb->insert_id;
        }

        return 0;
    }


    /**
     * Load Logs records (Multi rows).
     *
     * @param string $cond Load condition.
     * @param array  $args (Optional) Load condition arguments.
     * @param string $extra - The extra (if needed).
     *
     * @return array
     */
    public function load_logs_array( $cond, $args = array(), $extra = '' ) {

        $result = array();
        $sql    = $this->db->wpdb->prepare( 'SELECT * FROM ' . self::table_name( 'changes_logs' ) . ' WHERE ' . $cond, $args ); //phpcs:ignore --ok.

        if ( ! empty( $extra ) ) {
            $sql .= $extra;
        }

        $results     = $this->db->wpdb->get_results( $sql, ARRAY_A ); //phpcs:ignore -- ok.
        $logs_values = static::$mapping_db_fields['logs_db_values'];

        foreach ( $results as $data ) {
            foreach ( (array) $data as $key => $val ) {
                $data[ $key ] = $this->convert_fields_value( $key, $val, $logs_values );
            }
            $result[] = $data;
        }

        return $result;
    }


    /**
     * Returns a array of metadata.
     *
     * @param array $logs -  Array with all the events.
     *
     * @return array
     */
    public function get_log_meta_data( &$logs ) {

        $log_ids = array();

        foreach ( $logs as &$log ) {
            if ( ! isset( $log['id'] ) ) {
                return array();
            }
            $log_ids[] = (int) $log['id'];
        }
        unset( $log );

        $logs = \array_combine( $log_ids, $logs );

        $metas = $this->load_meta_by_logs_ids( $log_ids );

        foreach ( $metas as $meta ) {
            $logs[ $meta['log_id'] ]['meta_values'][ $meta['name'] ] = maybe_unserialize( $meta['value'] );
        }

        $log['meta_values'] = array();

        $map_fields = static::$mapping_db_fields['mapping_fields'];

        foreach ( $logs as &$log ) {
            foreach ( $map_fields as $meta_key => $column_name ) {
                $log['meta_values'][ $meta_key ] = $log[ $column_name ];
            }
        }
        unset( $log );

        return $logs;
    }


    /**
     * Loads all the meta records for the given log ids
     *
     * @param array $log_ids - Array with Occurrences IDs to search for.
     *
     * @return array
     */
    public function load_meta_by_logs_ids( $log_ids ) {

        if ( empty( $log_ids ) ) {
            return array();
        }

        $results = array();
        $sql     = 'SELECT * FROM ' . self::table_name( 'changes_meta' ) . ' WHERE log_id in (' . implode( ',', $log_ids ) . ')';
        $results = $this->db->wpdb->get_results( $sql, \ARRAY_A ); //phpcs:ignore -- ok.
        return $results;
    }

    /**
     * Collects and prepares all the metadata of the events.
     *
     * @param array $results - Array with all the data with events collected.
     *
     * @return array
     */
    public function prepare_log_with_meta_data( &$results ) {

        $prepared_array = array();

        $log_table_name  = static::table_name( 'changes_logs' );
        $meta_table_name = static::table_name( 'changes_meta' );

        $map_fields = static::$mapping_db_fields['mapping_fields'];

        $log_fields = static::$mapping_db_fields['logs_db_fields'];

        $logs_values = static::$mapping_db_fields['logs_db_values'];

        if ( is_array( reset( $results[0] ) ) || ( is_array( $results[0] ) && ! empty( $results[0] ) && ! isset( $results[0][0] ) ) ) {
            foreach ( $results as $row ) {
                $log_id = $row[ $log_table_name . 'id' ];
                if ( ! isset( $prepared_array[ $log_id ] ) ) {
                    foreach ( array_keys( $log_fields ) as $field ) {
                        $row_value                           = $row[ $log_table_name . $field ];
                        $prepared_array[ $log_id ][ $field ] = $this->convert_fields_value( $field, $row_value, $logs_values );
                    }
                    foreach ( $map_fields as $name => $field ) {
                        $prepared_array[ $log_id ]['meta_values'][ $name ] = maybe_unserialize( $row[ $log_table_name . $field ] );
                    }
                }
                $prepared_array[ $log_id ]['meta_values'][ $row[ $meta_table_name . 'name' ] ] = maybe_unserialize( $row[ $meta_table_name . 'value' ] );
            }
        }

        return $prepared_array;
    }

    /**
     * Convert fields values.
     *
     * @param string $key  Column key.
     * @param mixed  $val  Value.
     * @param array  $fields_values  Fields values.
     *
     * @return mixed
     */
    public function convert_fields_value( $key, $val, $fields_values ) {
        if ( ! is_null( $val ) && in_array( $key, array( 'user_id', 'username' ), true ) ) {
            if ( 'user_id' === $key ) {
                return intval( $val );
            } elseif ( 'username' === $key ) {
                return (string) $val;
            }
        } elseif ( 'roles' === $key ) {
            return is_array( $val ) ? implode( ',', $val ) : $val;
        } elseif ( isset( $fields_values[ $key ] ) ) {
            switch ( true ) {
                case is_string( $fields_values[ $key ] ):
                case Changes_Helper::is_ip_address( $val ):
                    return (string) $val;
                case is_array( $fields_values[ $key ] ):
                case is_object( $fields_values[ $key ] ):
                    $json_decoded_val = @json_decode( $val ); //phpcs:ignore --ok.
                    return is_null( $json_decoded_val ) ? $val : $json_decoded_val;
                case is_int( $fields_values[ $key ] ):
                    return (int) $val;
                case is_float( $fields_values[ $key ] ):
                    $num_arr = \explode( '.', $val );

                    $num_arr = array_slice( $num_arr, 0, 2 );

                    $num_arr = array_map( 'intval', $num_arr );

                    return implode( '.', $num_arr );
                case is_bool( $fields_values[ $key ] ):
                    return (bool) $val;
                default:
                    return '';
            }
        }
    }


    /**
     * Query with parameters.
     *
     * @param array $params - Query params.
     *
     * @return array
     */
    public function get_logs_data( $params ) {

        $select_fields = isset( $params['fields'] ) && is_array( $params['fields'] ) ? $params['fields'] : array();
        $logs_fields   = isset( $select_fields['tbllogs'] ) && is_array( $select_fields['tbllogs'] ) ? $select_fields['tbllogs'] : array();
        $meta_fields   = isset( $select_fields['tblmeta'] ) && is_array( $select_fields['tblmeta'] ) ? $select_fields['tblmeta'] : array();

        $order_by  = isset( $params['order_by'] ) ? $params['order_by'] : '';
        $limit     = isset( $params['limit'] ) ? intval( $params['limit'] ) : '';
        $with_meta = ! empty( $params['with_meta'] ) ? true : false;

        $where_clause  = ' WHERE 1 ';
        $query         = 'SELECT ';
        $order_clause  = '';
        $limit_clause  = '';
        $join_clause   = '';
        $select_clause = '';

        if ( ! empty( $order_by ) ) {
            $order_clause .= ' ORDER BY ' . $this->db->escape( $order_by );
        }

        if ( ! empty( $limit ) ) {
            $limit_clause = ' LIMIT ' . $this->db->escape( $limit );
        }

        if ( empty( $logs_fields ) && empty( $meta_fields ) ) {
            $logs_db_fields = static::$mapping_db_fields['logs_db_fields'];
            $tbl_alias      = 'tbllogs';

            foreach ( array_keys( $logs_db_fields ) as $field ) {
                $select_clause .= $tbl_alias . '.' . $field . ' AS ' . $tbl_alias . $field . ', ';
            }

            if ( $with_meta ) {
                $meta_db_fields = static::$mapping_db_fields['meta_db_fields'];
                $tbl_alias      = 'tblmeta';
                foreach ( array_keys( $meta_db_fields ) as $field ) {
                    $select_clause .= $tbl_alias . '.' . $field . ' AS ' . $tbl_alias . $field . ', ';
                }
            }

            $select_clause = \rtrim( $select_clause, ', ' );
        } else {
            foreach ( $select_fields as $tbl_alias => $select_fields ) {
                if ( is_array( $select_fields ) ) {
                    foreach ( $select_fields as $field => $alias ) {
                        $select_clause .= $tbl_alias . '.' . $field;
                        if ( $alias !== $field ) {
                            $select_clause .= ' AS ' . $alias;
                        }
                        $select_clause .= ', ';
                    }
                }
            }
            $select_clause = \rtrim( $select_clause, ', ' );
        }

        if ( $with_meta ) {
            $join_clause .= ' LEFT JOIN ' . static::table_name( 'changes_meta' ) . ' tblmeta ON tbllogs.id = tblmeta.log_id ';
        }

        $query .= $select_clause . ' FROM ' . static::table_name( 'changes_logs' ) . ' tbllogs ';
        $query .= ' ' . $join_clause . ' ' . $where_clause . ' ' . $order_clause . ' ' . $limit_clause;

        $results = $this->db->wpdb->get_results( $query, ARRAY_A ); //phpcs:ignore -- prepare ok.

        if ( empty( $results ) ) {
            $results = array( 0 => array( 0 ) );
        }

        return $results;
    }

    /**
     * Cleans up the database.
     *
     * @param string $max_sdate Max datetime string to keep logs.
     *
     * @return void
     */
    public function hook_remove_records( $max_sdate ) {
        $now       = time();
        $max_stamp = $now - ( strtotime( $max_sdate ) - $now );
        if ( empty( $max_stamp ) ) {
            return;
        }
        $this->delete( array( 'created_on <= %s' => intval( $max_stamp ) ) );
    }

    /**
     * Deletes records from the Changes Logs amd Metadata tables.
     *
     * @param array   $condition - Condition to be used in the query.
     * @param integer $limit - The limit for the query.
     * @param array   $order - The order clause.
     *
     * @return array - Number of deleted records.
     */
    public function delete( $condition = array(), $limit = 0, $order = array() ) {

        $sql = 'SELECT max(id) FROM ' . static::table_name( 'changes_logs' ) . ' WHERE 1 ';

        if ( ! empty( $condition ) ) {
            $sql .= ' AND ' . \array_key_first( $condition );
        }

        if ( ! empty( $order ) ) {
            $sql .= ' ORDER BY ' . \array_key_first( $order ) . ' ' . reset( $order );
        }

        if ( ! empty( $limit ) ) {
            $sql .= ' LIMIT ' . $order;
        }

        $biggest_id = (int) $this->db->wpdb->get_var( $this->db->wpdb->prepare( $sql, reset( $condition ) ) );

        if ( $biggest_id > 0 ) {
            $delete_meta = 'DELETE FROM ' . static::table_name( 'changes_meta' ) . ' WHERE log_id <= %s';

            $this->db->wpdb->query( $this->db->wpdb->prepare( $delete_meta, array( $biggest_id ) ) );

            $delete_log = 'DELETE FROM ' . static::table_name( 'changes_logs' ) . ' WHERE 1 ';

            if ( ! empty( $condition ) ) {
                $delete_log .= ' AND ' . \array_key_first( $condition );
            }

            if ( ! empty( $order ) ) {
                $delete_log .= ' ORDER BY ' . \array_key_first( $order ) . ' ' . reset( $order );
            }

            if ( ! empty( $limit ) ) {
                $delete_log .= ' LIMIT ' . $order;
            }
            return $this->db->wpdb->query( $this->db->wpdb->prepare( $delete_log, array( reset( $condition ) ) ) );
        }
        return false;
    }
}
