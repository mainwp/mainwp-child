<?php
/**
 * Manager: Alert Formatter Class
 *
 * Class file for alert formatting.
 *
 * @since 5.4.1
 * @package mainwp/child
 */

declare(strict_types=1);

namespace MainWP\Child\Changes;

use MainWP\Child\Changes\Helpers\Changes_WP_Helper;
use MainWP\Child\Changes\Entities\Changes_Metadata_Entity;
use MainWP\Child\Changes\Entities\Changes_Logs_Entity;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Changes_Alert_Formatter class.
 *
 * Class for handling the formatting of alert message/UI widget in different contexts.
 *
 * Formatting rules are given by given formatter configuration.
 */
class Changes_Alert_Formatter {

    /**
     * Formats given meta expression.
     *
     * @param string   $expression    Meta expression including the surrounding percentage chars.
     * @param string   $value         Meta value.
     * @param array    $configuration         The configuration rules for formatting which needs to be used.
     * @param int|null $occurrence_id Occurrence ID. Only present if the event was already written to the database.
     * @param array    $metadata      Meta data.
     * @param bool     $wrap          Should metadata be wrapped in highlighted markup.
     *
     * @return false|mixed|string|void|WP_Error
     */
    public static function format_meta_expression( $expression, $value, array $configuration, $occurrence_id = null, $metadata = array(), $wrap = true ) {
        \add_filter( 'mainwp_child_changes_logs_truncate_alert_value', array( __CLASS__, 'data_truncate' ), 10, 4 );

        $value = apply_filters( 'mainwp_child_changes_logs_truncate_alert_value', $value, $expression, $configuration['max_meta_value_length'], $configuration['ellipses_sequence'] );

        switch ( true ) {
            case '%Message%' === $expression:
                return esc_html( $value );

            case '%MetaLink%' === $expression:
                // NULL value check is here because events related to user meta fields didn't have the MetaLink meta prior to version 4.3.2.

                if ( $configuration['is_js_in_links_allowed'] && 'NULL' !== $value ) {
                    $label  = __( 'Exclude custom field from the monitoring', 'mainwp-child' );
                    $result = "<a href=\"#\" data-object-type='{$metadata['Object']}' data-disable-custom-nonce='" . \wp_create_nonce( 'disable-custom-nonce' . $value ) . "' onclick=\"return WsalDisableCustom(this, '" . $value . "');\"> {$label}</a>";

                    return self::wrap_in_hightlight_markup( $result, $configuration, true );
                }

                return '';

            case in_array( $expression, array( '%path%', '%old_path%', '%FilePath%' ), true ):
                // Concatenate directory and file paths.
                if ( $configuration['is_js_in_links_allowed'] ) {
                    $result = '<strong><span>' . $value . '</span>'; // phpcs:ignore
                    $result .= "<a href=\"#\" data-shortened-text='{$value}'>" . $configuration['ellipses_sequence'] . "</a></strong>"; // phpcs:ignore

                    return $result;
                }

                return $value;

            case in_array( $expression, array( '%MetaValue%', '%MetaValueOld%', '%MetaValueNew%' ), true ):
                // Trim the meta value to the maximum length and append configured ellipses sequence.
                $result = $value;

                return self::wrap_in_hightlight_markup( $result, $configuration );

            case '%ClientIP%' === $expression:
            case '%IPAddress%' === $expression:
                if ( is_string( $value ) ) {
                    $sanitized_ips = str_replace(
                        array(
                            '"',
                            '[',
                            ']',
                        ),
                        '',
                        $value
                    );

                    return self::wrap_in_hightlight_markup( $sanitized_ips, $configuration );
                } else {
                    return self::wrap_in_emphasis_markup( __( 'unknown', 'mainwp-child' ), $configuration );
                }

            case '%PostUrlIfPlublished%' === $expression:
                $post_id = null;
                if ( is_array( $metadata ) && array_key_exists( 'PostID', $metadata ) ) {
                    $post_id = $metadata['PostID'];
                } else {
                    $post_id = self::get_occurrence_meta_item( $occurrence_id, 'PostID' );
                }

                $occurrence_data = Changes_Logs_Entity::load( 'id = %d', array( $occurrence_id ) );

                if ( isset( $occurrence_data ) && isset( $occurrence_data['site_id'] ) ) {
                    // if ( MainWP_Helper::SET_SITE_ID_NUMBER < $occurrence_data['site_id'] ) {
                    if ( isset( $metadata['PostUrl'] ) ) {
                        return $metadata['PostUrl'];
                    } else {
                        return '';
                    }
                    // }
                }

                $occ_post = ! is_null( $post_id ) ? get_post( $post_id ) : null;
                if ( null !== $occ_post && 'publish' === $occ_post->post_status ) {
                    return get_permalink( $occ_post->ID );
                }

                return '';

            case '%MenuUrl%' === $expression:
                $menu_id = null;
                if ( 0 === $occurrence_id && is_array( $metadata ) && array_key_exists( 'MenuID', $metadata ) ) {
                    $menu_id = $metadata['MenuID'];
                } else {
                    $menu_id = self::get_occurrence_meta_item( $occurrence_id, 'MenuID' );
                }
                if ( null !== $menu_id ) {
                    return add_query_arg(
                        array(
                            'action' => 'edit',
                            'menu'   => $menu_id,
                        ),
                        admin_url( 'nav-menus.php' )
                    );
                }

                return '';

            case '%Attempts%' === $expression: // Failed login attempts.
                $check_value = (int) $value;
                if ( 0 === $check_value ) {
                    return '';
                } else {
                    return $value;
                }

            case '%LogFileText%' === $expression: // Failed login file text.
                return '';

            case in_array( $expression, array( '%PostStatus%', '%ProductStatus%' ), true ):
                $result = $value;

                return self::wrap_in_hightlight_markup( $result, $configuration );

            case '%multisite_text%' === $expression:
                if ( Changes_WP_Helper::is_multisite() && $value ) {
                    $site_info = get_blog_details( $value, true );
                    if ( $site_info ) {
                        $site_url = $site_info->siteurl;

                        return ' on site ' . self::format_link( $configuration, $expression, $site_info->blogname, $site_url );
                    }
                }

                return '';

            case '%ReportText%' === $expression:
            case '%ChangeText%' === $expression:
                return '';

            case '%TableNames%' === $expression:
                $value = str_replace( ',', ', ', $value );

                return self::wrap_in_hightlight_markup( \esc_html( $value ), $configuration );

            case '%LineBreak%' === $expression:
                return $configuration['end_of_line'];

            case '%PluginFile%' === $expression:
                return self::wrap_in_hightlight_markup( dirname( $value ), $configuration );

            case '%OldVersion%' === $expression:
                $return = ( 'NULL' !== $value ) ? $value : esc_html__( 'Upgrade event prior to WP Activity Log 5.0.0.', 'mainwp-child' );
                return self::wrap_in_hightlight_markup( $return, $configuration );

            default:
                // Ensure result is wrapped as expected.
                if ( $wrap ) {
                    $result = self::wrap_in_hightlight_markup( $result, $configuration );
                }

                return apply_filters( 'mainwp_child_changes_logs_format_custom_meta', $result, $expression, $configuration, $occurrence_id );
        }
    }

    /**
     * Truncates the data to a specific number of characters.
     *
     * @param mixed   $value - The value to be truncated.
     * @param string  $expression - The expression for that value.
     * @param integer $length - Number of characters to truncate to.
     * @param string  $ellipses_sequence - The sequence of ellipses suffix.
     *
     * @return string|mixed
     */
    public static function data_truncate( $value, $expression, $length = 50, $ellipses_sequence = '...' ) {

        switch ( $expression ) {
            case '%path%':
            case '%old_path%':
            case '%FilePath%':
                if ( mb_strlen( $value ) > $length ) {
                    $value = mb_substr( $value, 0, $length ); // phpcs:ignore
                }
                break;
            case '%MetaValue%':
            case '%MetaValueOld%':
            case '%MetaValueNew%':
                $value = mb_strlen( $value ) > $length ? ( mb_substr( $value, 0, $length ) . $ellipses_sequence ) : $value;
                break;
            default:
                break;
        }

        return $value;
    }

    /**
     * Handles formatting of hyperlinks in the event messages.
     *
     * Contains:
     * - check for empty values
     * - check if the link is disabled
     * - optional URL processing
     *
     * @param array  $configuration         The configuration rules for formatting which needs to be used.
     * @param string $url URL.
     * @param string $label Label.
     * @param string $title Title.
     * @param string $target Target attribute.
     *
     * @return string
     */
    public static function format_link( array $configuration, $url, $label, $title = '', $target = '_blank' ) {
        // Check for empty values.
        if ( null === $url || empty( $url ) ) {
            return '';
        }

        $processed_url = $url;
        $result        = self::build_link_markup( $configuration, $processed_url, $label, $title, $target );

        return self::wrap_in_hightlight_markup( $result, $configuration, true );
    }

    /**
     * Message for some events contains HTML tags for highlighting certain parts of the message.
     *
     * This function replaces the original HTML tags with the correct highlight tags.
     *
     * It also strips any additional HTML tags apart from hyperlink and an end of line to support legacy messages.
     *
     * @param string $message Message text.
     * @param array  $configuration         The configuration rules for formatting which needs to be used.
     *
     * @return string
     */
    public static function process_html_tags_in_message( $message, array $configuration ) {

        $result = preg_replace(
            array( '/<strong>/', '/<\/strong>/' ),
            array( $configuration['highlight_start_tag'], $configuration['highlight_end_tag'] ),
            $message
        );

        return strip_tags( $result, $configuration['tags_allowed_in_message'] );
    }

    /**
     * Override this method in subclass to format hyperlinks differently.
     *
     * Default implementation returns HTML A tag. Only implementation at the moment. We used to have Slack as well, but
     * we moved to a different implementation. Introducing another link markup would require adding link format with
     * placeholders to the formatter configuration.
     *
     * @param array  $configuration         The configuration rules for formatting which needs to be used.
     * @param string $url    URL.
     * @param string $label  Label.
     * @param string $title  Title.
     * @param string $target Target attribute.
     *
     * @return string
     */
    private static function build_link_markup( array $configuration, $url, $label, $title = '', $target = '_blank' ) {

        if ( $configuration['use_html_markup_for_links'] ) {
            return '<a href="' . \esc_url( $url ) . '" title="' . $title . '" target="' . $target . '">' . $label . '</a>';
        }

        return $label . ': ' . \esc_url( $url );
    }
    /**
     * Wraps given value in highlight markup.
     *
     * For example meta values displayed as <strong>{meta value}</strong> in the WP admin UI.
     *
     * @param string $value Value.
     * @param array  $configuration         The configuration rules for formatting which needs to be used.
     * @param bool   $no_esc - Do we need to escape the html in the message.
     *
     * @return string
     */
    private static function wrap_in_hightlight_markup( $value, array $configuration, $no_esc = false ) {
        if ( ! $no_esc ) {
            $value = esc_html( $value );
        }

        return $configuration['highlight_start_tag'] . $value . $configuration['highlight_end_tag'];
    }

    /**
     * Wraps given value in emphasis markup.
     *
     * For example an unknown IP address is displayed as <i>unknown</i> in the WP admin UI.
     *
     * @param string $value Value.
     * @param array  $configuration         The configuration rules for formatting which needs to be used.
     *
     * @return string
     */
    private static function wrap_in_emphasis_markup( $value, array $configuration ) {

        return $configuration['emphasis_start_tag'] . $value . $configuration['emphasis_end_tag'];
    }

    /**
     * Helper function to get meta value from an occurrence.
     *
     * @param int    $occurrence_id Occurrence ID.
     * @param string $meta_key      Meta key.
     *
     * @return mixed|null Meta value if exists. Otherwise null
     */
    private static function get_occurrence_meta_item( $occurrence_id, $meta_key ) {
        // get values needed.
        $meta_result = Changes_Metadata_Entity::load_by_name_and_occurrence_id( $meta_key, $occurrence_id );

        return isset( $meta_result['value'] ) ? maybe_unserialize( $meta_result['value'] ) : null;
    }
}
