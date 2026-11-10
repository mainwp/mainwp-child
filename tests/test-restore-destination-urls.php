<?php
/**
 * Destination option persistence across a restored database.
 *
 * @package MainWP/Child
 */

use MainWP\Child\MainWP_Child_Restore_Task_Runner;

require_once __DIR__ . '/test-backup-restore-task-runner.php';

/**
 * Exercise the shared capture and write-back with real option rows.
 */
class RestoreDestinationUrlsTest extends WP_UnitTestCase {

    /** Existing trailing-slash and non-URL prefix maps keep their literal behavior. */
    public function test_mapped_prefix_compatibility() {
        $rewriter = new \MainWP\Child\MainWP_Backup_Url_Rewriter();
        $this->assertSame(
            'https://destination.example/wordpress/admin new_capabilities',
            $rewriter->replace_value(
                'https://source.example/wp/admin wp_capabilities',
                array(
                    array( 'from' => 'https://source.example/wp/', 'to' => 'https://destination.example/wordpress/' ),
                    array( 'from' => 'wp_', 'to' => 'new_' ),
                )
            )
        );
    }

    /** Missing effective options must never remove source URL bytes from content. */
    public function test_false_destination_options_do_not_erase_links() {
        global $wpdb;
        $source = 'https://source.example';
        $wpdb->query( 'CREATE TABLE `rt_links` (id INT PRIMARY KEY, body LONGTEXT)' );
        add_filter( 'option_home', '__return_false' );
        add_filter( 'option_siteurl', '__return_false' );
        try {
            $this->assertSame( 1, $wpdb->insert( 'rt_links', array( 'id' => 1, 'body' => $source . '/post' ) ) );
            $method = new ReflectionMethod( MainWP_Child_Restore_Task_Runner::class, 'url_pairs' );
            $method->setAccessible( true );
            $pairs = $method->invoke( new Testable_Restore_Task_Runner(), array( 'db_replay' => array( 'banner_info' => array( 'home_url' => $source, 'site_url' => $source ) ) ) );
            $outcome = ( new \MainWP\Child\MainWP_Backup_Url_Rewriter() )->rewrite_slice( array(), array( 'tables' => array( 'rt_links' ), 'pairs' => $pairs, 'exclude' => array() ), time() + 10 );
            $this->assertTrue( $outcome['done'] );
            $this->assertSame( $source . '/post', $wpdb->get_var( 'SELECT body FROM rt_links WHERE id = 1' ) );
        } finally {
            remove_filter( 'option_home', '__return_false' );
            remove_filter( 'option_siteurl', '__return_false' );
            $wpdb->query( 'DROP TABLE rt_links' );
        }
    }

    /**
     * Each option must keep its own destination, including same-domain restores.
     *
     * @dataProvider destination_cases
     * @param string $home Destination home.
     * @param string $siteurl Destination installation URL.
     */
    public function test_persists_destination_options( $home, $siteurl ) {
        global $wpdb;
        $table = 'rt_options';
        $wpdb->query( 'CREATE TABLE `rt_options` LIKE ' . $wpdb->options ); // phpcs:ignore WordPress.DB -- disposable fixture.
        try {
            foreach ( array( 'home' => $home, 'siteurl' => $siteurl ) as $name => $value ) {
                $this->assertSame( 1, $wpdb->insert( $table, array( 'option_name' => $name, 'option_value' => $value, 'autoload' => 'yes' ) ) );
            }
            $runner = new Testable_Restore_Task_Runner();
            $state  = array( 'db_replay' => array( 'fk_path' => true ) );
            $capture = new ReflectionMethod( MainWP_Child_Restore_Task_Runner::class, 'capture_connection_identity' );
            $capture->setAccessible( true );
            $capture->invokeArgs( $runner, array( &$state ) );
            $this->assertNotFalse( $wpdb->query( "UPDATE `rt_options` SET option_value = 'https://source.example'" ) ); // phpcs:ignore WordPress.DB -- model imported source rows.
            $restore = new ReflectionMethod( MainWP_Child_Restore_Task_Runner::class, 'restore_connection_identity' );
            $restore->setAccessible( true );
            $restore->invokeArgs( $runner, array( &$state ) );
            $state['db_replay']['cursor']['tables']['options'] = array( 'status' => 'ok' );
            $verify = new ReflectionMethod( $runner, 'verify_destination_urls' );
            $verify->setAccessible( true );
            $this->assertNull( $verify->invoke( $runner, $state ) );
            foreach ( array( 'home' => $home, 'siteurl' => $siteurl ) as $name => $value ) {
                $this->assertSame( $value, $wpdb->get_row( $wpdb->prepare( 'SELECT option_value FROM `rt_options` WHERE option_name = %s', $name ), ARRAY_A )['option_value'], $name ); // phpcs:ignore WordPress.DB -- bypass cache.
            }
        } finally {
            $wpdb->query( 'DROP TABLE `rt_options`' ); // phpcs:ignore WordPress.DB -- fixture cleanup.
        }
    }

    /**
     * @return array Test cases.
     */
    public static function destination_cases() {
        return array(
            'different domain' => array( 'https://destination.example', 'https://destination.example' ),
            'separate URLs'    => array( 'https://destination.example', 'https://destination.example/wp' ),
            'siteurl separately' => array( 'https://source.example', 'https://destination.example/wp' ),
            'empty home'       => array( '', 'https://destination.example/wp' ),
            'same domain'      => array( 'https://source.example', 'https://source.example' ),
        );
    }

    /**
     * Runtime constants must not replace the captured stored option values.
     *
     * @runInSeparateProcess
     * @preserveGlobalState disabled
     */
    public function test_config_constants_remain_authoritative() {
        define( 'WP_HOME', 'https://constant-home.example' );
        define( 'WP_SITEURL', 'https://constant-site.example/wp' );
        $this->test_persists_destination_options( 'https://stored-home.example', 'https://stored-site.example/wp' );
        $this->assertSame( WP_HOME, home_url() );
        $this->assertSame( WP_SITEURL, site_url( '', 'https' ) );
    }

    /**
     * An actual failed UPDATE must name the correction and invalidate caches.
     */
    public function test_failed_url_write_reports_correction() {
        global $wpdb;
        $wpdb->query( 'CREATE TABLE `rt_options` LIKE ' . $wpdb->options ); // phpcs:ignore WordPress.DB -- disposable fixture.
        $this->assertSame( 1, $wpdb->insert( 'rt_options', array( 'option_name' => 'home', 'option_value' => 'https://source.example', 'autoload' => 'yes' ) ) );
        $state = array( 'identity_preserve' => array( array( 'option_name' => 'home', 'option_value' => 'https://destination.example', 'autoload' => 'yes' ) ) );
        $fail = static function ( $sql ) {
            return false !== strpos( $sql, 'UPDATE `rt_options` SET option_value' ) ? 'UPDATE mainwp_missing_url_fixture SET option_value = 1' : $sql;
        };
        $runner = new Testable_Restore_Task_Runner();
        $restore = new ReflectionMethod( MainWP_Child_Restore_Task_Runner::class, 'restore_connection_identity' );
        $restore->setAccessible( true );
        wp_cache_set( 'alloptions', array( 'home' => 'https://cached.example' ), 'options' );
        add_filter( 'query', $fail );
        try {
            $restore->invokeArgs( $runner, array( &$state ) );
            $this->fail( 'A failed destination URL write must not succeed.' );
        } catch ( \MainWP\Child\MainWP_Exception $e ) {
            $this->assertStringContainsString( 'wp option update home', $e->getMessage() );
            $this->assertStringContainsString( 'wp option update home DESTINATION_HOME_URL', $e->getMessage() );
            $this->assertStringContainsString( 'wp option update siteurl DESTINATION_SITEURL', $e->getMessage() );
            $this->assertStringNotContainsString( '&#', $e->getMessage() );
            $this->assertFalse( wp_cache_get( 'alloptions', 'options' ) );
        } finally {
            remove_filter( 'query', $fail );
            $wpdb->query( 'DROP TABLE `rt_options`' ); // phpcs:ignore WordPress.DB -- fixture cleanup.
        }
    }

    /** Absent rows must remain absent, while an empty stored home remains stored. */
    public function test_absent_destination_url_is_restored_and_verified() {
        global $wpdb;
        $wpdb->query( 'CREATE TABLE `rt_options` LIKE ' . $wpdb->options );
        $runner = new Testable_Restore_Task_Runner();
        $state = array( 'db_replay' => array( 'fk_path' => true, 'cursor' => array( 'tables' => array( 'options' => array( 'status' => 'ok' ) ) ) ) );
        $capture = new ReflectionMethod( $runner, 'capture_connection_identity' );
        $restore = new ReflectionMethod( $runner, 'restore_connection_identity' );
        $verify = new ReflectionMethod( $runner, 'verify_destination_urls' );
        foreach ( array( $capture, $restore, $verify ) as $method ) {
            $method->setAccessible( true );
        }
        try {
            $this->assertSame( 1, $wpdb->insert( 'rt_options', array( 'option_name' => 'siteurl', 'option_value' => 'https://destination.example', 'autoload' => 'yes' ) ) );
            $capture->invokeArgs( $runner, array( &$state ) );
            $this->assertSame( 1, $wpdb->insert( 'rt_options', array( 'option_name' => 'home', 'option_value' => 'https://source.example', 'autoload' => 'yes' ) ) );
            $restore->invokeArgs( $runner, array( &$state ) );
            $this->assertNull( $wpdb->get_var( "SELECT option_name FROM rt_options WHERE option_name = 'home'" ) );
            $this->assertNull( $verify->invoke( $runner, $state ) );
            $original_options = $wpdb->options;
            $fallback = static function () { return get_option( 'siteurl' ); };
            add_filter( 'default_option_home', $fallback );
            try {
                $wpdb->options = 'rt_options';
                $this->assertSame( 'https://destination.example', get_option( 'home' ) );
                $this->assertSame( 'https://destination.example', home_url() );
            } finally {
                $wpdb->options = $original_options;
                remove_filter( 'default_option_home', $fallback );
                wp_cache_delete( 'alloptions', 'options' );
                wp_cache_delete( 'notoptions', 'options' );
            }
            $this->assertSame( 1, $wpdb->insert( 'rt_options', array( 'option_name' => 'home', 'option_value' => 'https://source.example', 'autoload' => 'yes' ) ) );
            try {
                $verify->invoke( $runner, $state );
                $this->fail( 'A reintroduced source home must prevent completion.' );
            } catch ( \MainWP\Child\MainWP_Exception $e ) {
                $this->assertStringContainsString( 'wp option delete home', $e->getMessage() );
            }
        } finally {
            $wpdb->query( 'DROP TABLE `rt_options`' );
        }
    }

    /** Failed deletion of an imported URL must report the required correction. */
    public function test_failed_absent_url_delete_reports_correction() {
        global $wpdb;
        $wpdb->query( 'CREATE TABLE `rt_options` LIKE ' . $wpdb->options );
        $this->assertSame( 1, $wpdb->insert( 'rt_options', array( 'option_name' => 'home', 'option_value' => 'https://source.example', 'autoload' => 'yes' ) ) );
        $state = array( 'identity_preserve' => array(), 'identity_absent_urls' => array( 'home', 'siteurl' ) );
        $fail = static function ( $sql ) {
            return false !== strpos( $sql, "DELETE FROM `rt_options` WHERE option_name = 'home'" ) ? 'DELETE FROM mainwp_missing_url_fixture' : $sql;
        };
        $restore = new ReflectionMethod( new Testable_Restore_Task_Runner(), 'restore_connection_identity' );
        $restore->setAccessible( true );
        add_filter( 'query', $fail );
        try {
            $restore->invokeArgs( new Testable_Restore_Task_Runner(), array( &$state ) );
            $this->fail( 'A failed DELETE must prevent completion.' );
        } catch ( \MainWP\Child\MainWP_Exception $e ) {
            $this->assertStringContainsString( 'wp option delete home', $e->getMessage() );
            $this->assertFalse( wp_cache_get( 'alloptions', 'options' ) );
        } finally {
            remove_filter( 'query', $fail );
            $wpdb->query( 'DROP TABLE `rt_options`' );
        }
    }

    /**
     * Legacy or damaged capture rows must follow the write-back validation.
     */
    public function test_verification_ignores_malformed_legacy_rows() {
        $runner = new Testable_Restore_Task_Runner();
        $verify = new ReflectionMethod( MainWP_Child_Restore_Task_Runner::class, 'verify_destination_urls' );
        $verify->setAccessible( true );
        $state = array(
            'db_replay' => array( 'cursor' => array( 'tables' => array( 'options' => array( 'status' => 'ok' ) ) ) ),
            'identity_preserve' => array( 'invalid row', array(), array( 'option_name' => 'home' ), array( 'option_name' => 'home', 'option_value' => 'invalid without autoload' ) ),
        );
        $this->assertNull( $verify->invoke( $runner, $state ) );
        global $wpdb;
        $wpdb->query( 'CREATE TABLE `rt_options` LIKE ' . $wpdb->options );
        try {
            $this->assertSame( 1, $wpdb->insert( 'rt_options', array( 'option_name' => 'home', 'option_value' => 'https://destination.example', 'autoload' => 'yes' ) ) );
            $state['identity_preserve'][] = array( 'option_name' => 'home', 'option_value' => 'https://obsolete.example', 'autoload' => 'yes' );
            $state['identity_preserve'][] = array( 'option_name' => 'home', 'option_value' => 'https://destination.example', 'autoload' => 'yes' );
            $state['identity_preserve'][] = array( 'option_name' => 'home', 'option_value' => 'invalid without autoload' );
            $this->assertNull( $verify->invoke( $runner, $state ), 'Use the last valid capture exactly as write-back does.' );
        } finally {
            $wpdb->query( 'DROP TABLE `rt_options`' );
        }
    }

    /**
     * Stored and serialized links use configured destinations in an HTTP CLI context.
     *
     * @dataProvider stored_link_cases
     * @param string $source_home Source home URL.
     * @param string $source_site Source installation URL.
     * @param string $destination_site Destination installation URL.
     * @param string $link Stored source link.
     * @param string $expected Expected destination link.
     */
    public function test_stored_links_keep_destination_path_and_scheme( $source_home, $source_site, $destination_site, $link, $expected ) {
        global $wpdb;
        $original_home = get_option( 'home' );
        $original_site = get_option( 'siteurl' );
        $https = $_SERVER['HTTPS'] ?? null;
        unset( $_SERVER['HTTPS'] );
        update_option( 'home', 'https://destination.example' );
        update_option( 'siteurl', $destination_site );
        $wpdb->query( 'CREATE TABLE `rt_links` (id INT PRIMARY KEY, body LONGTEXT)' );
        try {
            $this->assertFalse( is_ssl(), 'Model WP-CLI without an HTTPS request context.' );
            $this->assertSame( 1, $wpdb->insert( 'rt_links', array( 'id' => 1, 'body' => serialize( array( 'post' => $link, 'navigation' => $link ) ) ) ) );
            $method = new ReflectionMethod( MainWP_Child_Restore_Task_Runner::class, 'url_pairs' );
            $method->setAccessible( true );
            $pairs = $method->invoke( new Testable_Restore_Task_Runner(), array( 'db_replay' => array( 'banner_info' => array( 'home_url' => $source_home, 'site_url' => $source_site, 'content_url' => $source_site . '/wp-content', 'upload_url' => $source_site . '/wp-content/uploads' ) ) ) );
            $outcome = ( new \MainWP\Child\MainWP_Backup_Url_Rewriter() )->rewrite_slice( array(), array( 'tables' => array( 'rt_links' ), 'pairs' => $pairs, 'exclude' => array() ), time() + 10 );
            $this->assertTrue( $outcome['done'] );
            $this->assertSame( array( 'post' => $expected, 'navigation' => $expected ), unserialize( $wpdb->get_var( 'SELECT body FROM `rt_links` WHERE id = 1' ) ) );
        } finally {
            $wpdb->query( 'DROP TABLE `rt_links`' );
            update_option( 'home', $original_home );
            update_option( 'siteurl', $original_site );
            if ( null === $https ) {
                unset( $_SERVER['HTTPS'] );
            } else {
                $_SERVER['HTTPS'] = $https;
            }
        }
    }

    /**
     * @return array Tie, longest-prefix and scheme regressions.
     */
    public static function stored_link_cases() {
        return array(
            'home wins equal source' => array( 'https://source.example', 'https://source.example', 'https://destination.example/wordpress', 'https://source.example/about', 'https://destination.example/about' ),
            'longest source first' => array( 'https://source.example', 'https://source.example/wp', 'https://destination.example/wordpress', 'https://source.example/wp/admin', 'https://destination.example/wordpress/admin' ),
            'HTTPS without URL flag' => array( '', 'https://source.example', 'https://destination.example', 'https://source.example/about', 'https://destination.example/about' ),
            'REST path uses home' => array( 'https://source.example', 'https://source.example/wp', 'https://destination.example', 'https://source.example/wp-json/wp/v2/posts', 'https://destination.example/wp-json/wp/v2/posts' ),
            'REST path with separate installation' => array( 'https://source.example', 'https://source.example/wp', 'https://destination.example/wordpress', 'https://source.example/wp-json/wp/v2/posts', 'https://destination.example/wp-json/wp/v2/posts' ),
            'post slug uses home' => array( 'https://source.example', 'https://source.example/wp', 'https://destination.example/wordpress', 'https://source.example/wp-tips/', 'https://destination.example/wp-tips/' ),
            'installation path relocates' => array( 'https://source.example', 'https://source.example/wp', 'https://destination.example', 'https://source.example/wp/wp-admin/', 'https://destination.example/wp-admin/' ),
            'unrelated host stays intact' => array( 'https://source.example', 'https://source.example/wp', 'https://destination.example', 'https://source.example.uk/wp-json/', 'https://source.example.uk/wp-json/' ),
            'Markdown home link' => array( 'https://source.example', 'https://source.example/wp', 'https://destination.example/wordpress', '[Home](https://source.example)', '[Home](https://destination.example)' ),
            'Markdown installation link' => array( 'https://source.example', 'https://source.example/wp', 'https://destination.example/wordpress', '[Admin](https://source.example/wp)', '[Admin](https://destination.example/wordpress)' ),
            'closing square bracket' => array( 'https://source.example', 'https://source.example/wp', 'https://destination.example', '[https://source.example]', '[https://destination.example]' ),
            'unquoted HTML link' => array( 'https://source.example', 'https://source.example/wp', 'https://destination.example', '<a href=https://source.example>Home</a>', '<a href=https://destination.example>Home</a>' ),
            'inline Markdown URL' => array( 'https://source.example', 'https://source.example/wp', 'https://destination.example', '`https://source.example`', '`https://destination.example`' ),
            'ampersand inside path segment' => array( 'https://source.example', 'https://source.example/wp', 'https://destination.example/wordpress', 'https://source.example/wp&tips', 'https://destination.example/wp&tips' ),
            'parenthesis inside path segment' => array( 'https://source.example', 'https://source.example/wp', 'https://destination.example/wordpress', 'https://source.example/wp)tips', 'https://destination.example/wp)tips' ),
            'square bracket inside path text' => array( 'https://source.example', 'https://source.example/wp', 'https://destination.example/wordpress', 'https://source.example/wp]tips', 'https://destination.example/wp]tips' ),
            'backtick inside path text' => array( 'https://source.example', 'https://source.example/wp', 'https://destination.example/wordpress', 'https://source.example/wp`tips', 'https://destination.example/wp`tips' ),
            'nested return URL' => array( 'https://source.example', 'https://source.example/wp', 'https://destination.example/wordpress', 'https://payment.example/?return=https://source.example&lang=en', 'https://payment.example/?return=https://destination.example&lang=en' ),
            'nested installation URL' => array( 'https://source.example', 'https://source.example/wp', 'https://destination.example/wordpress', 'https://payment.example/?return=https://source.example/wp&lang=en', 'https://payment.example/?return=https://destination.example/wordpress&lang=en' ),
            'HTML nested return URL' => array( 'https://source.example', 'https://source.example/wp', 'https://destination.example/wordpress', 'https://payment.example/?x=1&amp;return=https://source.example/wp&amp;lang=en', 'https://payment.example/?x=1&amp;return=https://destination.example/wordpress&amp;lang=en' ),
            'double encoded return separator' => array( 'https://source.example', 'https://source.example/wp', 'https://destination.example/wordpress', 'https://payment.example/?return=https://source.example&amp;amp;lang=en', 'https://payment.example/?return=https://destination.example&amp;amp;lang=en' ),
            'triple encoded return separator' => array( 'https://source.example', 'https://source.example/wp', 'https://destination.example/wordpress', 'https://payment.example/?return=https://source.example&amp;amp;amp;lang=en', 'https://payment.example/?return=https://destination.example&amp;amp;amp;lang=en' ),
            'Markdown sentence' => array( 'https://source.example', 'https://source.example/wp', 'https://destination.example/wordpress', '[Admin](https://source.example/wp).', '[Admin](https://destination.example/wordpress).' ),
            'bare home sentence punctuation' => array( 'https://source.example', 'https://source.example/wp', 'https://destination.example/wordpress', 'Visit https://source.example.', 'Visit https://destination.example.' ),
            'bare installation sentence punctuation' => array( 'https://source.example', 'https://source.example/wp', 'https://destination.example/wordpress', 'https://source.example/wp, https://source.example/wp; https://source.example/wp: https://source.example/wp!', 'https://destination.example/wordpress, https://destination.example/wordpress; https://destination.example/wordpress: https://destination.example/wordpress!' ),
            'unrelated port stays intact' => array( 'https://source.example', 'https://source.example/wp', 'https://destination.example/wordpress', 'https://source.example:8080/', 'https://source.example:8080/' ),
            'installation filename uses home' => array( 'https://source.example', 'https://source.example/wp', 'https://destination.example/wordpress', 'https://source.example/wp.php', 'https://destination.example/wp.php' ),
            'quoted punctuation stays in path' => array( 'https://source.example', 'https://source.example/wp', 'https://destination.example/wordpress', '<a href="https://source.example/wp!">One</a> <a href="https://source.example/wp)">Two</a>', '<a href="https://destination.example/wp!">One</a> <a href="https://destination.example/wp)">Two</a>' ),
            'unquoted punctuation stays in path' => array( 'https://source.example', 'https://source.example/wp', 'https://destination.example/wordpress', '<a href=https://source.example/wp!>One</a>', '<a href=https://destination.example/wp!>One</a>' ),
            'nested quoted punctuation stays in path' => array( 'https://source.example', 'https://source.example/wp', 'https://destination.example/wordpress', '<a href="https://payment.example/?return=https://source.example/wp!">One</a>', '<a href="https://payment.example/?return=https://destination.example/wp!">One</a>' ),
            'nested Markdown home URL' => array( 'https://source.example', 'https://source.example/wp', 'https://destination.example/wordpress', '[Continue](https://payment.example/?return=https://source.example)', '[Continue](https://payment.example/?return=https://destination.example)' ),
            'nested Markdown installation URL' => array( 'https://source.example', 'https://source.example/wp', 'https://destination.example/wordpress', '[Continue](https://payment.example/?return=https://source.example/wp)', '[Continue](https://payment.example/?return=https://destination.example/wordpress)' ),
            'nested Markdown literal punctuation' => array( 'https://source.example', 'https://source.example/wp', 'https://destination.example/wordpress', '[Continue](https://payment.example/?return=https://source.example/wp!)', '[Continue](https://payment.example/?return=https://destination.example/wp!)' ),
            'balanced nested Markdown parentheses' => array( 'https://source.example', 'https://source.example/wp', 'https://destination.example/wordpress', '[Continue](https://payment.example/(checkout?return=https://source.example/wp))', '[Continue](https://payment.example/(checkout?return=https://destination.example/wp))' ),
            'escaped nested Markdown parentheses' => array( 'https://source.example', 'https://source.example/wp', 'https://destination.example/wordpress', '[Continue](https://payment.example/\(checkout?return=https://source.example/wp)', '[Continue](https://payment.example/\(checkout?return=https://destination.example/wordpress)' ),
        );
    }

    /**
     * Full banner replacements must not rescan URLs produced by another pair.
     *
     * @dataProvider destination_prefix_cases
     * @param string $suffix Destination extends the source URL.
     * @param bool   $split Keep installation/content/uploads unchanged while home moves.
     */
    public function test_full_banner_replacements_are_simultaneous( $suffix, $split = false ) {
        global $wpdb;
        $scheme = wp_parse_url( WP_CONTENT_URL, PHP_URL_SCHEME );
        $source = $scheme . '://a.example';
        $destination = $source . $suffix;
        $asset_destination = $split ? $source : $destination;
        $home = get_option( 'home' );
        $site = get_option( 'siteurl' );
        $content_filter = static function () use ( $asset_destination ) { return $asset_destination . '/wp-content'; };
        $upload_filter = static function ( $uploads ) use ( $asset_destination ) {
            $uploads['baseurl'] = $asset_destination . '/wp-content/uploads';
            return $uploads;
        };
        update_option( 'home', $destination );
        update_option( 'siteurl', $asset_destination );
        add_filter( 'content_url', $content_filter );
        add_filter( 'upload_dir', $upload_filter );
        $wpdb->query( 'CREATE TABLE `rt_links` (id INT PRIMARY KEY, body LONGTEXT)' );
        try {
            $from = array( 'page' => $source . '/about', 'theme' => $source . '/wp-content/themes/t/logo.png', 'media' => $source . '/wp-content/uploads/i.png', 'nested' => serialize( array( $source . '/about' ) ) );
            $to = array( 'page' => $destination . '/about', 'theme' => $asset_destination . '/wp-content/themes/t/logo.png', 'media' => $asset_destination . '/wp-content/uploads/i.png', 'nested' => serialize( array( $destination . '/about' ) ) );
            $this->assertSame( 1, $wpdb->insert( 'rt_links', array( 'id' => 1, 'body' => serialize( $from ) ) ) );
            $method = new ReflectionMethod( MainWP_Child_Restore_Task_Runner::class, 'url_pairs' );
            $method->setAccessible( true );
            $pairs = $method->invoke( new Testable_Restore_Task_Runner(), array( 'db_replay' => array( 'banner_info' => array( 'home_url' => $source, 'site_url' => $source, 'content_url' => $source . '/wp-content', 'upload_url' => $source . '/wp-content/uploads' ) ) ) );
            $outcome = ( new \MainWP\Child\MainWP_Backup_Url_Rewriter() )->rewrite_slice( array(), array( 'tables' => array( 'rt_links' ), 'pairs' => $pairs, 'exclude' => array() ), time() + 10 );
            $this->assertTrue( $outcome['done'] );
            $this->assertSame( serialize( $to ), $wpdb->get_var( 'SELECT body FROM `rt_links` WHERE id = 1' ) );
        } finally {
            remove_filter( 'content_url', $content_filter );
            remove_filter( 'upload_dir', $upload_filter );
            update_option( 'home', $home );
            update_option( 'siteurl', $site );
            $wpdb->query( 'DROP TABLE `rt_links`' );
        }
    }

    /**
     * @return array Prefix-extension and same-domain cases.
     */
    public static function destination_prefix_cases() {
        return array( 'subdirectory' => array( '/staging' ), 'longer host' => array( '.uk' ), 'same domain' => array( '' ), 'separate public home' => array( '/blog', true ) );
    }

    /**
     * The configured content scheme survives a contrary request scheme.
     */
    public function test_full_banner_keeps_configured_content_scheme() {
        global $wpdb;
        $https = $_SERVER['HTTPS'] ?? null;
        $port = $_SERVER['SERVER_PORT'] ?? null;
        $configured_https = 'https' === wp_parse_url( WP_CONTENT_URL, PHP_URL_SCHEME );
        $_SERVER['HTTPS'] = $configured_https ? 'off' : 'on';
        $_SERVER['SERVER_PORT'] = $configured_https ? '80' : '443';
        $upload = wp_upload_dir();
        $wpdb->query( 'CREATE TABLE `rt_links` (id INT PRIMARY KEY, body LONGTEXT)' );
        try {
            $values = array( WP_CONTENT_URL . '/themes/t/logo.png', $upload['baseurl'] . '/i.png' );
            $this->assertSame( 1, $wpdb->insert( 'rt_links', array( 'id' => 1, 'body' => serialize( $values ) ) ) );
            $this->assertSame( ! $configured_https, is_ssl() );
            $method = new ReflectionMethod( MainWP_Child_Restore_Task_Runner::class, 'url_pairs' );
            $method->setAccessible( true );
            $pairs = $method->invoke( new Testable_Restore_Task_Runner(), array( 'db_replay' => array( 'banner_info' => array( 'home_url' => get_option( 'home' ), 'site_url' => get_option( 'siteurl' ), 'content_url' => WP_CONTENT_URL, 'upload_url' => $upload['baseurl'] ) ) ) );
            $outcome = ( new \MainWP\Child\MainWP_Backup_Url_Rewriter() )->rewrite_slice( array(), array( 'tables' => array( 'rt_links' ), 'pairs' => $pairs, 'exclude' => array() ), time() + 10 );
            $this->assertTrue( $outcome['done'] );
            $this->assertSame( 0, $outcome['failed_updates'] );
            $this->assertSame( serialize( $values ), $wpdb->get_var( 'SELECT body FROM `rt_links` WHERE id = 1' ) );
        } finally {
            $wpdb->query( 'DROP TABLE `rt_links`' );
            if ( null === $https ) { unset( $_SERVER['HTTPS'] ); } else { $_SERVER['HTTPS'] = $https; }
            if ( null === $port ) { unset( $_SERVER['SERVER_PORT'] ); } else { $_SERVER['SERVER_PORT'] = $port; }
        }
    }
}
