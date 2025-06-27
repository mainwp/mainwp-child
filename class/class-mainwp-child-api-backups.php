<?php
/**
 * MainWP Child Site Api Backups
 *
 * Manages MainWP API Backups child site actions when needed.
 *
 * @package MainWP\Child
 */

namespace MainWP\Child;

/**
 * Class MainWP_Child_Api_Backups
 *
 * This class handles all the MainWP API Backups child site actions when needed.
 *
 * @package MainWP\Child
 */
class MainWP_Child_Api_Backups {

    /**
     * Public variable to state if supported plugin is installed on the child site.
     *
     * @var bool If supported plugin is installed, return true, if not, return false.
     */
    public $is_plugin_installed = false;

    /**
     * Public static variable to hold the single instance of the class.
     *
     * @var mixed Default null
     */
    protected static $instance = null;

    /**
     * Method instance()
     *
     * Create a public static instance.
     *
     * @return mixed Class instance.
     */
    public static function instance() {
        if ( null === static::$instance ) {
            static::$instance = new self();
        }

        return static::$instance;
    }

    /**
     * MainWP_Child_Api_Backups constructor.
     *
     * Run any time class is called.
     */
    public function __construct() {
        // Constructor.
    }

    /**
     * Create a backup of the database for the given child site.
     *
     * @return void
     */
    public function api_backups_mysqldump() {

        // WordPress DB credentials.
        $database_name = DB_NAME;
        $user          = DB_USER;
        $pass          = DB_PASSWORD;

        // Remove ":" & all numbers from "Localhost:3306".
        $host = str_replace( ':', '', preg_replace( '/\d/', '', DB_HOST ) );

        // Get Site URL.
        $site_url = str_replace( '/', '.', preg_replace( '#^https?://#i', '', get_bloginfo( 'url' ) ) );

        // Create a timestamp.
        $current_date_time = current_datetime();
        $current_date_time = $current_date_time->format( 'm-d-Y_H.i.s.A' );

        // Build the uploads directory.
        $wp_get_upload_dir = wp_get_upload_dir();
        $wp_upload_dir     = $wp_get_upload_dir['basedir'] . '/mainwp/api_db_backups/';

        // Build the full path to the backup file.
        $gzip_full_path = $wp_upload_dir . $database_name . '_' . $site_url . '_' . $current_date_time . '.sql.gz';

        // Create the directory if it doesn't exist.
        if ( ! file_exists( $wp_upload_dir ) ) { //phpcs:ignore
            mkdir( $wp_upload_dir, 0755, true ); //phpcs:ignore
        }

        if ( function_exists( 'exec' ) ) {
            // Create the backup file. hide from logs ( password ).
            exec( "mysqldump --user={$user} --password='{$pass}' --host={$host} {$database_name} | gzip > {$gzip_full_path}", $output, $result ); //phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.system_calls_exec
        }

        // Check if the backup was successful.
        if ( 0 === $result ) {
            // Success.
            MainWP_Helper::write(
                array(
                    'result' => 'GOOD',
                    'output' => $output,
                    'res'    => $result,
                )
            );
        } else {
            // Error.
            MainWP_Helper::write(
                array(
                    'result' => 'ERROR',
                    'output' => $output,
                    'res'    => $result,
                )
            );
        }
    }
}
