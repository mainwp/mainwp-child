<?php
/**
 * Logger: Database
 *
 * Database class file.
 *
 * @since     5.5
 * @package   mainwp/child
 */

namespace MainWP\Child\Changes;

// Exit.
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Database.
 */
class Changes_Handle_WP_Database {

    /**
     * Local cache for basename of current script.
     *
     * @var string|bool
     */
    private static $script_basename = null;

    /**
     * Class cache for enabled state.
     *
     * @var bool
     */
    private static $handle_logs_enabled = null;

    /**
     * List of already logged operation during current request.
     *
     * @var string[]
     */
    private static $already_logged = array();

    /**
     * Inits the main hooks
     *
     * @return void
     */
    public static function init_hooks() {
        if ( self::is_enabled_logs() ) {
            \add_action( 'dbdelta_queries', array( __CLASS__, 'callback_change_db_delta_query' ) );
            \add_filter( 'query', array( __CLASS__, 'callback_change_drop_query' ) );
        }
    }

    /**
     * If any of the events is enabled result will be true
     *
     * @return boolean
     */
    private static function is_enabled_logs() {
        if ( null === self::$handle_logs_enabled ) {
            $logs_types                = array(
                1740,
                1745,
                1750,
                1755,
                1760,
                1765,
                1770,
                1775,
                1780,
            );
            $current_disabled_logs     = Changes_Helper::get_disabled_logs_types();
            $res                       = empty( array_diff( $logs_types, $current_disabled_logs ) );
            self::$handle_logs_enabled = ! $res;
        }
        return self::$handle_logs_enabled;
    }

    /**
     * Checks for drop query.
     *
     * @param string $query - Database query string.
     *
     * @return string
     */
    public static function callback_change_drop_query( $query ) {

        $query_begins = \substr( trim( $query ), 0, 8 );

        if ( false === stripos( $query_begins, 'drop ' ) && false === stripos( $query_begins, 'create ' ) && false === stripos( $query_begins, 'alter ' ) ) {
            return $query;
        }

        $table_names = array();
        $str         = explode( ' ', $query );
        $query_type  = '';
        if ( preg_match( '|DROP TABLE( IF EXISTS)? ([^ ]*)|', $query ) ) {
            $table_name = empty( $str[4] ) ? $str[2] : $str[4];
            if ( self::if_table_change_log_enabled( $table_name, 'delete' )
            && self::change_if_table_found( $table_name ) ) {
                array_push( $table_names, $table_name );
                $query_type = 'delete';
            }
        } elseif ( preg_match( '/CREATE TABLE( IF NOT EXISTS)? ([^ ]*)/i', $query, $matches ) || preg_match( '/CREATE TABLE ([^ ]*)/i', $query, $matches ) || preg_match( '/CREATE TEMPORARY TABLE ([^ ]*)/i', $query, $matches ) ) {
            $table_name = $matches[ count( $matches ) - 1 ];
            if ( self::if_table_change_log_enabled( $table_name, 'create' )
            && ! self::change_if_table_found( $table_name ) ) {
                array_push( $table_names, $table_name );
                $query_type = 'create';
            }
        } elseif ( preg_match( '/ALTER TABLE ([^ ]*)/i', $query, $matches ) ) {
            $table_name = $matches[ count( $matches ) - 1 ];
            if ( self::if_table_change_log_enabled( $table_name, 'update' )
            && self::change_if_table_found( $table_name ) ) {
                array_push( $table_names, $table_name );
                $query_type = 'update';
            }
        }

        self::change_check_db_log( $query_type, $table_names );

        return $query;
    }

    /**
     * If the list of tables is not empty.
     *
     * @param string   $query_type  Query type.
     * @param string[] $table_names Table names.
     */
    private static function change_check_db_log( $query_type, $table_names ) {
        if ( ! empty( $table_names ) ) {
            $runner = self::change_get_client_runner( $table_names );
            foreach ( $table_names as $table_name ) {
                $log_data  = self::change_get_log_data( $runner );
                $log_code  = self::change_get_log_code_id_by_runner( $runner, $query_type );
                $db_op_key = $query_type . '_' . $table_name;
                if ( in_array( $db_op_key, self::$already_logged, true ) ) {
                    continue;
                }

                $log_data['tablenames'] = $table_name;
                Changes_Logs_Logger::log_change( $log_code, $log_data );
                array_push( self::$already_logged, $db_op_key );
            }
        }
    }

    /**
     * Determine the actor of database change.
     *
     * @param string[] $table_names Names of the tables that are being changed.
     *
     * @return bool|string Theme, plugin or false.
     */
    private static function change_get_client_runner( $table_names ) {
        $result = false;

        if ( is_null( self::$script_basename ) ) {
            self::$script_basename = isset( $_SERVER['SCRIPT_NAME'] ) ? basename( sanitize_text_field( wp_unslash( $_SERVER['SCRIPT_NAME'] ) ), '.php' ) : false;
        }

        $result = self::$script_basename;

        if ( self::change_contains_wp_table( $table_names ) ) {
            $result = 'WordPress';
        }

        return $result;
    }

    /**
     * Checks if the list of tables contains a WP table.
     *
     * @param array $tables List of table names.
     *
     * @return bool True if the list contains a WP table.
     */
    private static function change_contains_wp_table( $tables ) {
        if ( ! empty( $tables ) ) {

            $wp_tables_array = array(
                'commentmeta',
                'comments',
                'links',
                'options',
                'postmeta',
                'posts',
                'terms',
                'termmeta',
                'term_relationships',
                'term_taxonomy',
                'usermeta',
                'users',
                'blogs',
                'blog_versions',
                'registration_log',
                'signups',
                'site',
                'sitemeta',
                'usermeta',
            );

            foreach ( $tables as $table ) {
                global $wpdb;

                $current_db_prefix = $wpdb->base_prefix;

                $table = trim( $table, '`' );
                $table = trim( $table, "'" );

                if ( 0 === \mb_strpos( $table, $current_db_prefix ) ) {

                    $table = substr_replace( $table, '', 0, strlen( $current_db_prefix ) );

                    if ( Changes_Helper::is_multisite() ) {

                        $table_name_chunks = \mb_split( '_', $table );
                        $possible_index    = reset( $table_name_chunks );

                        if ( false !== filter_var( $possible_index, FILTER_VALIDATE_INT ) ) {
                            $table = substr_replace( $table, '', 0, strlen( $possible_index . '_' ) );
                        }
                    }
                    if ( \in_array( $table, $wp_tables_array, true ) ) {
                        return true;
                    }
                } else {
                    return false;
                }
            }
        }

        return false;
    }

    /**
     * Get event options by actor.
     *
     * @param string $runner - Plugins, themes, WordPress or unknown.
     *
     * @return array
     *
     * phpcs:disable WordPress.Security.NonceVerification.Recommended
     * phpcs:disable WordPress.Security.ValidatedSanitizedInput.InputNotValidated
     */
    private static function change_get_log_data( $runner ) {
        // Check the actor.
        $log_data = array();
        switch ( $runner ) {
            case 'plugins':
                // Action Plugin Component.
                $plugin_file = '';

                if ( isset( $_GET['plugin'] ) ) {
                    $plugin_file = sanitize_text_field( wp_unslash( $_GET['plugin'] ) );
                } elseif ( isset( $_GET['checked'] ) && isset( $_GET['checked'][0] ) ) {
                    $plugin_file = sanitize_text_field( wp_unslash( $_GET['checked'][0] ) );
                } else {

                    global $wp_current_filter;
                    if ( isset( $wp_current_filter ) && ! empty( $wp_current_filter ) ) {
                        foreach ( $wp_current_filter as $key => $value ) {
                            if ( 0 === strpos( $value, 'activate_' ) && 'activate_plugin' !== $value ) {

                                $pos = strpos( $value, 'activate_' );
                                if ( false !== $pos ) {
                                    $plugin_file = substr_replace( $value, '', $pos, strlen( 'activate_' ) );
                                }

                                break;
                            }
                        }
                    }
                    if ( empty( $plugin_file ) && isset( $GLOBALS['plugin'] ) && '' !== trim( wp_unslash( $GLOBALS['plugin'] ) ) ) {
                        $plugin_file = sanitize_text_field( wp_unslash( $GLOBALS['plugin'] ) );
                    }
                }
                $plugins = get_plugins();
                if ( isset( $plugins[ $plugin_file ] ) ) {
                    $plugin = $plugins[ $plugin_file ];

                    $log_data['plugin'] = (object) array(
                        'name'      => $plugin['Name'],
                        'pluginuri' => $plugin['PluginURI'],
                        'version'   => $plugin['version'],
                    );
                } else {
                    $plugin_name = basename( $plugin_file, '.php' );
                    $plugin_name = str_replace( array( '_', '-', '  ' ), ' ', $plugin_name );
                    $plugin_name = ucwords( $plugin_name );

                    if ( empty( $plugin_file ) ) {
                        $plugin_name = self::change_check_recent_activated_plugin();
                    }

                    $log_data['plugin'] = (object) array( 'name' => $plugin_name );
                }
                break;
            case 'themes':
                // Action Theme Component.
                $theme_name = '';
                if ( isset( $_GET['theme'] ) ) {
                    $theme_name = sanitize_text_field( wp_unslash( $_GET['theme'] ) );
                } elseif ( isset( $_GET['checked'] ) ) {
                    $theme_name = sanitize_text_field( wp_unslash( $_GET['checked'][0] ) );
                }

                $theme_name        = str_replace( array( '_', '-', '  ' ), ' ', $theme_name );
                $theme_name        = ucwords( $theme_name );
                $log_data['theme'] = (object) array( 'name' => $theme_name );
                break;

            case 'WordPress':
                $log_data['component'] = 'WordPress';
                break;

            default:
                // Action Unknown Component.
                $log_data['component'] = 'Unknown';

        }

        return $log_data;
    }

    /**
     * Get alert code by actor and query type.
     *
     * @param string $runner - Plugins, themes, WordPress or unknown.
     * @param string $query_type - Create, update or delete.
     *
     * @return int Event code.
     */
    protected static function change_get_log_code_id_by_runner( $runner, $query_type ) {
        switch ( $runner ) {
            case 'plugins':
                if ( 'create' === $query_type ) {
                    return 1740;
                } elseif ( 'update' === $query_type ) {
                    return 1745;
                } elseif ( 'delete' === $query_type ) {
                    return 1750;
                }
                break;

            case 'themes':
                if ( 'create' === $query_type ) {
                    return 1755;
                } elseif ( 'update' === $query_type ) {
                    return 1760;
                } elseif ( 'delete' === $query_type ) {
                    return 1765;
                }
                break;

            case 'WordPress':
                break;
            default:
                if ( 'create' === $query_type ) {
                    return 1770;
                } elseif ( 'update' === $query_type ) {
                    return 1775;
                } elseif ( 'delete' === $query_type ) {
                    return 1780;
                }
                break;
        }
    }

    /**
     * Checks DB Delta queries.
     *
     * @param array $queries - Array of queries.
     *
     * @return array
     */
    public static function callback_change_db_delta_query( $queries ) {

        $query_types = array(
            'create' => array(),
            'update' => array(),
            'delete' => array(),
        );

        foreach ( $queries as $qry ) {
            $qry = str_replace( '`', '', $qry );
            $str = explode( ' ', $qry );
            if ( preg_match( '/CREATE TABLE( IF NOT EXISTS)? ([^ ]*)/i', $qry, $matches ) ) {
                $table_name = $matches[ count( $matches ) - 1 ];
                if ( self::if_table_change_log_enabled( $table_name, 'create' )
                && ! self::change_if_table_found( $table_name ) ) {
                    array_push( $query_types['create'], $table_name );
                }
            } elseif ( preg_match( '|ALTER TABLE ([^ ]*)|', $qry ) ) {
                array_push( $query_types['update'], $str[2] );
            } elseif ( preg_match( '|DROP TABLE( IF EXISTS)? ([^ ]*)|', $qry ) ) {
                $table_name = empty( $str[4] ) ? $str[2] : $str[4];
                if ( self::if_table_change_log_enabled( $table_name, 'delete' )
                && self::change_if_table_found( $table_name ) ) {
                    array_push( $query_types['delete'], $table_name );
                }
            }
        }

        if ( ! empty( $query_types['create'] ) || ! empty( $query_types['update'] ) || ! empty( $query_types['delete'] ) ) {
            foreach ( $query_types as $query_type => $table_names ) {
                self::change_check_db_log( $query_type, $table_names );
            }
        }

        return $queries;
    }

    /**
     * Check Last logs to determine the name of a plugin.
     *
     * @return string Name recent event.
     */
    private static function change_check_recent_activated_plugin() {
        $type_id = 1461;

        $latest_events = Changes_Logs_Logger::get_latest_logs( 25, true );

        $plugin_name = false;

        if ( is_array( $latest_events ) ) {
            foreach ( $latest_events as $latest_event ) {
                if ( intval( $latest_event['log_type_id'] ) === $type_id ) {
                    $event_meta  = $latest_event ? $latest_event['meta_values'] : false;
                    $plugin_name = $event_meta['PluginData']->Name;

                    break;
                }
            }
        }

        if ( $plugin_name ) {
            return $plugin_name;
        }
    }

    /**
     * Checks if a table exists in the WordPress database by running a SELECT query instead of former solution using
     *
     * @param string $table_name Table name.
     *
     * @return bool True if the table exists. False otherwise.
     */
    private static function change_if_table_found( $table_name ) {
        try {
            global $wpdb;

            $wpdb->suppress_errors( true );

            ob_start();
            $db_result = $wpdb->query( "SELECT COUNT(1) FROM {$table_name};" ); // phpcs:ignore
            ob_clean();

            $wpdb->suppress_errors( false );

            return ( 1 === $db_result );
        } catch ( \Exception $e ) {
            $wpdb->suppress_errors( false );
            return false;
        }
    }

    /**
     * Checks if logs for certain query type are enabled or not.
     *
     * @param string $table_name Table name.
     * @param string $query_type Query type.
     *
     * @return bool
     */
    private static function if_table_change_log_enabled( $table_name, $query_type ) {
        $runner = self::change_get_client_runner( array( $table_name ) );
        $code   = self::change_get_log_code_id_by_runner( $runner, $query_type );

        return Changes_Logs_Logger::is_enabled( $code );
    }
}
