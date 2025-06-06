<?php
/**
 * Changes Logs Class
 *
 * @since 5.4.1
 *
 * @package MainWP\Child
 *
 * @author  WP Activity Log plugin.
 *
 */

declare(strict_types=1);


namespace MainWP\Child\Changes;

use MainWP\Child\Changes\Helpers\Changes_Settings_Helper;


// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}


/**
 * Responsible for data validation
 */
class Changes_Validator {

    /**
     * Validates mirror tags against rule sets
     * Note: no short-returns here as this could be used to collect all the problems for give tag
     *
     * @param string $string - The string to validate.
     *
     * @return boolean - true - tag is valid, false - tag is not valid.
     *
     */
    public static function validate_mirror_tag( string $string ): bool {

        $valid = true;

        if ( ! self::starts_alpha_numeric( $string ) ) {
            $valid = false;
        }

        if ( ! self::is_string_less_or_equal( $string, 64 ) ) {
            $valid = false;
        }

        if ( ! self::is_string_contains_only( $string ) ) {
            $valid = false;
        }

        return $valid;
    }

    /**
     * Checks if given string starts with a letter or number.
     *
     * @param string $string - The string to check.
     *
     * @return boolean
     *
     */
    private static function starts_alpha_numeric( string $string ): bool {
        if ( preg_match( '/^[a-zA-Z0-9]/', $string ) ) {
            return true;
        }

        return false;
    }

    /**
     * Checks if string has less or equal number of characters.
     *
     * @param string  $string - The string to check.
     * @param integer $size - The size of the string to check for.
     *
     * @return boolean
     *
     */
    private static function is_string_less_or_equal( string $string, int $size ): bool {
        if ( strlen( $string ) <= $size ) {
            return true;
        }

        return false;
    }

    /**
     * Check if string contains only specific characters
     *
     * @param string $string - The string to check.
     *
     * @return boolean
     *
     */
    private static function is_string_contains_only( string $string ): bool {
        if ( preg_match( '/[a-z\d_-]*/i', $string ) ) {
            return true;
        }
        return false;
    }

    /**
     * Validate IP address.
     *
     * @param string $ip - IP address.
     *
     * @return string|bool
     */
    public static function validate_ip( $ip ) {
        $opts = FILTER_FLAG_IPV4 | FILTER_FLAG_IPV6;

        //if ( Changes_Settings_Helper::get_boolean_option_value( 'filter-internal-ip', false ) ) { .
            $opts = $opts | FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE;
        //}

        $filtered_ip = filter_var( $ip, FILTER_VALIDATE_IP, $opts );

        if ( ! $filtered_ip || empty( $filtered_ip ) ) {
            // Regex IPV4.
            if ( preg_match( '/^(([1-9]?[0-9]|1[0-9]{2}|2[0-4][0-9]|25[0-5]).){3}([1-9]?[0-9]|1[0-9]{2}|2[0-4][0-9]|25[0-5])$/', $ip ) ) {
                return $ip;
            } elseif ( preg_match( '/^\s*((([0-9A-Fa-f]{1,4}:){7}([0-9A-Fa-f]{1,4}|:))|(([0-9A-Fa-f]{1,4}:){6}(:[0-9A-Fa-f]{1,4}|((25[0-5]|2[0-4]\d|1\d\d|[1-9]?\d)(\.(25[0-5]|2[0-4]\d|1\d\d|[1-9]?\d)){3})|:))|(([0-9A-Fa-f]{1,4}:){5}(((:[0-9A-Fa-f]{1,4}){1,2})|:((25[0-5]|2[0-4]\d|1\d\d|[1-9]?\d)(\.(25[0-5]|2[0-4]\d|1\d\d|[1-9]?\d)){3})|:))|(([0-9A-Fa-f]{1,4}:){4}(((:[0-9A-Fa-f]{1,4}){1,3})|((:[0-9A-Fa-f]{1,4})?:((25[0-5]|2[0-4]\d|1\d\d|[1-9]?\d)(\.(25[0-5]|2[0-4]\d|1\d\d|[1-9]?\d)){3}))|:))|(([0-9A-Fa-f]{1,4}:){3}(((:[0-9A-Fa-f]{1,4}){1,4})|((:[0-9A-Fa-f]{1,4}){0,2}:((25[0-5]|2[0-4]\d|1\d\d|[1-9]?\d)(\.(25[0-5]|2[0-4]\d|1\d\d|[1-9]?\d)){3}))|:))|(([0-9A-Fa-f]{1,4}:){2}(((:[0-9A-Fa-f]{1,4}){1,5})|((:[0-9A-Fa-f]{1,4}){0,3}:((25[0-5]|2[0-4]\d|1\d\d|[1-9]?\d)(\.(25[0-5]|2[0-4]\d|1\d\d|[1-9]?\d)){3}))|:))|(([0-9A-Fa-f]{1,4}:){1}(((:[0-9A-Fa-f]{1,4}){1,6})|((:[0-9A-Fa-f]{1,4}){0,4}:((25[0-5]|2[0-4]\d|1\d\d|[1-9]?\d)(\.(25[0-5]|2[0-4]\d|1\d\d|[1-9]?\d)){3}))|:))|(:(((:[0-9A-Fa-f]{1,4}){1,7})|((:[0-9A-Fa-f]{1,4}){0,5}:((25[0-5]|2[0-4]\d|1\d\d|[1-9]?\d)(\.(25[0-5]|2[0-4]\d|1\d\d|[1-9]?\d)){3}))|:)))(%.+)?\s*$/', $ip ) ) {
                // Regex IPV6.
                return $ip;
            }

            return false;
        } else {
            return $filtered_ip;
        }
    }

    /**
     * Simple check for validating a URL, it must start with http:// or https://.
     * and pass FILTER_VALIDATE_URL validation.
     *
     * @param string $url to check.
     *
     * @return bool
     *
     * @since 4.6.0
     */
    public static function is_valid_url( $url ) {
        // Must start with http:// or https://.
        if ( 0 !== strpos( $url, 'http://' ) && 0 !== strpos( $url, 'https://' ) ) {
            return false;
        }

        // Must pass validation.
        if ( ! filter_var( $url, FILTER_VALIDATE_URL ) ) {
            return false;
        }

        return true;
    }

    /**
     * Validates username
     *
     * @param string $username - The username to validate.
     *
     * @return boolean
     *
     * @since 5.2.1
     */
    public static function validate_username( string $username ): bool {

        if ( preg_match( '/^[A-Za-z0-9\_\.\ \-\@]{3,}$/', $username ) ) {
            return true;
        }

        return false;
    }

    /**
     * Check if the float is IPv4 instead.
     *
     * @param float $ip_address - Number to check.
     *
     * @return bool result validation
     *
     * @since 4.6.0
     */
    public static function is_ip_address( $ip_address ) {
        return filter_var( $ip_address, FILTER_VALIDATE_IP ) !== false;
    }

    /**
     * Checks if give string is a valid JSON
     *
     * @param string $json_string - The possible JSON string to validate.
     *
     * @return boolean
     *
     * @since 5.2.1
     */
    public static function validate_json( string $json_string ): bool {
        // decode the JSON data.
        $result = json_decode( $json_string );

        // switch and check possible JSON errors.
        switch ( json_last_error() ) {
            case JSON_ERROR_NONE:
                $error = ''; // JSON is valid // No error has occurred.
                break;
            case JSON_ERROR_DEPTH:
                $error = 'The maximum stack depth has been exceeded.';
                break;
            case JSON_ERROR_STATE_MISMATCH:
                $error = 'Invalid or malformed JSON.';
                break;
            case JSON_ERROR_CTRL_CHAR:
                $error = 'Control character error, possibly incorrectly encoded.';
                break;
            case JSON_ERROR_SYNTAX:
                $error = 'Syntax error, malformed JSON.';
                break;
            // PHP >= 5.3.3.
            case JSON_ERROR_UTF8:
                $error = 'Malformed UTF-8 characters, possibly incorrectly encoded.';
                break;
            // PHP >= 5.5.0.
            case JSON_ERROR_RECURSION:
                $error = 'One or more recursive references in the value to be encoded.';
                break;
            // PHP >= 5.5.0.
            case JSON_ERROR_INF_OR_NAN:
                $error = 'One or more NAN or INF values in the value to be encoded.';
                break;
            case JSON_ERROR_UNSUPPORTED_TYPE:
                $error = 'A value of a type that cannot be encoded was given.';
                break;
            default:
                $error = 'Unknown JSON error occured.';
                break;
        }

        if ( '' !== $error ) {
            return false;
        }

        // everything is OK.
        return true;
    }
}
