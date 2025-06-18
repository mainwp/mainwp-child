<?php
/**
 * Entity: Abstract.
 *
 * Changes Logs Class
 *
 * @since 5.4.1
 *
 * @package MainWP\Child
 */


declare(strict_types=1);

namespace MainWP\Child\Changes\Entities;

use MainWP\Child\MainWP_Child_DB_Base;
use MainWP\Child\Changes\Changes_Validator;
use MainWP\Child\Changes\Helpers\Changes_PHP_Helper;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Responsible for the common entity operations.
 */
abstract class Changes_Abstract_Entity {

    /**
     * Contains the table name.
     *
     * @var string
     */
    private static $table = '';

    /**
     * Holds the DB connection (for caching purposes)
     *
     * @var \wpdb Connection
     */
    private static $connection = null;

    /**
     * Keeps the info about the columns of the table - name, type
     *
     * @var array
     */
    private static $fields = array();


    /**
     * Returns the the table name
     *
     * @return string
     */
    public static function get_table_name() {
        return MainWP_Child_DB_Base::instance()->get_table_name( static::$table );
    }

    /**
     * Returns the current connection (used by the plugin)
     *
     * @return \wpdb
     */
    public static function get_connection() {
        if ( null === self::$connection ) {
            self::$connection = MainWP_Child_DB_Base::instance()->get_connection();
        }
        return self::$connection;
    }

    /**
     * Checks for given column existence using custom connection.
     *
     * @param string       $table_name - The name of the table.
     * @param string       $col_name - The name of the column.
     * @param string       $col_type - Type of the column.
     * @param boolean|null $is_null - Is it null.
     * @param mixed        $key - Is it key.
     * @param mixed        $default - The default value of the column.
     * @param mixed        $extra - Extra parameters.
     *
     * @return boolean - True if the column exists and all given parameters are the same, false otherwise.
     */
    public static function check_column(
        string $table_name,
        string $col_name,
        string $col_type,
        ?bool $is_null = null,
        $key = null,
        $default = null,
        $extra = null
    ): bool {

        $diffs   = 0;
        $results = static::get_connection()->get_results( "DESC $table_name" );

        foreach ( $results as $row ) {

            if ( $row->Field === $col_name ) { // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase

                // Got our column, check the params.
                if ( ( null !== $col_type ) && ( strtolower( str_replace( ' ', '', $row->Type ) ) !== strtolower( str_replace( ' ', '', $col_type ) ) ) ) { // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
                    ++$diffs;
                }
                if ( ( null !== $is_null ) && ( $row->Null !== $is_null ) ) { // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
                    ++$diffs;
                }
                if ( ( null !== $key ) && ( $row->Key !== $key ) ) { // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
                    ++$diffs;
                }
                if ( ( null !== $default ) && ( $row->Default !== $default ) ) { // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
                    ++$diffs;
                }
                if ( ( null !== $extra ) && ( $row->Extra !== $extra ) ) { // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
                    ++$diffs;
                }

                if ( $diffs > 0 ) {
                    return false;
                }

                return true;
            } // End if found our column.
        }

        return false;
    }

    /**
     * Checks if the given index exists in the table
     *
     * @param string $index - The index to check for (text).
     *
     * @return boolean
     */
    public static function check_index_exists( string $index ): bool {
        $index = \sanitize_text_field( $index );

        $results = static::get_connection()->get_results( 'SHOW INDEX FROM ' . self::get_table_name(), \ARRAY_A );

        foreach ( $results as $row ) {
            if ( isset( $row['Key_name'] ) && $row['Key_name'] === $index ) {
                return true;
            }
        }

        return false;
    }

    /**
     * Checks and returns last mysql error
     *
     * @param \wpdb $_wpdb - The Mysql resource class.
     *
     * @return integer
     */
    public static function get_last_sql_error( $_wpdb ): int {
        $code = 0;
        if ( $_wpdb->dbh instanceof \mysqli ) {
            $code = \mysqli_errno( $_wpdb->dbh ); // phpcs:ignore WordPress.DB.RestrictedFunctions.mysql_mysqli_errno
        }

        if ( is_resource( $_wpdb->dbh ) ) {
            // Please do not report this code as a PHP 7 incompatibility. Observe the surrounding logic.
            $code = mysql_errno( $_wpdb->dbh ); // phpcs:ignore WordPress.DB.RestrictedFunctions.mysql_mysql_errno
        }
        return $code;
    }

    /**
     * Checks if give table exists
     *
     * @param string $table_name - The table to check for.
     *
     * @return boolean
     * - added $connection parameter
     */
    public static function check_table_exists( $table_name ): bool {
        $_wpdb = static::get_connection();
        foreach ( $_wpdb->get_col( 'SHOW TABLES', 0 ) as $table ) {
            if ( $table === $table_name ) {
                return true;
            }
        }
        return false;
    }


    /**
     * Saves the given data into the table
     * The data should be in following format:
     * field name => value
     *
     * It checks the given data array against the table fields and determines the types based on that, it stores the values in the table then.
     *
     * @param array $data - The data to be saved (check above about the format).
     *
     * @return int
     */
    public static function save( $data ) {

        $format      = array();
        $insert_data = array();

        foreach ( $data as $key => $val ) {
            if ( isset( ( static::class )::$fields[ $key ] ) ) {
                $insert_data[ $key ] = $val;
                $format[ $key ]      = '%s';
                if ( 'int' === ( static::class )::$fields[ $key ] ) {
                    $format[ $key ] = '%d';
                }
                if ( 'float' === ( static::class )::$fields[ $key ] ) {
                    $format[ $key ] = '%f';
                }
            }
        }

        if ( ! empty( $format ) ) {
            $_wpdb = static::get_connection();

            $_wpdb->suppress_errors( true );

            $_wpdb->replace( self::get_table_name(), $insert_data, $format );

            $_wpdb->suppress_errors( false );

            return $_wpdb->insert_id;
        }

        return 0; // no record is inserted.
    }

    /**
     * Prepares the data array and the format array based on the existing table fields
     *
     * @param array $data - The data to make preparation from.
     *
     * @return array
     */
    public static function prepare_data( array $data ): array {

        $format      = array();
        $insert_data = array();

        foreach ( $data as $key => $val ) {
            if ( isset( ( static::class )::$fields[ $key ] ) ) {
                $insert_data[ $key ] = $val;
                $format[ $key ]      = '%s';
                if ( 'int' === ( static::class )::$fields[ $key ] ) {
                    $format[ $key ] = '%d';
                }
                if ( 'float' === ( static::class )::$fields[ $key ] ) {
                    $format[ $key ] = '%f';
                }
            }
        }

        return array( $insert_data, $format );
    }

    /**
     * Load record from DB (Single row).
     *
     * @param string $cond - (Optional) Load condition.
     * @param array  $args - (Optional) Load condition arguments.
     * @param string $extra - The extra SQL string (if needed).
     *
     * @return array
     */
    public static function load( $cond = 'id=%d', $args = array( 1 ), $extra = '' ) {
        $_wpdb = static::get_connection();
        $sql = $_wpdb->prepare( 'SELECT * FROM ' . self::get_table_name() . ' WHERE ' . $cond, $args );

        if ( ! empty( trim( $extra ) ) ) {
            $sql .= $extra;
        }

        return $_wpdb->get_row( $sql, ARRAY_A );
    }

    /**
     * Load records from DB (Multi rows).
     *
     * @param string $cond Load condition.
     * @param array  $args (Optional) Load condition arguments.
     * @param \wpdb  $connection - \wpdb connection to be used for name extraction.
     * @param string $extra - The extra SQL string (if needed).
     *
     * @return array
     */
    public static function load_array( $cond, $args = array(), $connection = null, $extra = '' ) {

        if ( null !== $connection ) {
            if ( $connection instanceof \wpdb ) {
                $_wpdb = $connection;
            }
        } else {
            $_wpdb = static::get_connection();
        }

        $result = array();
        $sql    = $_wpdb->prepare( 'SELECT * FROM ' . self::get_table_name() . ' WHERE ' . $cond, $args );

        if ( ! empty( trim( $extra ) ) ) {
            $sql .= $extra;
        }

        $_wpdb->suppress_errors( true );
        $results = $_wpdb->get_results( $sql, ARRAY_A );
        $_wpdb->suppress_errors( false );

        foreach ( $results as $data ) {
            $result[] = static::load_data( $data );
        }

        return $result;
    }

    /**
     * Load object data from variable.
     *
     * @param array|object $data Data array or object.
     * @throws \Exception - Unsupported type.
     */
    public static function load_data( $data ) {
        foreach ( (array) $data as $key => $val ) {
            $data[ $key ] = self::cast_to_correct_type( $key, $val );
        }
        return $data;
    }

    /**
     * Casts given value to a correct type based on the type of property (identified by the $key) in the $copy object.
     * This is to allow automatic type casting instead of handling each database column individually.
     *
     * @param string $key  Column key.
     * @param mixed  $val  Value.
     *
     * @return mixed
     * @throws \Exception - Unsupported type of data.
     */
    public static function cast_to_correct_type( $key, $val ) {
        if ( ! is_null( $val ) && in_array( $key, array( 'user_id', 'username' ), true ) ) {
            // Username and user_id cannot have the default value set because some database queries rely on having
            // null values in the database.
            if ( 'user_id' === $key ) {
                return intval( $val );
            } elseif ( 'username' === $key ) {
                return (string) $val;
            }
        } elseif ( 'roles' === $key ) {
            return is_array( $val ) ? implode( ',', $val ) : $val;
        } elseif ( isset( ( static::class )::$fields_values[ $key ] ) ) {
            switch ( true ) {
                case is_string( ( static::class )::$fields_values[ $key ] ):
                case Changes_Validator::is_ip_address( $val ):
                    return (string) $val;
                case is_array( ( static::class )::$fields_values[ $key ] ):
                case is_object( ( static::class )::$fields_values[ $key ] ):
                    $json_decoded_val = Changes_PHP_Helper::json_decode( $val );
                    return is_null( $json_decoded_val ) ? $val : $json_decoded_val;
                case is_int( ( static::class )::$fields_values[ $key ] ):
                    return (int) $val;
                case is_float( ( static::class )::$fields_values[ $key ] ):
                    $num_arr = \explode( '.', $val );

                    $num_arr = array_slice( $num_arr, 0, 2 );

                    $num_arr = array_map( 'intval', $num_arr );

                    return implode( '.', $num_arr );
                case is_bool( ( static::class )::$fields_values[ $key ] ):
                    return (bool) $val;
                default:
                    throw new \Exception( \esc_html__( 'Unsupported type "', 'mainwp-child' ) . gettype( ( static::class )::$fields_values[ $key ] ) . '"' ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
            }
        }
    }

    /**
     * Delete records in DB matching a query.
     *
     * @param string $query Full SQL query.
     * @param array  $args  (Optional) Query arguments.
     * @param \wpdb  $connection - \wpdb connection to be used for name extraction.
     *
     * @return int|bool
     */
    public static function delete_query( $query, $args = array(), $connection = null ) {
        if ( null !== $connection ) {
            if ( $connection instanceof \wpdb ) {
                $_wpdb = $connection;
            }
        } else {
            $_wpdb = static::get_connection();
        }

        $sql = count( $args ) ? $_wpdb->prepare( $query, $args ) : $query;

        $_wpdb->suppress_errors( true );
        $res = $_wpdb->query( $sql );
        $_wpdb->suppress_errors( false );

        return $res;
    }

    /**
     * Deletes data from the database.
     *
     * @param array $data - Array of data to be deleted.
     * @param \wpdb $connection - \wpdb connection to be used for name extraction.
     *
     * @return bool
     */
    public static function delete( $data, $connection = null ) {
        if ( isset( $data['id'] ) ) {
            return self::delete_by_id( (int) $data['id'], $connection );
        }
    }

    /**
     * Default delete method
     *
     * @param integer $id - The real id of the table.
     * @param \wpdb   $connection - \wpdb connection to be used for name extraction.
     *
     * @return int|bool
     */
    public static function delete_by_id( int $id, $connection = null ) {
        if ( null !== $connection ) {
            if ( $connection instanceof \wpdb ) {
                $_wpdb = $connection;
            }
        } else {
            $_wpdb = static::get_connection();
        }

        $result = $_wpdb->delete(
            self::get_table_name(),
            array( 'id' => $id ),
            array( '%d' )
        );

        return $result;
    }

    /**
     * Returns array with fields to duplicate, gets rid of id and created_on columns.
     *
     * @param bool $duplicate_values - When called for duplication, gives the class ability to set fields that must have specific values in the database.
     *
     * @return array
     */
    public static function get_duplicate_fields( bool $duplicate_values ): array {
        $fields = self::get_fields();
        unset( $fields['id'] );
        if ( $duplicate_values && isset( $fields['created_on'] ) ) {
            $fields = \array_keys( $fields );
            $time   = \microtime( true );
            $key    = array_search( 'created_on', $fields, true );

            $fields[ $key ] = $time;

            return $fields;
        }

        return array_keys( $fields );
    }

    /**
     * Default find method
     *
     * @param array $data - Must contains formats and data. The array should contain:
     * 'data' - Associative array of all the fields and values to search for.
     * 'formats' - array of all the formats for the data we are searching for.
     *
     * @return array|bool
     */
    public static function find( array $data ) {
        /**
         * \wpdb has very powerful method called process_fields @see \wpdb::process_fields().
         * Unfortunately this method is not accessible, because it is marked protected. The best solution at the moment is to clone the class, lower the visibility and use the method.
         *
         * That of course takes resources so possible solution is to add also caching to this method, so that is marked as todo below.
         *
         * TODO: Add caching functionality to the method.
         */
        // phpcs:disable
        $wpdb_clone = new class() extends \wpdb {

            public function __construct() {
                $dbuser     = defined( 'DB_USER' ) ? DB_USER : '';
                $dbpassword = defined( 'DB_PASSWORD' ) ? DB_PASSWORD : '';
                $dbname     = defined( 'DB_NAME' ) ? DB_NAME : '';
                $dbhost     = defined( 'DB_HOST' ) ? DB_HOST : '';

                parent::__construct( $dbuser, $dbpassword, $dbname, $dbhost );
            }

            public function process_fields( $name, $data, $formats ) {
                return parent::process_fields( $name, $data, $formats );
            }

        };
        // phpcs:enable

        $_wpdb = static::get_connection();

        $where_clause = $wpdb_clone->process_fields(
            self::get_table_name(),
            $data['data'],
            $data['formats']
        );

        $where_data = self::prepare_full_where( $where_clause );

        $conditions = $where_data['conditions'];
        $values     = $where_data['values'];

        $_wpdb->check_current_query = false;

        $sql = $_wpdb->prepare(
            'SELECT * FROM `' . self::get_table_name() . '` WHERE ' . $conditions,
            $values
        );

        $_wpdb->suppress_errors( true );

        $result = $_wpdb->get_results(
            $sql,
            ARRAY_A
        );
        $_wpdb->suppress_errors( false );

        return $result;
    }

    /**
     * Prepares full where clause
     *
     * @param array        $where_clause - Array prepared based on fields and values from the WP_DB.
     * @param string       $condition - The where clause condition - default AND.
     * @param string|null  $criteria - The criteria to check for.
     * @param boolean|null $left_pref - For any starting value - partial where clause.
     * @param boolean|null $right_pref - For any ending value - partial where clause.
     *
     * @return array
     */
    public static function prepare_full_where(
        array $where_clause,
        string $condition = ' AND ',
        ?string $criteria = ' = ',
        ?bool $left_pref = false,
        ?bool $right_pref = false
    ): array {

        foreach ( $where_clause as $field => $value ) {
            if ( is_null( $value['value'] ) ) {
                $conditions[] = '`' . self::get_table_name() . '` . `' . $field . '` IS null';
                continue;
            }

            if ( \is_array( $value['value'] ) ) {
                $cond_string  = '(`' . self::get_table_name() . '` . `' . $field . '` ';
                $cond_string .= ' BETWEEN ';
                foreach ( $value['value'] as $val ) {
                    $cond_string .= $value['format'] . ' ' . $condition . ' ';
                    $values[]     =
                    ( ( $left_pref ) ? ' % ' : '' ) .
                    $val .
                    ( ( $right_pref ) ? ' % ' : '' );
                }
                $cond_string  = rtrim( $cond_string, ' ' . $condition . ' ' ) . ')';
                $conditions[] = $cond_string;

                continue;
            }

            $conditions[] = '`' . self::get_table_name() . '` . `' . $field . '` ' . $criteria . ' ' .
            $value['format'];
            $values[]     =
            ( ( $left_pref ) ? ' % ' : '' ) .
            $value['value'] .
            ( ( $right_pref ) ? ' % ' : '' );
        }

        $conditions = implode( ' ' . $condition . ' ', $conditions );

        return array(
            'conditions' => $conditions,
            'values'     => $values,
        );
    }

    /**
     * Similar to LoadMulti but allows the use of a full SQL query.
     *
     * @param string $query Full SQL query.
     * @param array  $args  (Optional) Query arguments.
     *
     * @return array List of loaded records.
     */
    public static function load_multi_query( $query, $args = array() ) {
        $_wpdb  = static::get_connection();
        $result = array();
        $sql    = count( $args ) ? $_wpdb->prepare( $query, $args ) : $query;
        foreach ( $_wpdb->get_results( $sql, ARRAY_A ) as $data ) {
            $result[] = self::load_data( $data );
        }

        return $result;
    }

    /**
     * Builds SQL for given table.
     *
     * @param string       $query - Partial SQL query string.
     * @param array|string $args - Arguments to be applied to the query.
     *
     * @return array
     */
    public static function build_multi_query( $query, $args ) {
        $sql_query = 'SELECT * FROM `' . self::get_table_name() . '` ' . $query;

        return self::load_multi_query( $sql_query, $args );
    }

    /**
     * Load multiple records from DB.
     *
     * @param string $cond (Optional) Load condition (eg: 'some_id = %d' ).
     * @param array  $args (Optional) Load condition arguments (rg: array(45) ).
     *
     * @return array List of loaded records.
     */
    public static function load_multi( $cond, $args = array() ) {
        $_wpdb  = static::get_connection();
        $result = array();
        $sql    = ( ! is_array( $args ) || ! count( $args ) ) // Do we really need to prepare() or not?
            ? ( $cond )
            : $_wpdb->prepare( $cond, $args );

        $_wpdb->suppress_errors( true );

        $data_collected = $_wpdb->get_results( $sql, ARRAY_A );
        $_wpdb->suppress_errors( false );

        foreach ( $data_collected as $data ) {
            foreach ( $data as $key => $val ) {
                $data[ $key ] = self::cast_to_correct_type( $key, $val );
            }

            $sql = $_wpdb->prepare( 'SELECT * FROM ' . Changes_Metadata_Entity::get_table_name() . ' WHERE  log_id = %d', $data['id'] );

            $_wpdb->suppress_errors( true );
            $results = $_wpdb->get_results( $sql, ARRAY_A );
            $_wpdb->suppress_errors( false );
            $meta_results = array();
            foreach ( $results as $meta_key => $meta_val ) {
                $json_decoded_val                  = Changes_PHP_Helper::json_decode( $meta_val['value'] );
                $val                               = is_null( $json_decoded_val ) ? $meta_val['value'] : $json_decoded_val;
                $meta_results[ $meta_val['name'] ] = maybe_unserialize( $val );
            }

            foreach ( Changes_Metadata_Entity::$migrated_meta as $meta_key => $column_name ) {
                $meta_results[ $meta_key ] = $data[ $column_name ];
            }

            $data['meta_values'] = $meta_results;

            $result[] = $data;
        }

        return $result;
    }

    /**
     * Return the table fields and default values
     *
     * @return array
     */
    public static function get_fields_values(): array {
        return ( static::class )::$fields_values;
    }

    /**
     * Return the table fields
     *
     * @return array
     */
    public static function get_fields(): array {
        return ( static::class )::$fields;
    }

    /**
     * Builds a query based on the given parameters.
     * The array could contain of:
     *  - All ORs in the WHERE
     *  - All ANDs in the WHERE
     * It must be SQL string with interpolation, and its value.
     *
     * @param array $select_fields - Fields to use in the select statement of the query.
     * @param array $query_parameters - Array with all the where clause expressions.
     * @param array $order_by - Ordering array with fields and type of order.
     * @param array $limit - Limit section of the query - array with range (if there is a need of range).
     * @param array $join - Join section of the query.
     * @param \wpdb $connection - \wpdb connection to be used for name extraction.
     *
     * @return array
     */
    public static function build_query(
        array $select_fields = array(),
        array $query_parameters = array(),
        array $order_by = array(),
        array $limit = array(),
        array $join = array(),
        $connection = null
    ) {

        $where_clause = ' WHERE 1 ';
        $query        = 'SELECT ';
        $order_clause = '';
        $limit_clause = '';
        $join_clause  = '';

        $values     = array();
        $sql_values = array();

        $string_clause = '';
        /**
         * $query_parameters = [
         *   OR = [
         *      0 = [
         *         'log_type_id' = '"%s"' = [
         *            0 = "1007"
         *         ]
         *      ]
         *   ]
         * $query_parameters = [
         *   'AND' = [
         *      0 = [
         *          'changes_metadata.log_type_id LIKE %s' = '"9073"'
         *      ]
         *   ]
         *   'OR' = [
         *      0 = [
         *         'log_type_id' = '"%s"' = [
         *            0 = '"9073"'
         *            1 = '"4015"'
         *         ]
         *      ]
         *      1 = [
         *         'object' = '"%s"' = [
         *            0 = 'woocommerce-product'
         *         ]
         *      ]
         *   ]
         *
         * WHERE 1  AND () )
         */
        $first_time = true;
        if ( isset( $query_parameters['OR'] ) && ! empty( $query_parameters['OR'] ) && isset( $query_parameters['AND'] ) && ! empty( $query_parameters['AND'] ) ) {

            $query_parameters = array(
                'OR'  => $query_parameters['OR'],
                'AND' => $query_parameters['AND'],
            );

        }
        foreach ( $query_parameters as $clause => $values ) {
            if ( ! $first_time ) {
                $string_clause .= ' ' . \strtoupper( $clause ) . ' ( ';
            } else {
                $string_clause .= ' ( ';
            }
            $first_time          = false;
            $sub_join_clause     = '';
            $sub_sub_join_clause = '';
            foreach ( $values as $sub_clause => $sub_values ) {
                $sub_join_clause = '';
                if ( ! \is_int( $sub_clause ) ) {
                    $sub_join_clause = $sub_clause;
                    if ( ' ( ' !== $string_clause ) {
                        $string_clause = \rtrim( $string_clause, ' ( ' );
                        if ( ! $first_time ) {
                            $string_clause = \rtrim( $string_clause, ' ' . \strtoupper( $clause ) . ' ' );
                        }

                        if ( empty( $sub_sub_join_clause ) ) {
                            $string_clause .= ' ' . \strtoupper( $clause ) . ' ( ( ';
                        } elseif ( ! empty( $sub_sub_join_clause ) ) {
                            $string_clause      .= ' ' . \strtoupper( $clause ) . ' ( ';
                            $sub_sub_join_clause = '';
                        }
                    } else {
                        $string_clause .= ' ( ';
                    }
                }
                if ( \is_array( $sub_values ) ) {
                    foreach ( $sub_values as $value => $val ) {
                        $sql_val = $val;
                        if ( ! \is_int( $value ) ) {
                            $sub_sub_join_clause = $value;
                        } else {
                            $sub_sub_join_clause = $sub_join_clause;
                        }
                        if ( \is_array( $val ) ) {
                            foreach ( $val as $sql_string => $substitutes ) {
                                $sql_value = $sql_string;
                                if ( \is_array( $substitutes ) ) {
                                    foreach ( $substitutes as $substitute ) {
                                        $sql_values[] = $substitute;

                                        if ( ! empty( $sub_sub_join_clause ) ) {
                                            $logic_join = $sub_sub_join_clause;
                                        } else {
                                            $logic_join = $clause;
                                        }
                                        $string_clause .= '(' . $sql_value . ') ' . \strtoupper( $logic_join ) . ' ';
                                    }
                                } else {
                                    if ( ! empty( $sub_sub_join_clause ) ) {
                                        $logic_join = $sub_sub_join_clause;
                                    } else {
                                        $logic_join = $clause;
                                    }
                                    $string_clause .= '(' . $sql_value . ') ' . \strtoupper( $logic_join ) . ' ';
                                    if ( \is_array( $sql_val ) ) {
                                        $sql_values[] = reset( $sql_val );
                                    } else {
                                        $sql_values[] = $sql_val;
                                    }
                                }
                            }

                            unset( $sub_values[ $value ] );

                            $string_clause = \rtrim( $string_clause, $sub_sub_join_clause . ' ' );
                            if ( count( $sub_values ) ) {
                                $string_clause .= ' ) ' . $clause . ' ( ';
                            } else {
                                $string_clause .= ' ) ';
                            }
                        } else {
                            if ( ! empty( $sub_join_clause ) ) {
                                $logic_join = $sub_join_clause;
                                $value      = \array_key_first( $val );
                                $sql_val    = reset( $val[ \array_key_first( $val ) ] );
                            } else {
                                $logic_join = $clause;
                            }
                            $string_clause .= '(' . $value . ') ' . \strtoupper( $logic_join ) . ' ';
                            $sql_values[]   = $sql_val;

                            unset( $values[ $sub_clause ] );
                            if ( 0 === count( $values ) ) {
                                $string_clause  = \rtrim( $string_clause, \strtoupper( $logic_join ) . ' ' );
                                $string_clause .= ' ) ';
                            }
                        }
                    }
                } elseif ( \is_array( $sub_values ) ) {
                        $string_clause .= '(' . \array_key_first( $sub_values ) . ')';
                        $sql_values[]   = reset( $sub_values );
                } else {
                    $string_clause .= '(' . $sub_clause . ')';
                    $sql_values[]   = $sub_values;
                }
            }
            if ( ! empty( $sub_join_clause ) ) {
                $logic_join = $sub_join_clause;
            } else {
                $logic_join = $clause;
            }
            $string_clause = \rtrim( $string_clause, $logic_join . ' ' );
            if ( isset( $sub_clause ) && ! \is_int( $sub_clause ) ) {
                $sub_join_clause = $sub_clause;
                $string_clause  .= ' ) ';
            }
            if ( empty( $sub_sub_join_clause ) ) {
                $string_clause .= ' ) ';
            }
        }

        if ( ! empty( $string_clause ) ) {
            $where_clause .= ' AND (' . $string_clause . ')';
        }

        if ( ! empty( $order_by ) ) {
            $order_clause .= ' ORDER BY ';
            foreach ( $order_by as $clause => $order ) {
                $order_clause .= $clause . ' ' . $order . ', ';
            }
            $order_clause = \rtrim( $order_clause, ', ' );
        }

        if ( ! empty( $limit ) ) {
            $limit_clause = ' LIMIT ';
            foreach ( $limit as $value ) {
                $limit_clause .= $value . ', ';
            }

            $limit_clause = \rtrim( $limit_clause, ', ' );
        }

        if ( ! empty( $select_fields ) ) {
            foreach ( $select_fields as $field => $alias ) {
                $query .= $field;
                if ( $alias !== $field ) {
                    $query .= ' AS ' . $alias;
                }
                $query .= ', ';
            }
            $query = \rtrim( $query, ', ' );
        } else {
            $query .= self::get_table_name() . '.*';
        }

        /**
         * Join format:
         * [
         *   'table_name' - Table to join
         *        [
         *           'direction' - Left, right, full - type of the join.
         *           'join_fields' - fields and corresponding tables to join.
         *              [
         *                 'join_table_left' - If it is set, join given table, if not use the current
         *                 'join_field_left' - name of the field to join
         *                 'join_table_right' - If it is set, join given table, if not use the default (no table)
         *                 'join_field_right' - name of the field to join
         *              ]
         *        ]
         * ]
         */
        if ( ! empty( $join ) ) {
            foreach ( $join as $table => $rules ) {

                if ( isset( $rules['direction'] ) ) {
                    $join_clause .= ' ' . \strtoupper( $rules['direction'] ) . ' JOIN ' . $table . ' ON ';
                } else {
                    $join_clause .= ' LEFT JOIN ' . $table . ' ON ';
                }

                foreach ( $rules['join_fields'] as $fields ) {
                    if ( isset( $fields['join_table_left'] ) ) {
                        $join_clause .= $fields['join_table_left'] . '.';
                    } else {
                        $join_clause .= $table . '.';
                    }
                    $join_clause .= $fields['join_field_left'] . ' = ';
                    if ( isset( $fields['join_table_right'] ) ) {
                        $join_clause .= $fields['join_table_right'] . '.';
                    }
                    $join_clause .= $fields['join_field_right'] . ' AND ';
                }

                $join_clause = rtrim( $join_clause, ' AND ' );
            }
        }

        $query .= ' FROM ' . self::get_table_name();
        $query .= ' ' . $join_clause . ' ' . $where_clause . ' ' . $order_clause . ' ' . $limit_clause;

        if ( null !== $connection ) {
            if ( $connection instanceof \wpdb ) {
                $_wpdb = $connection;
            }
        } else {
            $_wpdb = static::get_connection();
        }

        $sql_values = array_filter( $sql_values );

        // If it is simple query without placeholders - lets act properly.
        if ( ! empty( $sql_values ) ) {
            $sql = $_wpdb->prepare( $query, $sql_values );
        } else {
            $sql = $query;
        }

        $_wpdb->suppress_errors( true );
        $results = $_wpdb->get_results( $sql, ARRAY_A );
        $_wpdb->suppress_errors( false );

        if ( empty( $results ) ) {
            $results = array( 0 => array( 0 ) );
        }

        return $results;
    }

    /**
     * Creates array with the full filed names (table name included) for the given table
     *
     * @param \wpdb $connection - \wpdb connection to be used for name extraction.
     *
     * @return array
     */
    public static function prepare_full_select_statement( $connection = null ): array {
        $full_fields = array();
        foreach ( \array_keys( ( static::class )::$fields ) as $field ) {
            $full_fields[ self::get_table_name() . '.' . $field ] = self::get_table_name() . $field;
        }

        return $full_fields;
    }

    /**
     * Returns the table size in Megabyte format
     *
     * @param \wpdb $connection - The connection object to be used, if empty the default is used.
     *
     * @return int
     */
    public static function get_table_size( $connection = null ) {
        if ( null !== $connection ) {
            if ( $connection instanceof \wpdb ) {
                $_wpdb = $connection;
            }
        } else {
            $_wpdb = static::get_connection();
        }

        $sql = "SELECT
            ROUND(((data_length + index_length) / 1024 / 1024), 2) AS `Size (MB)`
        FROM
            information_schema.TABLES
        WHERE
            table_schema = '" . $_wpdb->dbname . "'
            AND table_name = '" . self::get_table_name() . "';";

        $_wpdb->suppress_errors( true );
        $results = $_wpdb->get_var( $sql );
        $_wpdb->suppress_errors( false );

        if ( $results ) {
            return $results;
        }

        return 0;
    }
}
