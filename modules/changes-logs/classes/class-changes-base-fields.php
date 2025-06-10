<?php
/**
 * Extension: Base fields.
 *
 * @since 5.1.0
 *
 * @package   mainwp/child
 */

declare(strict_types=1);

namespace MainWP\Child\Changes;

use MainWP\Child\Changes\Entities\Changes_Metadata_Entity;
use MainWP\Child\Changes\Entities\Changes_Logs_Entity;
use MainWP\Child\Changes\Helpers\Changes_DateTime_Formatter_Helper;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Provides fields searching functionality.
 *
 */
class Changes_Base_Fields {
    /**
     * Filed must have:
     * - unique name of field (no spaces)
     * - DB relation (which DB field represents the field)
     * - type
     * - validation
     */

    /**
     * Mapper method - maps all the fields for searching to the corresponding fields (and how to extract value if they are not directly callable from the occurrence table)
     *
     * @return array
     *
     * @since 5.0.0
     */
    public static function prepare_all_fields(): array {

        // Collects the default table fields.
        $fields = Changes_Logs_Entity::get_fields();

        $table_meta = Changes_Metadata_Entity::get_table_name();

        /**
         * Logic for searching in the meta table for some values.
         *
         * @param sql_prefix - The SQL prefix to search for given values
         * @param sub_sql_string - The SQL part of the query used to search for specific values (how)
         * @param sql_suffix - The SQL suffix for the search query
         * @param column_name - Which column from the occurrences table to map the results to
         */
        $fields_subs = array(
            'post_title' => array(
                'sql_prefix'     => 'SELECT occurrence_id FROM ' . $table_meta . ' as meta WHERE meta.name=\'PostTitle\' AND ( ',
                'sub_sql_string' => "( (meta.value LIKE '%s') > 0 )",
                'sql_suffix'     => ' )',
                'column_name'    => 'occurrence_id',
            ),
        );

        /**
         * All the user fields logic for collecting
         * That differs because the users can be searched using mails, names, roles etc...
         *
         * With this we are mapping the logic for extraction the user IDs using different user searc criteria
         *
         * @param call - Which method (and class) to call with the parameters.
         * @param field_name - The name of the field to extract data from.
         * @param in_table - Marks what to use for searching in the occurrences table (they are currently the same)
         */
        $user_fields = array(
            'user_first_name' => array(
                'call'       => array( self::class, 'users_search' ),
                'field_name' => 'first_name',
                'in_table'   => array(
                    'user_id'  => 'ID',
                    'username' => 'user_login',
                ),
            ),
            'user_last_name'  => array(
                'call'       => array( self::class, 'users_search' ),
                'field_name' => 'last_name',
                'in_table'   => array(
                    'user_id'  => 'ID',
                    'username' => 'user_login',
                ),
            ),
            'user_email'      => array(
                'call'       => array( self::class, 'users_search' ),
                'field_name' => 'user_email',
                'in_table'   => array(
                    'user_id'  => 'ID',
                    'username' => 'user_login',
                ),
            ),
            'user_role'       => array(
                'call'     => array( self::class, 'users_search' ),
                'extract'  => 'role',
                'in_table' => array(
                    'user_id'  => 'ID',
                    'username' => 'user_login',
                ),
            ),
            'user_id'         => array(
                'call'       => array( self::class, 'users_search' ),
                'field_name' => 'ID',
                'in_table'   => array(
                    'user_id'  => 'ID',
                    'username' => 'user_login',
                ),
            ),
        );

        $fields_aliases = array(
            'post_title' => array( 'post_name' ),
        );

        foreach ( array_keys( $fields_subs ) as $key ) {
            if ( isset( $fields_aliases[ $key ] ) ) {
                foreach ( $fields_aliases[ $key ] as $alias ) {
                    $fields_subs[ $alias ] = $fields_subs[ $key ];
                }
            }
        }

        // Dates.
        $dates = array(
            'start_date' => 'date',
            'end_date'   => 'date',
        );

        return \array_merge( $fields_subs, $fields, $dates, $user_fields );
    }

    /**
     * Parses the given search string (human readable) and returns the WHERE SQL statement based on the given string logic. To construct this use "field_name" followed by ":" and then the value (no spaces are supported) if there is a space in the string use '"'. For exclusions use "-" before the field name.
     *
     * @param string $search_string - Example: alert_id:1000 post_name:"something" user_id:1 .
     *
     * @return string
     *
     * @since 5.0.0
     */
    public static function string_to_search( string $search_string ): string {
        /*
        * alert_id:1000 post_name:"something" user_id:1
        */

        // There is nothing to parse - bounce.
        if ( ! \strpos( $search_string, ':' ) ) {
            return $search_string;
        }

        $fields_values = array();

        $where_sql = '';

        $logs_ids_to_search  = array();
        $logs_ids_to_exclude = array();

        // Remove trailing and leading spaces.
        $string = trim( $search_string );

        $raw_fields = \explode( ' ', $string );

        foreach ( $raw_fields as $field ) {
            if ( empty( $field ) || ! \strpos( $field, ':' ) ) {
                continue;
            }
            list($field_key, $field_value) = \explode( ':', trim( $field ) );

            $exc_flag = false;

            if ( false !== \strpos( $field_key, '-' ) ) {
                $exc_flag  = true;
                $field_key = ltrim( $field_key, $field_key[0] );
            }

            if ( '' !== trim( $field_value ) ) {
                if ( isset( $fields_values[ $field_key ] ) ) {
                    if ( $exc_flag ) {
                        $fields_values[ $field_key ]['exc'][] = $field_value;
                    } else {
                        $fields_values[ $field_key ][] = $field_value;
                    }
                } else {
                    $fields_values[ $field_key ] = array();
                    if ( $exc_flag ) {
                        $fields_values[ $field_key ]['exc'][] = $field_value;
                    } else {
                        $fields_values[ $field_key ][] = $field_value;
                    }
                }
            } else {
                continue;
            }
        }

        $get_sub_fields = self::prepare_all_fields();

        foreach ( $fields_values as $filed_name => $filed_values ) {
            if ( isset( $get_sub_fields[ $filed_name ] ) && isset( $get_sub_fields[ $filed_name ]['sql_prefix'] ) ) {
                self::meta_search( $get_sub_fields[ $filed_name ], $filed_values, $logs_ids_to_search, $logs_ids_to_exclude );
            }

            if ( isset( $get_sub_fields[ $filed_name ] ) && isset( $get_sub_fields[ $filed_name ]['call'] ) ) {
                $where_sql .= \call_user_func( $get_sub_fields[ $filed_name ]['call'], $get_sub_fields[ $filed_name ], $filed_values ) . ' AND ';

            }

            if ( isset( $get_sub_fields[ $filed_name ] ) && ( ! isset( $get_sub_fields[ $filed_name ]['call'] ) && ! isset( $get_sub_fields[ $filed_name ]['sql_prefix'] ) ) ) {
                $where_sql .= self::direct_field_call( (string) $filed_name, $filed_values ) . ' AND ';
            }
        }

        $where_sql = \rtrim( $where_sql, ' AND ' );

        return $where_sql;
    }

    /**
     * Special class method for searching for users based on different criteria - user first name, user roles, etc...
     *
     * @param array $search_keys - Keys (from field mapping logic @see prepare_all_fields method) giving the logic of how to search for user based on specific field.
     * @param array $search_values - Values collected from the search string to search for.
     *
     * @return string
     *
     * @since 5.0.0
     */
    public static function users_search( array $search_keys, array $search_values ) {
        $exclude = false;

        if ( isset( $search_values['exc'] ) ) {
            $exclude            = true;
            $search_user_values = $search_values['exc'];
        } else {
            $search_user_values = $search_values;
        }

        if ( isset( $search_keys['field_name'] ) ) {
            $users = array();

            $exclude = false;

            if ( isset( $search_values['exc'] ) ) {
                $exclude            = true;
                $search_user_values = $search_values['exc'];
            } else {
                $search_user_values = $search_values;
            }

            $args = array(
                'blog_id'    => 0,
                'meta_query' => array(
                    array(
                        'key'     => $search_keys['field_name'],
                        'values'  => $search_user_values,
                        'compare' => 'REGEXP',
                    ),
                ),
            );

            if ( 'ID' === $search_keys['field_name'] ) {
                unset( $args['meta_query'] );
                $args['include'] = $search_user_values;
            }

            if ( 'user_email' === $search_keys['field_name'] ) {
                global $wpdb;
                $search_str  = '';
                $search_vals = array();
                foreach ( $search_user_values as $value ) {
                    $search_str   .= ' ' . $search_keys['field_name'] . ' LIKE %s OR ';
                    $search_vals[] = $value;
                }
                $search_str  = \rtrim( $search_str, ' OR ' );
                $users_array = $wpdb->get_results(
                    $wpdb->prepare( 'SELECT * FROM ' . $wpdb->users . ' WHERE ' . $search_str, $search_vals ) // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
                ); // This will return list of users with matching email domain.
            } else {
                $users_array = \get_users( $args );
            }

            foreach ( $users_array as $user ) {
                if ( isset( $search_keys['in_table'] ) ) {
                    $arr = array();
                    foreach ( $search_keys['in_table'] as $field_name => $object_name ) {
                        $arr[ $field_name ] = "'" . $user->$object_name . "'";
                    }

                    $users[] = $arr;
                } else {
                    $users[] = array( 'user_id' => $user->ID );
                }
            }

            $from_string_array = array();

            if ( ! empty( $users ) ) {
                foreach ( $users as $user ) {
                    foreach ( $user as $field_name => $field_value ) {
                        if ( ! empty( $field_value ) ) {
                            $from_string_array[ $field_name ][] = $field_value;
                        }
                    }
                }
            }
            $users_sql = '';
            foreach ( $from_string_array as $field_name => $field_values ) {
                $users_sql .= ( ( true === $exclude ) ? ' ( ' . $field_name . ' IS NULL OR ' : '' ) . $field_name . ( ( true === $exclude ) ? ' NOT' : '' ) . ' IN ( ' . \implode( ',', $field_values ) . ( ( true === $exclude ) ? ' ) ) AND ' : ' ) OR ' );
            }

            if ( true === $exclude ) {
                $users_sql = ' ( ' . \rtrim( $users_sql, ' AND ' ) . ' ) ';
            } else {
                $users_sql = ' ( ' . \rtrim( $users_sql, ' OR ' ) . ' ) ';
            }

            if ( true === $exclude ) {
                unset( $search_values['exc'] );
                if ( ! empty( $search_values ) ) {
                    $users_sql .= ' AND ' . self::users_search( $search_keys, $search_values );
                }
            }

            return $users_sql;
        } elseif ( isset( $search_keys['extract'] ) ) {
            if ( 'role' === $search_keys['extract'] ) {

                $users_sql = '';
                foreach ( $search_user_values as $field_value ) {
                    $users_sql .= ( ( true === $exclude ) ? '!' : '' ) . " FIND_IN_SET('" . $field_value . "',`user_roles`) " . ( ( true === $exclude ) ? ' AND ' : ' OR ' );
                }

                if ( true === $exclude ) {
                    $users_sql = ' ( ' . \rtrim( $users_sql, ' AND ' ) . ' ) ';
                } else {
                    $users_sql = ' ( ' . \rtrim( $users_sql, ' OR ' ) . ' ) ';
                }

                if ( true === $exclude ) {
                    unset( $search_values['exc'] );
                    if ( ! empty( $search_values ) ) {
                        $users_sql .= ' AND ' . self::users_search( $search_keys, $search_values );
                    }
                }

                return $users_sql;
            }
        }
    }

    /**
     * Special class method used for searching in the meta table for the given filed/value based on the mapping provided in the @see prepare_all_fields
     *
     * @param array $search_keys - Keys (from field mapping logic @see prepare_all_fields method) giving the logic of how to search for user based on specific field.
     * @param array $search_values - Values collected from the search string to search for.
     * @param array $logs_ids_to_search - Array with the collected IDs to search for so far.
     * @param array $logs_ids_to_exclude - Array with the collected IDs to exclude from search so far.
     *
     * @return void
     *
     * @since 5.0.0
     */
    public static function meta_search( array $search_keys, array $search_values, array &$logs_ids_to_search, array &$logs_ids_to_exclude ) {
        $sub_sql = $search_keys['sql_prefix'];

        $exclude = false;

        if ( isset( $search_values['exc'] ) ) {
            $exclude            = true;
            $search_user_values = $search_values['exc'];
        } else {
            $search_user_values = $search_values;
        }

        foreach ( $search_user_values as $value ) {
            $sub_sql .= $search_keys['sub_sql_string'] . ' OR ';
        }

        $sub_sql = \rtrim( $sub_sql, ' OR ' );

        $sub_sql .= $search_keys['sql_suffix'];

        $_wpdb = Changes_Metadata_Entity::get_connection();

        $sql     = $_wpdb->prepare( $sub_sql, $search_user_values );
        $results = $_wpdb->get_results( $sql, ARRAY_A );

        if ( ! empty( $results ) ) {
            if ( true === $exclude ) {
                $logs_ids_to_exclude = \array_merge( $logs_ids_to_exclude, array_column( $results, $search_keys['column_name'] ) );
            } else {
                $logs_ids_to_search = \array_merge( $logs_ids_to_search, array_column( $results, $search_keys['column_name'] ) );
            }
        }

        if ( true === $exclude ) {
            unset( $search_values['exc'] );
            if ( ! empty( $search_values ) ) {
                self::meta_search( $search_keys, $search_values, $logs_ids_to_search, $logs_ids_to_exclude );
            }
        }
    }

    /**
     * Falls here if the filed / value pair to search for is directly present in the occurrences table - meaning that there is no need to map, or extract additional data.
     *
     * @param string $field_name - The name of the field to search for.
     * @param array  $search_values - The search values provided.
     *
     * @return string
     *
     * @since 5.0.0
     */
    public static function direct_field_call( string $field_name, array $search_values ) {
        $exclude = false;

        $field_sql = '';

        if ( \in_array( $field_name, array( 'start_date', 'end_date' ), true ) ) {
            if ( 'start_date' === $field_name ) {
                $start_datetime   = \DateTime::createFromFormat( 'Y-m-d H:i:s', $search_values[0] . ' 00:00:00' );
                $_start_timestamp = $start_datetime->format( 'U' ) + ( Changes_DateTime_Formatter_Helper::get_time_zone_offset() ) * -1;
                $field_sql       .= " created_on >= {$_start_timestamp} ";
            } else {
                $end_datetime   = \DateTime::createFromFormat( 'Y-m-d H:i:s', datetime: $search_values[0] . ' 23:59:59' );
                $_end_timestamp = $end_datetime->format( 'U' ) + ( Changes_DateTime_Formatter_Helper::get_time_zone_offset() ) * -1;
                $field_sql     .= " created_on <= {$_end_timestamp} ";
            }
        } else {

            if ( isset( $search_values['exc'] ) ) {
                $exclude             = true;
                $search_field_values = $search_values['exc'];
            } else {
                $search_field_values = $search_values;
            }
            foreach ( $search_field_values as $field_values ) {
                $field_sql .= ( ( true === $exclude ) ? ' ( ' . $field_name . ' IS NULL OR ' : '' ) . $field_name . ( ( true === $exclude ) ? ' NOT' : '' ) . ' IN ( ' . "'" . \implode( ",'", (array) $field_values ) . "'" . ( ( true === $exclude ) ? ' ) ) AND ' : ' ) OR ' );
            }

            if ( true === $exclude ) {
                $field_sql = ' ( ' . \rtrim( $field_sql, ' AND ' ) . ' ) ';
            } else {
                $field_sql = ' ( ' . \rtrim( $field_sql, ' OR ' ) . ' ) ';
            }

            if ( true === $exclude ) {
                unset( $search_values['exc'] );
                if ( ! empty( $search_values ) ) {
                    $field_sql .= ' AND ' . self::direct_field_call( $field_name, $search_values );
                }
            }
        }

        return $field_sql;
    }
}
