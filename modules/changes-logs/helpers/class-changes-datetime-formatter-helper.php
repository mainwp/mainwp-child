<?php
/**
 * Class: DateTime formatter Helper.
 *
 * Helper class used for extraction / loading classes.
 *
 * @package mainwp/child
 *
 * @since 5.4.1
 */

declare(strict_types=1);

namespace MainWP\Child\Changes\Helpers;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Date and Time Utility Class
 *
 * @since 4.5.0
 */
class Changes_DateTime_Formatter_Helper {
    /**
     * Regular expression for matching the milliseconds part of datetime string.
     *
     * @var string
     */
    private static $am_pm_lookup_pattern = '/\.\d+((\&nbsp;|\ )([AP]M))?/i';

    /**
     * GMT Offset
     *
     * @var string
     */
    private static $gmt_offset_sec = 0;

    /**
     * Date format.
     *
     * @var string
     */
    private static $date_format;

    /**
     * Time format.
     *
     * @var string
     */
    private static $time_format;

    /**
     * Datetime format.
     *
     * @var string
     */
    private static $datetime_format;

    /**
     * Datetime format without linebreaks.
     *
     * @var string
     */
    private static $datetime_format_no_linebreaks;

    /**
     * If true, show milliseconds.
     *
     * @var bool
     */
    private static $show_milliseconds = null;

    /**
     * Call this method to get singleton
     */
    public static function init() {

        $timezone = Changes_Settings_Helper::get_timezone();

        /**
         * Transform timezone values.
         *
         * @since 3.2.3
         */
        if ( '0' === $timezone ) {
            $timezone = 'utc';
        } elseif ( '1' === $timezone ) {
            $timezone = 'wp';
        }

        if ( 'utc' === $timezone ) {
            self::$gmt_offset_sec = date( 'Z' ); // phpcs:ignore WordPress.DateTime.RestrictedFunctions.date_date
        } else {
            self::$gmt_offset_sec = get_option( 'gmt_offset' ) * HOUR_IN_SECONDS;
        }

        self::$show_milliseconds             = Changes_Settings_Helper::get_show_milliseconds();
        self::$date_format                   = Changes_Settings_Helper::get_date_format();
        self::$time_format                   = Changes_Settings_Helper::get_time_format();
        self::$datetime_format               = Changes_Settings_Helper::get_datetime_format();
        self::$datetime_format_no_linebreaks = Changes_Settings_Helper::get_datetime_format( false );
    }

    /**
     * Remove milliseconds from formatted datetime string.
     *
     * @param string $formatted_datetime Formatted datetime string.
     *
     * @return string
     *
     * @since 4.2.0
     */
    public static function remove_milliseconds( $formatted_datetime ) {
        return preg_replace( self::$am_pm_lookup_pattern, ' $3', $formatted_datetime );
    }

    /**
     * Formats date time based on various requirements.
     *
     * @param float  $timestamp              Timestamp.
     * @param string $type                   Output type.
     * @param bool   $do_timezone_offset     If true, timezone offset is applied to the timestamp.
     * @param bool   $line_break             If true, line-break characters are included.
     * @param bool   $use_nb_space_for_am_pm If true, non-breakable space is included before AM/PM part.
     * @param bool   $translated             If true, the result is translated.
     *
     * @return string
     */
    public static function get_formatted_date_time( $timestamp, $type = 'datetime', $do_timezone_offset = true, $line_break = false, $use_nb_space_for_am_pm = true, $translated = true ) {

        if ( null === self::$show_milliseconds ) {
            self::init();
        }

        $result = '';
        $format = null;
        switch ( $type ) {
            case 'datetime':
                $format = $line_break ? self::$datetime_format : self::$datetime_format_no_linebreaks;
                if ( ! $use_nb_space_for_am_pm ) {
                    $format = preg_replace( '/&\\\n\\\b\\\s\\\p;/', ' ', $format );
                }
                break;
            case 'date':
                $format = self::$date_format;
                break;
            case 'time':
                $format = self::$time_format;
                break;
            default:
                return $result;
        }

        if ( null === $format ) {
            return $result;
        }

        // Timezone adjustment.
        $timezone_adjusted_timestamp = (int) ( $do_timezone_offset ? $timestamp + self::$gmt_offset_sec : $timestamp );

        // Milliseconds in format (this is probably not necessary, but we keep it just to be 100% sure).
        if ( ! self::$show_milliseconds ) {
            // Remove the milliseconds placeholder from format string.
            $format = str_replace( '.$$$', '', $format );
        }

        // Date formatting.
        $result = $translated ? date_i18n( $format, $timezone_adjusted_timestamp ) : date( $format, $timezone_adjusted_timestamp ); // phpcs:ignore WordPress.DateTime.RestrictedFunctions.date_date

        // Milliseconds value.
        if ( self::$show_milliseconds ) {
            $result = str_replace(
                '$$$',
                substr( number_format( fmod( $timezone_adjusted_timestamp, 1 ), 3 ), 2 ),
                $result
            );
        }

        return $result;
    }

    /**
     * Returns the offset of the timezone
     *
     * @return int
     */
    public static function get_time_zone_offset() {
        if ( null === self::$show_milliseconds ) {
            self::init();
        }

        return self::$gmt_offset_sec;
    }
}
