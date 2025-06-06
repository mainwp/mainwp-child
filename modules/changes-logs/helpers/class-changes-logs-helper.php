<?php
/**
 * Changes Logs Class
 *
 * @author  WP Activity Log plugin.
 *
 * @since 5.4.1
 *
 * @package MainWP\Child
 */

declare(strict_types=1);


namespace MainWP\Child\Changes\Helpers;

use MainWP\Child\Changes\Changes_Validator;
use MainWP\Child\Changes\Changes_Alert_Formatter;


// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Provides logging functionality for the comments.
 */
class Changes_Logs_Helper {
    /**
     * Holds array with all the deactivated logs.
     * When monitored plugin is deactivated, all logs are removed from the logs array. But here we hold all of these deactivated logs, so we can still show proper message generated from the plugin.
     *
     * @var array
     */
    private static $deactivated_logs = null;

    /**
     * Returns the log array by given log_type_id
     *
     * @param integer $log_type_id - The log_type_id to get the log data for.
     *
     * @return array|bool
     */
    public static function get_log( $log_type_id = 0 ) {
        if ( isset( Changes_Logs_Manager::get_logs()[ $log_type_id ] ) ) {
            return Changes_Logs_Manager::get_logs()[ $log_type_id ];
        }

        // Lets check deactivated as well.
        if ( isset( self::get_deactivated_logs_array()[ $log_type_id ] ) ) {

            return self::get_deactivated_logs_array()[ $log_type_id ];
        }

        return false;
    }

    /**
     * Returns the log message
     *
     * @param integer $log_type_id - The log_type_id to retrieve the message for.
     *
     * @return string
     */
    public static function get_original_log_message( $log_type_id = 0 ): string {
        if ( isset( Changes_Logs_Manager::get_logs()[ $log_type_id ] ) ) {

            return Changes_Logs_Manager::get_logs()[ $log_type_id ]['message'];
        }

        if ( isset( self::get_deactivated_logs_array()[ $log_type_id ] ) ) {

            return self::get_deactivated_logs_array()[ $log_type_id ][3];
        }

        return esc_html__( 'Alert message not found.', 'mainwp-child' );
    }

    /**
     * Collects and returns all of the deactivated logs (coming from deactivated plugins)
     *
     * @return array
     */
    public static function get_deactivated_logs_array(): array {
        if ( null === self::$deactivated_logs ) {
            // Nothing in the registered logs - lets try ones that are activated only if the representative plugin is installed and activated.
            $unchecked_logs = array();
            $custom_logs    = \mainwp_child_changes_logs_get_classes_list( 'Alerts' );
            foreach ( $custom_logs as $logs ) {
                if ( method_exists( $logs, 'get_logs_array' ) ) {
                    $unchecked_logs += call_user_func_array( array( $logs, 'get_logs_array' ), array() );
                }
            }
            self::$deactivated_logs = $unchecked_logs;
        }
        return self::$deactivated_logs;
    }

    /**
     * Formats the log message
     *
     * @param array       $meta_data - Array of meta data.
     * @param string|null $message -  The message to be formatted.
     * @param integer     $log_type_id - The log id to get message for.
     * @param integer     $log_id - The occurrence id to get message for.
     * @param string      $context - In which context the message should be formatted.
     *
     * @return string|bool
     */
    public static function get_message( $meta_data, $message = null, $log_type_id = 0, $log_id = 0, $context = 'default' ) {
        $active_log = isset( Changes_Logs_Manager::get_logs()[ $log_type_id ] );
        if ( $active_log || isset( self::get_deactivated_logs_array()[ $log_type_id ] ) ) {
            if ( $active_log ) {
                $log = Changes_Logs_Manager::get_logs()[ $log_type_id ];
            } else {
                $log               = self::get_deactivated_logs_array()[ $log_type_id ];
                $log['code']       = $log[0];
                $log['desc']       = $log[3];
                $log['message']    = $log[3];
                $log['metadata']   = $log[4];
                $log['object']     = $log[6];
                $log['event_type'] = $log[7];
            }

            $message = is_null( $message ) ? $log['message'] : $message;

            if ( ! $context ) {
                $context = 'default';
            }
            // Get the log formatter for given context.
            $configuration = Formatter_Factory::get_configuration( $context );

            // Tokenize message with regex.
            $message_parts = preg_split( '/(%.*?%)/', (string) $message, - 1, PREG_SPLIT_DELIM_CAPTURE );
            if ( ! is_array( $message_parts ) ) {
                // Use the message as is.
                $result = (string) $message;
            } elseif ( ! empty( $message_parts ) ) {
                // Handle tokenized message.
                foreach ( $message_parts as $i => $token ) {
                    if ( strlen( $token ) === 0 ) {
                        continue;
                    }
                    // Handle escaped percent sign.
                    if ( '%%' === $token ) {
                        $message_parts[ $i ] = '%';
                    } elseif ( substr( $token, 0, 1 ) === '%' && substr( $token, - 1, 1 ) === '%' ) {
                        // Handle complex expressions.
                        $message_parts[ $i ] = self::get_meta_expression_value( substr( $token, 1, - 1 ), $meta_data );
                        $message_parts[ $i ] = Changes_Alert_Formatter::format_meta_expression( $token, $message_parts[ $i ], $configuration, $log_id, $meta_data );
                    }
                }

                // Compact message.
                $result = implode( '', $message_parts );
            }

            // Process message to make sure it any HTML tags are handled correctly.
            $result = Changes_Alert_Formatter::process_html_tags_in_message( $result, $configuration );

            $end_of_line = $configuration['end_of_line'];

            // Process metadata and links introduced as part of log definition in version 4.2.1.
            if ( $configuration['supports_metadata'] ) {
                $metadata_result = self::get_formatted_metadata( $configuration, $meta_data, $log_id, $log );
                if ( ! empty( $metadata_result ) ) {
                    if ( ! empty( $result ) ) {
                        $result .= $end_of_line;
                    }
                    $result .= $metadata_result;
                }
            }

            if ( $configuration['supports_hyperlinks'] ) {
                $hyperlinks_result = self::get_formatted_hyperlinks( $configuration, $meta_data, $log_id, $log );
                if ( ! empty( $hyperlinks_result ) ) {
                    if ( ! empty( $result ) ) {
                        $result .= $end_of_line;
                    }
                    $result .= $hyperlinks_result;
                }
            }

            return $result;
        }

        return false;
    }

    /**
     * Retrieves a value for a particular meta variable expression.
     *
     * @param string $expr Expression, eg: User->Name looks for a Name property for meta named User.
     * @param array  $meta_data (Optional) Meta data relevant to expression.
     *
     * @return mixed The value nearest to the expression.
     */
    protected static function get_meta_expression_value( $expr, $meta_data = array() ) {
        $expr = preg_replace( '/%/', '', $expr );
        if ( 'IPAddress' === $expr ) {
            if ( array_key_exists( 'IPAddress', $meta_data ) ) {
                if ( is_array( $meta_data['IPAddress'] ) ) {
                    return implode( ', ', $meta_data['IPAddress'] );
                } else {
                    return implode( ', ', array( $meta_data['IPAddress'] ) );
                }
            }

            return null;
        }

        // TODO: Handle function calls (and methods?).
        $expr = explode( '->', $expr );
        $meta = array_shift( $expr );
        $meta = isset( $meta_data[ $meta ] ) ? $meta_data[ $meta ] : null;
        foreach ( $expr as $part ) {
            if ( is_scalar( $meta ) || is_null( $meta ) ) {
                return $meta; // This isn't 100% correct.
            }
            $meta = is_array( $meta ) && array_key_exists( $part, $meta ) ? $meta[ $part ] : ( isset( $meta->$part ) ? $meta->$part : 'NULL' );
        }

        return is_scalar( $meta ) ? (string) $meta : var_export( $meta, true ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_var_export
    }

    /**
     * Retrieves formatted meta data item (label and data).
     *
     * @param array $configuration  - Alert message configuration rules.
     * @param array $meta_data Meta data.
     * @param int   $log_id Occurrence ID.
     * @param array $log - The array with all the log details.
     *
     * @return string
     */
    public static function get_formatted_metadata( $configuration, $meta_data, $log_id, $log ) {
        $result            = '';
        $metadata_as_array = self::get_metadata_as_array( $configuration, $meta_data, $log_id, $log );
        if ( ! empty( $metadata_as_array ) ) {

            $meta_result_parts = array();
            foreach ( $metadata_as_array as $meta_label => $meta_expression ) {
                if ( ! empty( $meta_expression ) ) {
                    array_push( $meta_result_parts, $meta_label . ': ' . $meta_expression );
                }
            }

            if ( ! empty( $meta_result_parts ) ) {
                $result .= implode( $configuration['end_of_line'], $meta_result_parts );
            }
        }
        return $result;
    }

    /**
     * Retrieves metadata as an associative array.
     *
     * @param array $configuration  - Alert message configuration rules.
     * @param array $meta_data Meta data.
     * @param int   $log_id Occurrence ID.
     * @param array $log - The array with all the log details.
     *
     * @return array
     */
    public static function get_metadata_as_array( $configuration, $meta_data, $log_id, $log ) {
        $result = array();
        if ( ! empty( $log['metadata'] ) ) {
            foreach ( $log['metadata'] as $meta_label => $meta_token ) {
                if ( strlen( $meta_token ) === 0 ) {
                    continue;
                }

                // Pure log meta lookup based on meta token.
                $meta_expression = self::get_meta_expression_value( $meta_token, $meta_data );

                // Additional log meta processing - handles derived or decorated log data.
                $meta_expression = Changes_Alert_Formatter::format_meta_expression( $meta_token, $meta_expression, $configuration, $log_id );

                if ( ! empty( $meta_expression ) ) {
                    $result[ $meta_label ] = $meta_expression;
                }
            }
        }

        return $result;
    }

    /**
     * Get formatter hyperlinks.
     *
     * @param array $configuration  - Alert message configuration rules.
     * @param array $meta_data     Meta data.
     * @param int   $log_id Occurrence ID.
     * @param array $log - The array with all the log details.
     *
     * @return string
     */
    public static function get_formatted_hyperlinks( $configuration, $meta_data, $log_id, $log ) {
        $result              = '';
        $hyperlinks_as_array = self::get_hyperlinks_as_array( $configuration, $meta_data, $log_id, $log );
        if ( ! empty( $hyperlinks_as_array ) ) {
            $links_result_parts = array();
            foreach ( $hyperlinks_as_array as  $link_data ) {
                $link_label       = $link_data['label'];
                $link_url         = $link_data['url'];
                $needs_formatting = $link_data['needs_formatting'];
                $formatted_link   = $needs_formatting ? Changes_Alert_Formatter::format_link( $configuration, $link_url, $link_label ) : $link_url;
                array_push( $links_result_parts, $formatted_link );
            }

            if ( ! empty( $links_result_parts ) ) {
                $result .= implode( $configuration['end_of_line'], $links_result_parts );
            }
        }

        return $result;
    }

    /**
     * Retrieves hyperlinks as an array.
     *
     * @param array $configuration  - Alert message configuration rules.
     * @param array $meta_data                            Meta data.
     * @param int   $log_id                        Occurrence ID.
     * @param array $log - The array with all the log details.
     * @param bool  $exclude_links_not_needing_formatting If true, links that don't need formatting will
     *                                                    be excluded. For example special links that
     *                                                    contain onclick attribute already from the meta
     *                                                    formatter.
     *
     * @return array
     */
    public static function get_hyperlinks_as_array( $configuration, $meta_data, $log_id, $log, $exclude_links_not_needing_formatting = false ) {
        $result = array();
        if ( ! empty( $log['links'] ) ) {
            foreach ( $log['links'] as $link_label => $link_data ) {

                $link_title = '';
                $link_url   = '';
                if ( is_string( $link_data ) ) {
                    if ( strlen( $link_data ) === 0 ) {
                        continue;
                    }

                    $link_url   = $link_data;
                    $link_title = $link_data;
                } else {
                    $link_url   = $link_data['url'];
                    $link_title = $link_data['label'];
                }

                /**
                 * Link url can be:
                 * - an actual URL
                 * - placeholder for an existing metadata field that contains a URL (or the full HTML A tag markup)
                 * -- before 4.2.1 the CommentLink meta would contain the full HTML markup for the link, now it contains only the URL
                 * - other placeholder for a dynamic or JS infused link that will be processed by the meta formatter.
                 */
                $needs_formatting = true;
                if ( ! Changes_Validator::is_valid_url( $link_url ) ) {

                    $meta_expression = self::get_meta_expression_value( $link_url, $meta_data );
                    $meta_expression = Changes_Alert_Formatter::format_meta_expression( $link_url, $meta_expression, $configuration, $log_id, $meta_data, false );
                    if ( ! empty( $meta_expression ) ) {
                        if ( Changes_Validator::is_valid_url( $meta_expression ) ) {

                            $link_url = $meta_expression;
                        } elseif ( preg_match( '/onclick=/', $meta_expression ) ) {
                            $link_url         = $meta_expression;
                            $needs_formatting = false;
                        } else {

                            preg_match( '/href=["\']https?:\/\/([^"\']+)["\']/', $meta_expression, $url_matches );
                            if ( count( $url_matches ) === 2 ) {
                                $link_url = $url_matches[1];
                            }
                        }
                    } else {
                        $link_url = '';
                    }
                }

                if ( $exclude_links_not_needing_formatting && ! $needs_formatting ) {
                    continue;
                }

                if ( ! empty( $link_url ) ) {
                    $result[ $link_label ] = array(
                        'url'              => $link_url,
                        'needs_formatting' => $needs_formatting,
                        'title'            => $link_title,
                        'label'            => $link_label,
                    );
                }
            }
        }

        return $result;
    }
}
