<?php
/**
 * MainWP Changes DB
 *
 * This file handles all interactions with the DB.
 *
 * @since 5.4.1
 *
 * @package MainWP/Child
 */

namespace MainWP\Child\Changes;

use MainWP\Child\MainWP_Child_DB_Base;

/**
 * Class MainWP_DB
 *
 * @package MainWP\Child
 *
 * @uses \MainWP\Child\MainWP_Child_DB_Helper
 */
class Changes_DB extends MainWP_Child_DB_Base { // phpcs:ignore Generic.Classes.OpeningBraceSameLine.ContentAfterBrace -- NOSONAR.

    // phpcs:disable WordPress.DB.RestrictedFunctions, WordPress.DB.PreparedSQL.NotPrepared, Generic.Metrics.CyclomaticComplexity -- This is the only way to achieve desired results, pull request solutions appreciated.

    /**
     * Private static variable to hold the single instance of the class.
     *
     * @static
     *
     * @var mixed Default null
     */
    private static $instance = null;

    /**
     * Create public static instance.
     *
     * @static
     *
     * @return MainWP_Child_DB_Helper
     */
    public static function instance() {
        if ( null === static::$instance ) {
            static::$instance = new self();
        }

        static::$instance->test_connection();

        return static::$instance;
    }
}
