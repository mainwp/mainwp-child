<?php
/**
 * Class: Helper responsible for PHP functionalities improvement.
 *
 * @author  WP Activity Log plugin.
 *
 * Changes Logs Class
 *
 * @since 5.4.1
 *
 * @package MainWP\Child
 */

declare(strict_types=1);

namespace MainWP\Child\Changes\Helpers;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Ease some of the PHP functionalities.
 */
class Changes_PHP_Helper {

    /**
     * Use this method if you need to split string by comma, remove spaces (leading and trailing), remove empty elements from the array and.
     *
     * @param string $string - The string to split.
     *
     * @return array
     *
     */
    public static function string_to_array( string $string ): array {
        if ( empty( $string ) ) {
            return array();
        }

        $split_string = explode( ',', $string );

        // Remove the whitespaces.
        $split_string = array_map( 'trim', $split_string );

        // Removes empty elements.
        $split_string = array_filter( $split_string );

        if ( ! is_array( $split_string ) ) {
            return array();
        }

        return $split_string;
    }

    /**
     * Filters request data.
     *
     * @return array Filtered request data.
     *
     */
    public static function get_filtered_request_data(): array {
        $result = array();

        $get_data = filter_input_array( INPUT_GET );
        if ( is_array( $get_data ) ) {
            $result = array_merge( $result, $get_data );
        }

        $post_data = filter_input_array( INPUT_POST );
        if ( is_array( $post_data ) ) {
            $result = array_merge( $result, $post_data );
        }

        return $result;
    }

    /**
     * A wrapper for JSON encoding that fixes potential issues.
     *
     * @param mixed $data The data to encode.
     * @return string JSON string.
     */
    public static function json_encode( $data ) {
        return @json_encode( $data ); // phpcs:ignore
    }

    /**
     * A wrapper for JSON encoding that fixes potential issues.
     *
     * @param string $data - The JSON string to decode.
     * @return mixed Decoded data.
     */
    public static function json_decode( $data ) {
        return @json_decode( $data ); // phpcs:ignore
    }
}
