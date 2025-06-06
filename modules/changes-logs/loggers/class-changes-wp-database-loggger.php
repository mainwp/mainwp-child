<?php
/**
 * Logger: Database
 *
 * Database sensor class file.
 *
 * @since     5.4.1
 * @package   mainwp/child
 */

declare(strict_types=1);

namespace MainWP\Child\Changes\Loggers;

use MainWP\Child\Changes\Helpers\Changes_WP_Helper;
use MainWP\Child\Changes\Helpers\Changes_Settings_Helper;
use MainWP\Child\Changes\Changes_Logs_Manager;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Database sensor.
 *
 * 5010 Plugin created table
 * 5011 Plugin modified table structure
 * 5012 Plugin deleted table
 * 5013 Theme created tables
 * 5014 Theme modified tables structure
 * 5015 Theme deleted tables
 * 5016 Unknown component created tables
 * 5017 Unknown component modified tables structure
 * 5018 Unknown component deleted tables
 * 5022 WordPress created tables
 * 5023 WordPress modified tables structure
 * 5024 WordPress deleted tables
 */
class Changes_WP_Database_Logger {

    /**
     * Local cache for basename of current script. It is used to improve performance
     * of determining the actor of current action.
     *
     * @var string|bool
     */
    private static $script_basename = null;

    /**
     * Class cache for sensor enabled state.
     *
     * @var bool
     *
     * @since 5.3.0
     */
    private static $sensor_enabled = null;

    /**
     * If true, database events are being logged. This is used by the plugin's update process to temporarily disable
     * the database sensor to prevent errors (events are registered after the upgrade process is run).
     *
     * @var bool
     *
     * @since 4.5.0
     */
    private static $enabled = true;

    /**
     * List of already logged operation during current request. It is used to prevent duplicate events. Values in the
     * array are strings in form of "{operation type}_{table name}".
     *
     * @var string[]
     *
     * @since 4.5.0
     */
    private static $already_logged = array();

    /**
     * Inits the main hooks
     *
     * @return void
     *
     * @since 4.5.0
     */
    public static function init() {
        if ( self::is_sensor_enabled() ) {
            \add_action( 'dbdelta_queries', array( __CLASS__, 'event_db_delta_query' ) );
            \add_filter( 'query', array( __CLASS__, 'event_drop_query' ) );
        }
    }

    /**
     * Walks through the disabled alerts (for the given installation or current user settings) and checks always disabled events against them.
     * If always disabled alerts (which are DB related alert), are currently disabled in the user configuration (all of them), then there is no need to init this sensor - the result from that will be false.
     * If any of the events is enabled result will be true - that Sensor should be fired.
     *
     * @return boolean
     *
     * @since 4.5.0
     */
    private static function is_sensor_enabled(): bool {
        if ( null === self::$sensor_enabled ) {
                    $sensor_alerts           = array(
                        5010,
                        5011,
                        5012,
                        5013,
                        5014,
                        5015,
                        5016,
                        5017,
                        5018,
                        5022,
                        5023,
                        5024,
                    );
                    $current_disabled_alerts = Changes_Settings_Helper::get_disabled_alerts();
                    $res                     = empty( array_diff( $sensor_alerts, $current_disabled_alerts ) );

                    self::$sensor_enabled = ! $res;
        }

        return self::$sensor_enabled;
    }

    /**
     * Sets the sensor as disabled.
     *
     * @return void
     *
     * @since 4.6.0
     */
    public static function set_disabled() {
        self::$enabled = false;
    }

    /**
     * Checks for drop query.
     *
     * @param string $query - Database query string.
     *
     * @return string
     */
    public static function event_drop_query( $query ) {
        if ( ! self::$enabled ) {
            return $query;
        }

        $query_begins = \substr( trim( $query ), 0, 8 );

        if ( false === stripos( $query_begins, 'drop ' ) && false === stripos( $query_begins, 'create ' ) && false === stripos( $query_begins, 'alter ' ) ) {
            return $query;
        }

        $table_names = array();
        $str         = explode( ' ', $query );
        $query_type  = '';
        if ( preg_match( '|DROP TABLE( IF EXISTS)? ([^ ]*)|', $query ) ) {
            $table_name = empty( $str[4] ) ? $str[2] : $str[4];
            // Only log when the table exists as some plugins try to delete tables even if they don't exist.
            if ( self::is_table_operation_check_enabled( $table_name, 'delete' )
            && self::check_if_table_exists( $table_name ) ) {
                array_push( $table_names, $table_name );
                $query_type = 'delete';
            }
        } elseif ( preg_match( '/CREATE TABLE( IF NOT EXISTS)? ([^ ]*)/i', $query, $matches ) || preg_match( '/CREATE TABLE ([^ ]*)/i', $query, $matches ) || preg_match( '/CREATE TEMPORARY TABLE ([^ ]*)/i', $query, $matches ) ) {
            $table_name = $matches[ count( $matches ) - 1 ];
            if ( self::is_table_operation_check_enabled( $table_name, 'create' )
            && ! self::check_if_table_exists( $table_name ) ) {
                /**
                 * Some plugins keep trying to create tables even
                 * when they already exist - would result in too
                 * many alerts.
                 */
                array_push( $table_names, $table_name );
                $query_type = 'create';
            }
        } elseif ( preg_match( '/ALTER TABLE ([^ ]*)/i', $query, $matches ) ) {
            $table_name = $matches[ count( $matches ) - 1 ];
            if ( self::is_table_operation_check_enabled( $table_name, 'update' )
            && self::check_if_table_exists( $table_name ) ) {
                /**
                 * Some plugins keep trying to create tables even
                 * when they already exist - would result in too
                 * many alerts.
                 */
                array_push( $table_names, $table_name );
                $query_type = 'update';
            }
        }

        self::maybe_trigger_event( $query_type, $table_names );

        return $query;
    }

    /**
     * Triggers an event if the list of tables is not empty. It also checks if
     * the event should be logged for events originated by WordPress.
     *
     * @param string   $query_type  Query type.
     * @param string[] $table_names Table names.
     */
    private static function maybe_trigger_event( $query_type, $table_names ) {
        if ( ! empty( $table_names ) ) {
            $actor = self::get_actor( $table_names );
            if ( 'WordPress' === $actor && ! Changes_Settings_Helper::get_boolean_option_value( 'wp-backend' ) ) {
                // Event is not fired if the monitoring of background events is disabled.
                return;
            }

            // Loop through each item to report event per table.
            foreach ( $table_names as $table_name ) {
                $alert_options = self::get_event_options( $actor );
                $event_code    = self::get_event_code( $actor, $query_type );
                $db_op_key     = $query_type . '_' . $table_name;
                if ( in_array( $db_op_key, self::$already_logged, true ) ) {
                    continue;
                }

                $alert_options['TableNames'] = $table_name;
                Changes_Logs_Manager::trigger_event( $event_code, $alert_options );
                array_push( self::$already_logged, $db_op_key );
            }
        }
    }

    /**
     * Determine the actor of database change.
     *
     * @param string[] $table_names Names of the tables that are being changed.
     *
     * @return bool|string Theme, plugin or false if unknown.
     */
    private static function get_actor( $table_names ) {
        // Default actor (treated as an unknown component).
        $result = false;

        // Use current script name to determine if the actor is theme or a plugin.
        if ( is_null( self::$script_basename ) ) {
            self::$script_basename = isset( $_SERVER['SCRIPT_NAME'] ) ? basename( sanitize_text_field( wp_unslash( $_SERVER['SCRIPT_NAME'] ) ), '.php' ) : false;
        }

        $result = self::$script_basename;

        // Check table names for default WordPress table names (including network tables).
        if ( self::contains_wordpress_table( $table_names ) ) {
            $result = 'WordPress';
        }

        return $result;
    }

    /**
     * Checks if the list of tables contains a WordPress table.
     *
     * @param array $tables List of table names.
     *
     * @return bool True if the list contains a WordPress table.
     */
    private static function contains_wordpress_table( $tables ) {
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
                // 'wp_term_relationships' .
                // 'wp_1_term_relationships' .
                // 'wp_' .

                global $wpdb;

                $current_db_prefix = $wpdb->base_prefix;

                $table = trim( $table, '`' );
                $table = trim( $table, "'" );

                if ( 0 === \mb_strpos( $table, $current_db_prefix ) ) {

                    $table = substr_replace( $table, '', 0, strlen( $current_db_prefix ) );

                    if ( Changes_WP_Helper::is_multisite() ) {

                        $table_name_chunks = \mb_split( '_', $table );
                        $possible_index    = reset( $table_name_chunks );

                        if ( false !== filter_var( $possible_index, FILTER_VALIDATE_INT ) ) {
                            $table = substr_replace( $table, '', 0, strlen( $possible_index . '_' ) );
                        }
                    }
                    if ( \in_array( $table, $wp_tables_array, true ) ) {
                        // Stop as soon as the first WordPress table is found.
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
     * @param string $actor - Plugins, themes, WordPress or unknown.
     *
     * @return array
     *
     * phpcs:disable WordPress.Security.NonceVerification.Recommended
     * phpcs:disable WordPress.Security.ValidatedSanitizedInput.InputNotValidated
     */
    private static function get_event_options( $actor ) {
        // Check the actor.
        $alert_options = array();
        switch ( $actor ) {
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
                    if ( empty( $plugin_file ) ) {
                        if ( isset( $GLOBALS['plugin'] ) && '' !== trim( wp_unslash( $GLOBALS['plugin'] ) ) ) {
                            $plugin_file = sanitize_text_field( wp_unslash( $GLOBALS['plugin'] ) );
                        }
                    }
                }
                // Get plugin data.
                $plugins = get_plugins();
                if ( isset( $plugins[ $plugin_file ] ) ) {
                    $plugin = $plugins[ $plugin_file ];

                    // Set alert options.
                    $alert_options['Plugin'] = (object) array(
                        'Name'      => $plugin['Name'],
                        'PluginURI' => $plugin['PluginURI'],
                        'Version'   => $plugin['Version'],
                    );
                } else {
                    $plugin_name = basename( $plugin_file, '.php' );
                    $plugin_name = str_replace( array( '_', '-', '  ' ), ' ', $plugin_name );
                    $plugin_name = ucwords( $plugin_name );

                    // If this is still empty at this point, lets check recent events.
                    if ( empty( $plugin_file ) ) {
                        $plugin_name = self::determine_recently_activated_plugin();
                    }

                    $alert_options['Plugin'] = (object) array( 'Name' => $plugin_name );
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

                $theme_name             = str_replace( array( '_', '-', '  ' ), ' ', $theme_name );
                $theme_name             = ucwords( $theme_name );
                $alert_options['Theme'] = (object) array( 'Name' => $theme_name );
                break;

            case 'WordPress':
                $alert_options['Component'] = 'WordPress';
                break;

            default:
                // Action Unknown Component.
                $alert_options['Component'] = 'Unknown';

        }

        return $alert_options;
    }

    /**
     * Get alert code by actor and query type.
     *
     * @param string $actor - Plugins, themes, WordPress or unknown.
     * @param string $query_type - Create, update or delete.
     *
     * @return int Event code.
     */
    protected static function get_event_code( $actor, $query_type ) {
        switch ( $actor ) {
            case 'plugins':
                if ( 'create' === $query_type ) {
                    return 5010;
                } elseif ( 'update' === $query_type ) {
                    return 5011;
                } elseif ( 'delete' === $query_type ) {
                    return 5012;
                }
                break;

            case 'themes':
                if ( 'create' === $query_type ) {
                    return 5013;
                } elseif ( 'update' === $query_type ) {
                    return 5014;
                } elseif ( 'delete' === $query_type ) {
                    return 5015;
                }
                break;

            case 'WordPress':
                if ( 'create' === $query_type ) {
                    return 5022;
                } elseif ( 'update' === $query_type ) {
                    return 5023;
                } elseif ( 'delete' === $query_type ) {
                    return 5024;
                }
                break;
            default:
                if ( 'create' === $query_type ) {
                    return 5016;
                } elseif ( 'update' === $query_type ) {
                    return 5017;
                } elseif ( 'delete' === $query_type ) {
                    return 5018;
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
    public static function event_db_delta_query( $queries ) {
        if ( ! self::$enabled ) {
            return $queries;
        }

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
                if ( self::is_table_operation_check_enabled( $table_name, 'create' )
                && ! self::check_if_table_exists( $table_name ) ) {
                    /**
                     * Some plugins keep trying to create tables even
                     * when they already exist- would result in too
                     * many alerts.
                     */
                    array_push( $query_types['create'], $table_name );
                }
            } elseif ( preg_match( '|ALTER TABLE ([^ ]*)|', $qry ) ) {
                array_push( $query_types['update'], $str[2] );
            } elseif ( preg_match( '|DROP TABLE( IF EXISTS)? ([^ ]*)|', $qry ) ) {
                $table_name = empty( $str[4] ) ? $str[2] : $str[4];
                // Only log when the table exists as some plugins try to delete tables even if they don't exist.
                if ( self::is_table_operation_check_enabled( $table_name, 'delete' )
                && self::check_if_table_exists( $table_name ) ) {
                    array_push( $query_types['delete'], $table_name );
                }
            }
        }

        if ( ! empty( $query_types['create'] ) || ! empty( $query_types['update'] ) || ! empty( $query_types['delete'] ) ) {
            foreach ( $query_types as $query_type => $table_names ) {
                self::maybe_trigger_event( $query_type, $table_names );
            }
        }

        return $queries;
    }

    /**
     * Sets the sensor as enabled / disabled
     *
     * @param boolean $enabled - Boolean value.
     *
     * @return void
     *
     * @since 4.5.0
     */
    public static function set_enabled( bool $enabled ) {
        self::$enabled = (bool) $enabled;
    }

    /**
     * Last resort to determine the name of a plugin performing the action.
     *
     * @return string Name, taken from recent event.
     */
    private static function determine_recently_activated_plugin() {
        $alert_id = 5001;

        $latest_events = Changes_Logs_Manager::get_latest_events( 25, true );

        $plugin_name = false;

        if ( \is_array( $latest_events ) ) {
            foreach ( $latest_events as $latest_event ) {
                if ( intval( $latest_event['alert_id'] ) === $alert_id ) {
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
     * SHOW TABLES. The previous solution has proven to be memory intense in shared hosting environments.
     *
     * @param string $table_name Table name.
     *
     * @return bool True if the table exists. False otherwise.
     *
     * @since 4.5.0
     */
    private static function check_if_table_exists( $table_name ) {
        try {
            global $wpdb;

            $wpdb->suppress_errors( true );

            // Output buffering is here to prevent from error log messages that would be fired if the table didn't exist.
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
     * Checks if alerts for certain query type are enabled or not.
     *
     * This is used to prevent unnecessary table existence checks. These checks should not take place
     * if a specific alert is not enabled. Unfortunately if the alert is enabled or not is being checked
     * too late.
     *
     * @param string $table_name Table name.
     * @param string $query_type Query type.
     *
     * @return bool
     *
     * @since 4.5.0
     */
    private static function is_table_operation_check_enabled( $table_name, $query_type ) {
        $actor      = self::get_actor( array( $table_name ) );
        $event_code = self::get_event_code( $actor, $query_type );

        return Changes_Logs_Manager::is_enabled( $event_code );
    }
}
