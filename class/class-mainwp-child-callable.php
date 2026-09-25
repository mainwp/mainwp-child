<?php
/**
 * MainWP Callable Functions
 *
 * Manage functions that can be executed on the child site.
 *
 * @package MainWP\Child
 */

namespace MainWP\Child;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

// phpcs:disable WordPress.WP.AlternativeFunctions --  to use external code, third party credit.

/**
 * Class MainWP_Child_Callable
 *
 * Manage functions that can be executed on the child site.
 */
class MainWP_Child_Callable { //phpcs:ignore -- NOSONAR - multi methods.

    /**
     * Maximum serialized Post Dripper v2 request.
     *
     * Post Dripper deliveries go through the same content normalizer as Post Plus, so they carry
     * the same field limits; 256 KB rejected legal non-Latin posts here, because a 200 KB CJK body
     * doubles to 400 KB once every character is JSON-escaped to \uXXXX.
     */
    const POST_DRIPPER_REQUEST_MAX_BYTES = 2097152;

    /**
     * Maximum serialized Post Plus v2 request.
     *
     * The handler's own field limits add up to roughly 246 KB, and JSON encoding can expand a
     * legal payload sixfold because every control byte becomes \u00XX, so anything below ~1.5 MB
     * would drop valid posts here before the handler ever saw them.
     */
    const POST_PLUS_REQUEST_MAX_BYTES = 2097152;

    /**
     * Public static variable to hold the single instance of the class.
     *
     * @var mixed Default null
     */
    protected static $instance = null;

    /**
     * Private variable to hold the array of all callable functions.
     *
     * @var array Callable functions.
     */
    private $callableFunctions = array(
        'stats'                         => 'get_site_stats',
        'upgrade'                       => 'upgrade_wp',
        'newpost'                       => 'new_post',
        'deactivate'                    => 'deactivate',
        'newuser'                       => 'new_user',
        'newadminpassword'              => 'new_admin_password',
        'installplugintheme'            => 'install_plugin_theme',
        'upgradeplugintheme'            => 'upgrade_plugin_theme',
        'upgradetranslation'            => 'upgrade_translation',
        'backup'                        => 'backup',
        'backup_preflight'              => 'backup_preflight',
        'backup_checkpid'               => 'backup_checkpid',
        'backup_task_status'            => 'backup_task_status',
        'backup_task_create'            => 'backup_task_create',
        'backup_task_run'               => 'backup_task_run',
        'backup_task_cancel'            => 'backup_task_cancel',
        'backup_task_prune'             => 'backup_task_prune',
        'backup_download_token'         => 'backup_download_token',
        'backup_manifest_chunk'         => 'backup_manifest_chunk',
        'restore_preflight'             => 'restore_preflight',
        'restore_task_create'           => 'restore_task_create',
        'restore_task_run'              => 'restore_task_run',
        'restore_task_status'           => 'restore_task_status',
        'restore_task_cancel'           => 'restore_task_cancel',
        'restore_artifact_chunk'        => 'restore_artifact_chunk',
        'cloneinfo'                     => 'cloneinfo',
        'security'                      => 'get_security_stats',
        'securityFix'                   => 'do_security_fix',
        'securityUnFix'                 => 'do_security_un_fix',
        'post_action'                   => 'post_action',
        'get_all_posts'                 => 'get_all_posts',
        'get_all_posts_v2'              => 'get_all_posts_v2',
        'post_dripper_capabilities_v2'  => 'post_dripper_capabilities_v2',
        'post_plus_capabilities_v2'     => 'post_plus_capabilities_v2',
        'comment_action'                => 'comment_action',
        'comment_bulk_action'           => 'comment_bulk_action',
        'get_all_comments'              => 'get_all_comments',
        'get_all_themes'                => 'get_all_themes',
        'theme_action'                  => 'theme_action',
        'get_all_plugins'               => 'get_all_plugins',
        'plugin_action'                 => 'plugin_action',
        'get_all_pages'                 => 'get_all_pages',
        'termageddon_page_v2_get'       => 'termageddon_page_v2_get',
        'termageddon_page_v2_delete'    => 'termageddon_page_v2_delete',
        'get_all_users'                 => 'get_all_users',
        'user_action'                   => 'user_action',
        'search_users'                  => 'search_users',
        'maintenance_site'              => 'maintenance_site',
        'branding_child_plugin'         => 'branding_child_plugin',
        'code_snippet'                  => 'code_snippet',
        'uploader_action'               => 'uploader_action',
        'uploader_preflight_v2'         => 'uploader_preflight_v2',
        'uploader_deploy_v2'            => 'uploader_deploy_v2',
        'uploader_deployment_state_v2'  => 'uploader_deployment_state_v2',
        'uploader_rollback_v2'          => 'uploader_rollback_v2',
        'early_access_release_v2'       => 'early_access_release_v2',
        'favorites_package_state_v2'    => 'favorites_package_state_v2',
        'favorites_install_verified_v2' => 'favorites_install_verified_v2',
        'virusdie_sync_install_v1'      => 'virusdie_sync_install_v1',
        'wordpress_seo'                 => 'wordpress_seo',
        'client_report'                 => 'client_report',
        'createBackupPoll'              => 'backup_poll',
        'page_speed'                    => 'page_speed',
        'woo_com_status'                => 'woo_com_status',
        'links_checker'                 => 'links_checker',
        'wordfence'                     => 'wordfence',
        'delete_backup'                 => 'delete_backup',
        'update_values'                 => 'update_child_values',
        'ithemes'                       => 'ithemes',
        'updraftplus'                   => 'updraftplus',
        'backup_wp'                     => 'backup_wp',
        'backwpup'                      => 'backwpup',
        'wp_rocket'                     => 'wp_rocket',
        'settings_tools'                => 'settings_tools',
        'skeleton_key'                  => 'bulk_settings_manager', // deprecated.
        'bulk_settings_manager'         => 'bulk_settings_manager',
        'custom_post_type'              => 'custom_post_type',
        'backup_buddy'                  => 'backup_buddy',
        'get_site_icon'                 => 'get_site_icon',
        'vulner_checker'                => 'vulner_checker',
        'wp_staging'                    => 'wp_staging',
        'disconnect'                    => 'disconnect',
        'time_capsule'                  => 'time_capsule',
        'extra_excution'                => 'extra_execution', // deprecated!
        'extra_execution'               => 'extra_execution',
        'wpvivid_backuprestore'         => 'wpvivid_backuprestore',
        'check_abandoned'               => 'check_abandoned',
        'wp_seopress'                   => 'wp_seopress',
        'db_updater'                    => 'db_updater',
        'cache_purge_action'            => 'cache_purge_action',
        'jetpack_protect'               => 'jetpack_protect',
        'jetpack_scan'                  => 'jetpack_scan',
        'delete_actions'                => 'delete_actions',
        'verify_action'                 => 'verify_action',
        'api_backups_mysqldump'         => 'api_backups_mysqldump',
        'patchstack'                    => 'patchstack',
        'password_policy_settings'      => 'password_policy_settings',
        'clean_up_child_logs'           => 'clean_up_child_logs',
        'process_premium_updates'       => 'process_premium_updates',
    );

    /**
     * Private variable to hold the array of all callable functions that don't require regularl authentication.
     *
     * @var array Callable functions.
     */
    private $callableFunctionsNoAuth = array(
        'stats' => 'get_site_stats_no_auth',
    );

    /**
     * Method get_class_name()
     *
     * Get class name.
     *
     * @return string __CLASS__ Class name.
     */
    public static function get_class_name() {
        return __CLASS__;
    }

    /**
     * MainWP_Child_Callable constructor.
     *
     * Run any time class is called.
     */
    public function __construct() {
    }

    /**
     * Method instance()
     *
     * Create a public static instance.
     *
     * @return mixed Class instance.
     */
    public static function get_instance() {
        if ( null === static::$instance ) {
            static::$instance = new self();
        }
        return static::$instance;
    }

    /**
     * Method init_call_functions()
     *
     * Initiate callable functions.
     *
     * @param bool $auth If true, regular authentication is required.
     *
     * @uses \MainWP\Child\MainWP_Helper::instance()->error()
     * @uses \MainWP\Child\MainWP_Utility::handle_fatal_error()
     * @uses \MainWP\Child\MainWP_Utility::fix_for_custom_themes()
     */
    public function init_call_functions( $auth = false ) {
        $callable         = false;
        $callable_no_auth = false;
        $call_func        = false;
        // phpcs:disable WordPress.Security.NonceVerification
        // check if function is callable.
        if ( isset( $_POST['function'] ) ) {
            $call_func        = isset( $_POST['function'] ) ? sanitize_text_field( wp_unslash( $_POST['function'] ) ) : '';
            $callable         = $this->is_callable_function( $call_func ); // check callable func.
            $callable_no_auth = $this->is_callable_function_no_auth( $call_func ); // check callable no auth func.
        }

        // Fire off the called function.
        if ( $auth && isset( $_POST['function'] ) && $callable ) {
            MainWP_Helper::maybe_set_doing_cron();
            MainWP_Utility::handle_fatal_error();
            MainWP_Utility::fix_for_custom_themes();
            $this->call_function( $call_func );
        } elseif ( isset( $_POST['function'] ) && $callable_no_auth ) {

            MainWP_Helper::maybe_set_doing_cron();

            MainWP_Utility::fix_for_custom_themes();
            $this->call_function_no_auth( $call_func );
        } elseif ( isset( $_POST['function'] ) && isset( $_POST['mainwpsignature'] ) && ! $callable && ! $callable_no_auth ) {
            MainWP_Helper::instance()->error( esc_html__( 'Required version has not been detected. Please, make sure that you are using the latest version of the MainWP Child plugin on your site.', 'mainwp-child' ), 'child_plugin_incompatible' );
        }
        // phpcs:enable
    }

    /**
     * Method is_callable_function()
     *
     * Check if the function is the list of callable functions.
     *
     * @param string $func Contains the name of the function to check.
     *
     * @return bool If callable, return true, if not, return false.
     */
    public function is_callable_function( $func ) {
        if ( isset( $this->callableFunctions[ $func ] ) ) {
            return true;
        }
        return false;
    }

    /**
     * Method is_callable_function_no_auth()
     *
     * Check if the function is the list of callable functions that don't require regular authentication.
     *
     * @param string $func Contains the name of the function to check.
     *
     * @return bool If callable, return true, if not, return false.
     */
    public function is_callable_function_no_auth( $func ) {
        if ( isset( $this->callableFunctionsNoAuth[ $func ] ) ) {
            return true;
        }
        return false;
    }

    /**
     * Method call_function()
     *
     * Call ceratin function.
     *
     * @param string $func Contains the name of the function to call.
     */
    public function call_function( $func ) {
        if ( $this->is_callable_function( $func ) ) {
            call_user_func( array( $this, $this->callableFunctions[ $func ] ) );
        }
    }

    /**
     * Method call_function_no_auth()
     *
     * Call ceratin function without regular authentication if the function is in the $callableFunctionsNoAuth list.
     *
     * @param string $func Contains the name of the function to call.
     */
    public function call_function_no_auth( $func ) {
        if ( $this->is_callable_function_no_auth( $func ) ) {
            call_user_func( array( $this, $this->callableFunctionsNoAuth[ $func ] ) );
        }
    }

    /**
     * Method get_site_stats()
     *
     * Fire off the get_site_stats() function.
     *
     * @uses \MainWP\Child\MainWP_Child_Stats::get_site_stats()
     */
    public function get_site_stats() {
        MainWP_Child_Stats::get_instance()->get_site_stats( array(), true, array( 'site_stats' => true ) );
    }

    /**
     * Method get_site_stats_no_auth()
     *
     * Fire off the get_site_stats_no_auth() function.
     *
     * @uses \MainWP\Child\MainWP_Child_Stats::get_site_stats_no_auth()
     */
    public function get_site_stats_no_auth() {
        MainWP_Child_Stats::get_instance()->get_site_stats_no_auth();
    }

    /**
     * Method install_plugin_theme()
     *
     * Fire off the install_plugin_theme() function.
     *
     * @uses \MainWP\Child\MainWP_Child_Install::install_plugin_theme()
     */
    public function install_plugin_theme() {
        MainWP_Child_Install::get_instance()->install_plugin_theme();
    }

    /**
     * Method upgrade_wp()
     *
     * Fire off the upgrade_wp() function.
     *
     * @uses \MainWP\Child\MainWP_Child_Updates::upgrade_wp()
     */
    public function upgrade_wp() {
        MainWP_Child_Updates::get_instance()->upgrade_wp();
    }

    /**
     * Method upgrade_translation()
     *
     * Fire off the upgrade_translation() function.
     *
     * @uses \MainWP\Child\MainWP_Child_Updates::upgrade_translation()
     */
    public function upgrade_translation() {
        MainWP_Child_Updates::get_instance()->upgrade_translation();
    }

    /**
     * Method upgrade_plugin_theme()
     *
     * Fire off the upgrade_plugin_theme() function.
     *
     * @uses \MainWP\Child\MainWP_Child_Updates::upgrade_plugin_theme()
     */
    public function upgrade_plugin_theme() {
        MainWP_Child_Updates::get_instance()->upgrade_plugin_theme();
    }

    /**
     * Method theme_action()
     *
     * Fire off the theme_action() function.
     *
     * @uses \MainWP\Child\MainWP_Child_Install::theme_action()
     */
    public function theme_action() {
        MainWP_Child_Install::get_instance()->theme_action();
    }

    /**
     * Method plugin_action()
     *
     * Fire off the plugin_action() function.
     *
     * @uses \MainWP\Child\MainWP_Child_Install::plugin_action()
     */
    public function plugin_action() {
        MainWP_Child_Install::get_instance()->plugin_action();
    }

    /**
     * Method get_all_plugins()
     *
     * Fire off the get_all_plugins() function.
     *
     * @uses \MainWP\Child\MainWP_Child_Stats::get_all_plugins()
     */
    public function get_all_plugins() {
        MainWP_Child_Stats::get_instance()->get_all_plugins();
    }

    /**
     * Method get_all_themes()
     *
     * Fire off the get_all_themes() function.
     *
     * @uses \MainWP\Child\MainWP_Child_Stats::get_all_themes()
     */
    public function get_all_themes() {
        MainWP_Child_Stats::get_instance()->get_all_themes();
    }

    /**
     * Method get_all_users()
     *
     * Fire off the get_all_users() function.
     *
     * @uses \MainWP\Child\MainWP_Child_Users::get_all_users()
     */
    public function get_all_users() {
        MainWP_Child_Users::get_instance()->get_all_users();
    }

    /**
     * Method user_action()
     *
     * Fire off the user_action() function.
     *
     * @uses \MainWP\Child\MainWP_Child_Users::user_action()
     */
    public function user_action() {
        MainWP_Child_Users::get_instance()->user_action();
    }

    /**
     * Method search_users()
     *
     * Fire off the search_users() function.
     *
     * @uses \MainWP\Child\MainWP_Child_Users::search_users()
     */
    public function search_users() {
        MainWP_Child_Users::get_instance()->search_users();
    }

    /**
     * Method get_all_posts()
     *
     * Fire off the get_all_posts() function.
     *
     * @uses \MainWP\Child\MainWP_Child_Posts::get_all_posts()
     */
    public function get_all_posts() {
        MainWP_Child_Posts::get_instance()->get_all_posts();
    }

    /**
     * Fire the typed paginated post extraction callable.
     */
    public function get_all_posts_v2() {
        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Authenticated MainWP callable.
        if ( ! isset( $_POST['request'] ) || ! is_string( $_POST['request'] ) ) {
            MainWP_Helper::write( MainWP_Child_Posts::get_instance()->get_all_posts_v2( null ) );
        }

        // phpcs:ignore WordPress.Security.NonceVerification.Missing,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Closed JSON is validated by the handler.
        $raw = wp_unslash( $_POST['request'] );
        if ( '' === $raw || 65536 < strlen( $raw ) ) {
            MainWP_Helper::write( MainWP_Child_Posts::get_instance()->get_all_posts_v2( null ) );
        }

        MainWP_Helper::write( MainWP_Child_Posts::get_instance()->get_all_posts_v2( json_decode( $raw, true ) ) );
    }

    /**
     * Fire the Post Dripper capability negotiation callable.
     */
    public function post_dripper_capabilities_v2() {
        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Authenticated MainWP callable.
        if ( ! isset( $_POST['request'] ) || ! is_string( $_POST['request'] ) ) {
            MainWP_Helper::write( MainWP_Child_Posts::get_instance()->post_dripper_capabilities_v2( null ) );
        }

        // phpcs:ignore WordPress.Security.NonceVerification.Missing,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Closed JSON is validated by the handler.
        $raw = wp_unslash( $_POST['request'] );
        if ( '' === $raw || self::POST_DRIPPER_REQUEST_MAX_BYTES < strlen( $raw ) ) {
            MainWP_Helper::write( MainWP_Child_Posts::get_instance()->post_dripper_capabilities_v2( null ) );
        }

        MainWP_Helper::write( MainWP_Child_Posts::get_instance()->post_dripper_capabilities_v2( json_decode( $raw, true ) ) );
    }

    /**
     * Fire the Post Plus capability negotiation callable.
     */
    public function post_plus_capabilities_v2() {
        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Authenticated MainWP callable.
        if ( ! isset( $_POST['request'] ) || ! is_string( $_POST['request'] ) ) {
            MainWP_Helper::write( MainWP_Child_Posts::get_instance()->post_plus_capabilities_v2( null ) );
        }

        // phpcs:ignore WordPress.Security.NonceVerification.Missing,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Closed JSON is validated by the handler.
        $raw = wp_unslash( $_POST['request'] );
        if ( '' === $raw || self::POST_PLUS_REQUEST_MAX_BYTES < strlen( $raw ) ) {
            MainWP_Helper::write( MainWP_Child_Posts::get_instance()->post_plus_capabilities_v2( null ) );
        }

        MainWP_Helper::write( MainWP_Child_Posts::get_instance()->post_plus_capabilities_v2( json_decode( $raw, true ) ) );
    }

    /**
     * Method get_all_pages()
     *
     * Fire off the get_all_pages() function.
     *
     * @uses \MainWP\Child\MainWP_Child_Posts::get_all_pages()
     */
    public function get_all_pages() {
        MainWP_Child_Posts::get_instance()->get_all_pages();
    }

    /**
     * Read one exact marker-bound Termageddon page.
     */
    public function termageddon_page_v2_get() {
        ( new MainWP_Child_Termageddon() )->handle_get();
    }

    /**
     * Delete one exact marker-bound Termageddon page.
     */
    public function termageddon_page_v2_delete() {
        ( new MainWP_Child_Termageddon() )->handle_delete();
    }

    /**
     * Method comment_action()
     *
     * Fire off the comment_action() function.
     *
     * @uses \MainWP\Child\MainWP_Child_Comments::comment_action()
     */
    public function comment_action() {
        MainWP_Child_Comments::get_instance()->comment_action();
    }

    /**
     * Method get_all_comments()
     *
     * Fire off the get_all_comments() function.
     *
     * @uses \MainWP\Child\MainWP_Child_Comments::get_all_comments()
     */
    public function get_all_comments() {
        MainWP_Child_Comments::get_instance()->get_all_comments();
    }

    /**
     * Method comment_bulk_action()
     *
     * Fire off the comment_bulk_action() function.
     *
     * @uses \MainWP\Child\MainWP_Child_Comments::comment_bulk_action()
     */
    public function comment_bulk_action() {
        MainWP_Child_Comments::get_instance()->comment_bulk_action();
    }

    /**
     * Method maintenance_site()
     *
     * Fire off the maintenance_site() function.
     *
     * @uses \MainWP\Child\MainWP_Child_Maintenance::maintenance_site()
     */
    public function maintenance_site() {
        MainWP_Child_Maintenance::get_instance()->maintenance_site();
    }

    /**
     * Method new_post()
     *
     * Fire off the new_post() function.
     *
     * @uses \MainWP\Child\MainWP_Child_Posts::new_post()
     */
    public function new_post() {
        MainWP_Child_Posts::get_instance()->new_post();
    }

    /**
     * Method post_action()
     *
     * Fire off the post_action() function.
     *
     * @uses \MainWP\Child\MainWP_Child_Posts::post_action()
     */
    public function post_action() {
        MainWP_Child_Posts::get_instance()->post_action();
    }

    /**
     * Method new_admin_password()
     *
     * Fire off the new_admin_password() function.
     *
     * @uses \MainWP\Child\MainWP_Child_Users::new_admin_password()
     */
    public function new_admin_password() {
        MainWP_Child_Users::get_instance()->new_admin_password();
    }

    /**
     * Method new_user()
     *
     * Fire off the new_user() function.
     *
     * @uses \MainWP\Child\MainWP_Child_Users::new_user()
     */
    public function new_user() {
        MainWP_Child_Users::get_instance()->new_user();
    }

    /**
     * Method cloneinfo()
     *
     * Fire off the cloneinfo() function.
     *
     * @uses \MainWP\Child\MainWP_Helper::write()
     */
    public function cloneinfo() {

        /**
         * WordPress DB Table Prefix.
         *
         * @global string
         */
        global $table_prefix;

        $information['dbCharset']    = DB_CHARSET;
        $information['dbCollate']    = DB_COLLATE;
        $information['table_prefix'] = $table_prefix;
        $information['site_url']     = get_option( 'site_url' );
        $information['home']         = get_option( 'home' );

        MainWP_Helper::write( $information );
    }

    /**
     * Method backup_poll()
     *
     * Fire off the backup_poll() function.
     *
     * @uses \MainWP\Child\MainWP_Backup::backup_poll()
     */
    public function backup_poll() {
        MainWP_Backup::get()->backup_poll();
    }

    /**
     * Method backup_checkpid()
     *
     * Fire off the backup_checkpid() function.
     *
     * @uses \MainWP\Child\MainWP_Backup::backup_checkpid()
     */
    public function backup_checkpid() {
        MainWP_Backup::get()->backup_checkpid();
    }

    /**
     * Method backup()
     *
     * Fire off the backup() function.
     *
     * @param bool $write Whether or not to execute MainWP_Helper::write(), Default: true.
     *
     * @return array Action result.
     *
     * @uses \MainWP\Child\MainWP_Backup::backup()
     */
    public function backup( $write = true ) {
        return MainWP_Backup::get()->backup( $write );
    }

    /**
     * Method backup_preflight()
     *
     * Run the pre-backup environment validation and send the
     * readiness report to the Dashboard. The report includes the backup
     * directory secret; this response only travels inside the authenticated
     * Dashboard connection.
     *
     * @uses \MainWP\Child\MainWP_Backup::check_backup_readiness()
     */
    public function backup_preflight() {
        // The restore capability probe runs on the path a user is waiting on and reads only the capability keys.
        $capabilities_only = isset( $_POST['capabilities_only'] ) && '1' === sanitize_text_field( wp_unslash( $_POST['capabilities_only'] ) ); // phpcs:ignore WordPress.Security.NonceVerification -- authenticated MainWP request.
        try {
            $information = MainWP_Backup::get()->check_backup_readiness( $capabilities_only );
        } catch ( MainWP_Exception $e ) {
            MainWP_Helper::instance()->error( $e->getMessage(), $e->get_message_error_code() );
            return;
        }
        MainWP_Helper::write( $information );
    }

    /**
     * Method backup_task_status()
     *
     * Status read-back for a spine task. Applies the wall-clock
     * deadline on every poll, so a task stuck on a low-traffic child whose
     * cron never fires still goes terminal. The terminal phase observed here
     * is what the Dashboard turns into its completion hook.
     *
     * @uses \MainWP\Child\MainWP_Child_Backup_Task
     */
    public function backup_task_status() {
        $task_id = isset( $_POST['task_id'] ) ? sanitize_text_field( wp_unslash( $_POST['task_id'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification -- authenticated MainWP request.
        if ( '' === $task_id ) {
            MainWP_Helper::instance()->error( esc_html__( 'No backup task specified. The Dashboard did not send a task id with the status request.', 'mainwp-child' ) );
            return;
        }

        $spine = MainWP_Child_Backup_Task::instance();
        $spine->install();
        $spine->enforce_wall_clock_deadline( $task_id );

        $task = $spine->get_task( $task_id );
        if ( null === $task ) {
            MainWP_Helper::instance()->error( esc_html__( 'Backup task not found. It may have been cleaned up or was never created on this site.', 'mainwp-child' ) );
            return;
        }

        MainWP_Helper::write( $spine->status_payload( $task ) );
    }

    /**
     * Method backup_task_create()
     *
     * Start a new backup task on the resumable machine and hand the task id
     * back to the Dashboard, which then drives the work one bounded slice
     * per continuation call. The backup params travel into the task's resume
     * cursor here, so later slices never read the request again.
     *
     * @uses \MainWP\Child\MainWP_Child_Backup_Task_Runner::create_task_for_params()
     */
    public function backup_task_create() {
        // phpcs:disable WordPress.Security.NonceVerification -- authenticated MainWP request.
        $type    = isset( $_POST['type'] ) ? sanitize_text_field( wp_unslash( $_POST['type'] ) ) : '';
        $exclude = isset( $_POST['exclude'] ) ? explode( ',', wp_unslash( $_POST['exclude'] ) ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- sanitized in assemble_exclude_list().

        $excludebackup = isset( $_POST['excludebackup'] ) && '1' === $_POST['excludebackup'];
        $excludecache  = isset( $_POST['excludecache'] ) && '1' === $_POST['excludecache'];

        $file = '';
        if ( ! empty( $_POST['f'] ) ) {
            $file = MainWP_Helper::sanitize_filename( wp_unslash( $_POST['f'] ) ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- sanitized via MainWP_Helper::sanitize_filename().
        }

        $params = array(
            'ext'                => isset( $_POST['ext'] ) ? sanitize_text_field( wp_unslash( $_POST['ext'] ) ) : 'zip',
            'file'               => $file,
            'excludes'           => MainWP_Backup::get()->assemble_exclude_list( $exclude, $excludebackup, $excludecache ),
            'excludezip'         => isset( $_POST['excludezip'] ) && '1' === $_POST['excludezip'],
            'excludenonwp'       => isset( $_POST['excludenonwp'] ) && '1' === $_POST['excludenonwp'],
            'file_descriptors'   => isset( $_POST['file_descriptors'] ) ? intval( wp_unslash( $_POST['file_descriptors'] ) ) : 0,
            'loadFilesBeforeZip' => isset( $_POST['loadFilesBeforeZip'] ) ? sanitize_text_field( wp_unslash( $_POST['loadFilesBeforeZip'] ) ) : true,
            'emit_manifest'      => isset( $_POST['emit_manifest'] ) && '1' === $_POST['emit_manifest'],
        );

        if ( 'incremental' === $type ) {
            $raw_manifest = isset( $_POST['parent_manifest'] ) ? wp_unslash( $_POST['parent_manifest'] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- validated as strict base64 below.
            if ( strlen( $raw_manifest ) > 6 * 1024 * 1024 ) {
                MainWP_Helper::instance()->error( esc_html( MainWP_Backup_Error::message( MainWP_Backup_Error::ERR_MANIFEST_TOO_LARGE ) ), MainWP_Backup_Error::ERR_MANIFEST_TOO_LARGE );
                return;
            }
            if ( '' !== $raw_manifest && false === base64_decode( $raw_manifest, true ) ) { // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- strict-mode validation of the pushed manifest's wire encoding, not code.
                MainWP_Helper::instance()->error( esc_html( MainWP_Backup_Error::message( MainWP_Backup_Error::ERR_INCREMENTAL_BASE_MISSING ) ), MainWP_Backup_Error::ERR_INCREMENTAL_BASE_MISSING );
                return;
            }
            $params['parent_child_task_id'] = isset( $_POST['parent_child_task_id'] ) ? sanitize_text_field( wp_unslash( $_POST['parent_child_task_id'] ) ) : '';
            $params['parent_manifest_b64']  = $raw_manifest;
            $params['parent_snapshot_id']   = isset( $_POST['parent_snapshot_id'] ) ? sanitize_text_field( wp_unslash( $_POST['parent_snapshot_id'] ) ) : '';

            // A manifest too large for one request arrives beforehand via
            // backup_manifest_chunk; the create request references the
            // staged session instead of carrying the bytes inline.
            $params['parent_manifest_ref']    = isset( $_POST['parent_manifest_ref'] ) ? sanitize_text_field( wp_unslash( $_POST['parent_manifest_ref'] ) ) : '';
            $params['parent_manifest_sha256'] = isset( $_POST['parent_manifest_sha256'] ) ? sanitize_text_field( wp_unslash( $_POST['parent_manifest_sha256'] ) ) : '';
        }
        // phpcs:enable WordPress.Security.NonceVerification

        try {
            MainWP_Backup::get()->check_backup_readiness();
            $task_id = MainWP_Child_Backup_Task_Runner::instance()->create_task_for_params( $type, $params );
        } catch ( MainWP_Exception $e ) {
            MainWP_Helper::instance()->error( $e->getMessage(), $e->get_message_error_code() );
            return;
        }

        MainWP_Helper::write(
            array(
                'task_id' => $task_id,
                'phase'   => MainWP_Child_Backup_Task::PHASE_QUEUED,
            )
        );
    }

    /**
     * Method backup_task_run()
     *
     * Advance a backup task one bounded slice and return its fresh status -
     * one round-trip per Dashboard continuation call. The wall-clock
     * deadline applies first, so a task stuck past its budget goes terminal
     * even when its work never runs again.
     *
     * @uses \MainWP\Child\MainWP_Child_Backup_Task_Runner::advance_one_slice()
     */
    public function backup_task_run() {
        $task_id = isset( $_POST['task_id'] ) ? sanitize_text_field( wp_unslash( $_POST['task_id'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification -- authenticated MainWP request.
        if ( '' === $task_id ) {
            MainWP_Helper::instance()->error( esc_html__( 'No backup task specified. The Dashboard did not send a task id with the run request.', 'mainwp-child' ) );
            return;
        }

        $spine = MainWP_Child_Backup_Task::instance();
        $spine->install();
        $spine->enforce_wall_clock_deadline( $task_id );

        $task = $spine->get_task( $task_id );
        if ( null === $task ) {
            MainWP_Helper::instance()->error( esc_html__( 'Backup task not found. It may have been cleaned up or was never created on this site.', 'mainwp-child' ) );
            return;
        }

        MainWP_Helper::set_limit( 2 * MainWP_Child_Backup_Task_Runner::SLICE_BUDGET_SECONDS, '512M' );

        $task = MainWP_Child_Backup_Task_Runner::instance()->advance_one_slice( $task, MainWP_Child_Backup_Task_Runner::LEASE_OWNER_DASHBOARD );
        if ( null === $task ) {
            MainWP_Helper::instance()->error( esc_html__( 'Backup task not found. It may have been cleaned up or was never created on this site.', 'mainwp-child' ) );
            return;
        }

        MainWP_Helper::write( $spine->status_payload( $task ) );
    }

    /**
     * Method backup_task_cancel()
     *
     * Cancel a stuck or unwanted backup from the Dashboard. With no task id the
     * single active backup is cancelled - the orphan case where the Dashboard no
     * longer holds the child task id. While a runner holds the lease the response
     * reports busy and the Dashboard retries; otherwise the task lands terminal
     * failed with the cancelled code after the same cleanup a phase failure runs.
     *
     * @uses \MainWP\Child\MainWP_Child_Backup_Task_Runner::cancel_backup_task()
     */
    public function backup_task_cancel() {
        $task_id = isset( $_POST['task_id'] ) ? sanitize_text_field( wp_unslash( $_POST['task_id'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification -- authenticated MainWP request.

        $outcome = MainWP_Child_Backup_Task_Runner::instance()->cancel_backup_task( $task_id );

        if ( isset( $outcome['error'] ) ) {
            MainWP_Helper::instance()->error( $outcome['error'], isset( $outcome['error_code'] ) ? $outcome['error_code'] : null );
            return;
        }

        MainWP_Helper::write( $outcome );
    }

    /**
     * Method backup_task_prune()
     *
     * Clean up after backups the Dashboard's retention removed: delete the
     * named terminal task rows with their artifact files and sidecars, then
     * sweep stale legacy markers and db dump fragments from the backup
     * directory. Running tasks are refused and report as skipped.
     *
     * @uses \MainWP\Child\MainWP_Child_Backup_Task::prune_task()
     * @uses \MainWP\Child\MainWP_Child_Backup_Task::sweep_stale_markers()
     */
    public function backup_task_prune() {
        // phpcs:disable WordPress.Security.NonceVerification -- authenticated MainWP request.
        $raw_ids  = isset( $_POST['task_ids'] ) ? wp_unslash( $_POST['task_ids'] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- decoded and per-item sanitized below.
        $do_sweep = ! isset( $_POST['sweep'] ) || '1' === $_POST['sweep'];
        // phpcs:enable WordPress.Security.NonceVerification

        $task_ids = json_decode( (string) $raw_ids, true );
        if ( ! is_array( $task_ids ) ) {
            $task_ids = array();
        }

        $spine = MainWP_Child_Backup_Task::instance();
        $spine->install();

        $dirs       = MainWP_Helper::get_mainwp_dir( 'backup' );
        $backup_dir = $dirs[0];

        $counts = array(
            'pruned'  => 0,
            'active'  => 0,
            'missing' => 0,
        );

        // Passed to prune_task so a pruned restore row also drops its aside tables.
        $restore_engine = MainWP_Child_Restore_Task_Runner::instance()->get_restore_engine();

        foreach ( $task_ids as $task_id ) {
            $task_id = sanitize_text_field( (string) $task_id );
            if ( '' === $task_id ) {
                continue;
            }
            $outcome = $spine->prune_task( $task_id, $backup_dir, $restore_engine );
            if ( isset( $counts[ $outcome ] ) ) {
                ++$counts[ $outcome ];
            }
        }

        if ( $do_sweep ) {
            $spine->sweep_stale_markers( $backup_dir );
        }

        MainWP_Helper::write(
            array(
                'result'  => 'ok',
                'pruned'  => $counts['pruned'],
                'active'  => $counts['active'],
                'missing' => $counts['missing'],
            )
        );
    }

    /**
     * Method backup_download_token()
     *
     * Issue a one-time download authorization for a backup artifact. The
     * Dashboard calls this right before fetching the file and verifies the
     * returned sha256 hash after the transfer.
     *
     * @uses \MainWP\Child\MainWP_Child_Backup_Download_Token::issue()
     */
    public function backup_download_token() {
        $file = '';
        if ( ! empty( $_POST['file'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification -- authenticated MainWP request.
            $file = MainWP_Helper::sanitize_filename( wp_unslash( $_POST['file'] ) ); // phpcs:ignore WordPress.Security.NonceVerification,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- authenticated MainWP request; sanitized via MainWP_Helper::sanitize_filename().
        }
        if ( '' === $file ) {
            MainWP_Helper::instance()->error( esc_html__( 'No backup file specified. The Dashboard did not send a file name with the download authorization request.', 'mainwp-child' ), MainWP_Backup_Error::ERR_DL_FILE_NOT_FOUND );
            return;
        }

        $result = MainWP_Child_Backup_Download_Token::instance()->issue( $file );
        if ( isset( $result['error'] ) ) {
            MainWP_Helper::instance()->error( $result['error'], $result['error_code'] );
            return;
        }

        MainWP_Helper::write( $result );
    }

    /**
     * Method restore_preflight()
     *
     * Answer the dashboard's cheap pre-restore questions before any job
     * exists: the live WooCommerce order-storage options the
     * preserve-orders gate reads in the apply phase. Letting the
     * dashboard read them at initiate time turns a refusal after a full
     * transfer into a refusal before the restore job is created.
     *
     * @uses \MainWP\Child\MainWP_Child_Restore_Task_Runner::live_woo_preflight()
     */
    public function restore_preflight() {
        MainWP_Helper::write( MainWP_Child_Restore_Task_Runner::instance()->live_woo_preflight() );
    }

    /**
     * Method restore_task_create()
     *
     * Create a restore task on the resumable machine. The Dashboard sends
     * artifact metadata only (names, sizes, expected hashes) - bytes
     * arrive later through restore_artifact_chunk. All write paths derive
     * from the task id the child generates here; nothing on the wire ever
     * becomes a path.
     *
     * @uses \MainWP\Child\MainWP_Child_Restore_Task_Runner::create_restore_task()
     */
    public function restore_task_create() {
        // phpcs:disable WordPress.Security.NonceVerification -- authenticated MainWP request.
        $restore_id    = isset( $_POST['restore_id'] ) ? sanitize_text_field( wp_unslash( $_POST['restore_id'] ) ) : '';
        $source_prefix = isset( $_POST['source_prefix'] ) ? sanitize_text_field( wp_unslash( $_POST['source_prefix'] ) ) : '';
        $artifacts_raw = isset( $_POST['artifacts_json'] ) ? wp_unslash( $_POST['artifacts_json'] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- decoded and per-field sanitized below.
        $options_raw   = isset( $_POST['options_json'] ) ? wp_unslash( $_POST['options_json'] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- decoded and per-field sanitized below.
        $pull_raw      = isset( $_POST['pull_manifest_json'] ) ? wp_unslash( $_POST['pull_manifest_json'] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- decoded and per-field sanitized below.
        // phpcs:enable WordPress.Security.NonceVerification

        if ( ! preg_match( '/^[a-z0-9]{6,16}$/', $restore_id ) ) {
            MainWP_Helper::instance()->error( esc_html__( 'The restore request did not carry a valid restore id.', 'mainwp-child' ) );
            return;
        }

        $artifacts = $this->sanitize_restore_artifacts( json_decode( (string) $artifacts_raw, true ) );
        if ( empty( $artifacts ) ) {
            MainWP_Helper::instance()->error( esc_html__( 'The restore request did not name any artifacts to receive.', 'mainwp-child' ) );
            return;
        }

        $options  = json_decode( (string) $options_raw, true );
        $options  = is_array( $options ) ? $options : array();
        $sub_type = isset( $options['sub_type'] ) && in_array( $options['sub_type'], array( 'db', 'files' ), true ) ? $options['sub_type'] : 'full';

        $params = array(
            'restore_id'       => $restore_id,
            'source_prefix'    => $source_prefix,
            'artifacts'        => $artifacts,
            'has_foreign_keys' => isset( $options['has_foreign_keys'] ) ? $options['has_foreign_keys'] : null,
            // WooCommerce order preservation: keep the live order tables and
            // re-merge the customers and stock they reference. woo_order_since
            // is the backup's created time, used only to bound the customer
            // capture set; correctness does not depend on it.
            'preserve_orders'  => ! empty( $options['preserve_orders'] ),
            'woo_order_since'  => isset( $options['woo_order_since'] ) ? (int) $options['woo_order_since'] : 0,
        );

        if ( array_key_exists( 'reconcile', $options ) ) {
            $params['reconcile'] = MainWP_Child_Backup_Reconciler::sanitize_options( $options['reconcile'] );
        }

        // A rejected pull manifest never fails the create: the task
        // proceeds without it and the Dashboard takes the staged
        // transfer path instead.
        $pull_rejected = false;
        if ( '' !== $pull_raw ) {
            $pull_manifest = MainWP_Child_Restore_Task_Runner::sanitize_pull_manifest( json_decode( (string) $pull_raw, true ), $artifacts );
            if ( null === $pull_manifest ) {
                $pull_rejected = true;
            } else {
                $params['pull_manifest'] = $pull_manifest;
            }
        }

        try {
            $reused  = false;
            $task_id = MainWP_Child_Restore_Task_Runner::instance()->create_restore_task( $sub_type, $params, $reused );
        } catch ( MainWP_Exception $e ) {
            MainWP_Helper::instance()->error( $e->getMessage(), $e->get_message_error_code() );
            return;
        }

        $disk_free = 0;
        if ( function_exists( 'disk_free_space' ) ) {
            $probe     = @disk_free_space( ABSPATH ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- advisory probe, host may disable it.
            $disk_free = false === $probe ? 0 : (int) $probe;
        }

        $response = array(
            'task_id'         => $task_id,
            'phase'           => MainWP_Child_Backup_Task::PHASE_QUEUED,
            'disk_free_bytes' => $disk_free,
        );
        if ( $reused ) {
            $spine              = MainWP_Child_Backup_Task::instance();
            $response           = array_merge( $response, $spine->status_payload( $spine->get_task( $task_id ) ) );
            $response['reused'] = true;
            $pull_rejected      = '' !== $pull_raw && empty( $response['sub_state']['params']['pull_manifest'] );
        }
        if ( $pull_rejected ) {
            $response['pull_manifest_rejected'] = true;
        }

        MainWP_Helper::write( $response );
    }

    /**
     * Method restore_task_run()
     *
     * Advance a restore task one bounded slice and return its status.
     *
     * @uses \MainWP\Child\MainWP_Child_Restore_Task_Runner::advance_one_slice()
     */
    public function restore_task_run() {
        $task = $this->load_restore_task_from_request( 'run' );
        if ( null === $task ) {
            return;
        }

        MainWP_Helper::set_limit( 2 * MainWP_Child_Backup_Task_Runner::SLICE_BUDGET_SECONDS, '512M' );

        $task = MainWP_Child_Restore_Task_Runner::instance()->advance_one_slice( $task, MainWP_Child_Backup_Task_Runner::LEASE_OWNER_DASHBOARD );
        if ( null === $task ) {
            MainWP_Helper::instance()->error( esc_html__( 'Restore task not found. It may have been cleaned up or was never created on this site.', 'mainwp-child' ) );
            return;
        }

        MainWP_Helper::write( MainWP_Child_Backup_Task::instance()->status_payload( $task ) );
    }

    /**
     * Method restore_task_status()
     *
     * Read-only status for a restore task; enforces the wall-clock
     * deadline like the backup status callable.
     */
    public function restore_task_status() {
        $task = $this->load_restore_task_from_request( 'status' );
        if ( null === $task ) {
            return;
        }

        MainWP_Helper::write( MainWP_Child_Backup_Task::instance()->status_payload( $task ) );
    }

    /**
     * Method restore_task_cancel()
     *
     * Cancel a restore task. While another runner holds the task lease
     * the response reports busy and the Dashboard retries; otherwise
     * the task lands terminal failed with the cancelled code after the
     * same cleanup a phase failure runs (old aside tables kept).
     *
     * @uses \MainWP\Child\MainWP_Child_Restore_Task_Runner::cancel_restore_task()
     */
    public function restore_task_cancel() {
        $task = $this->load_restore_task_from_request( 'cancel' );
        if ( null === $task ) {
            return;
        }

        $outcome = MainWP_Child_Restore_Task_Runner::instance()->cancel_restore_task( $task->task_id );

        if ( isset( $outcome['error'] ) ) {
            MainWP_Helper::instance()->error( $outcome['error'], isset( $outcome['error_code'] ) ? $outcome['error_code'] : null );
            return;
        }

        MainWP_Helper::write( $outcome );
    }

    /**
     * Method backup_manifest_chunk()
     *
     * Receive one hash-verified piece of an oversized parent manifest
     * into a staging session. The first chunk creates the session and
     * returns a child-generated token; later chunks reference it. The
     * final chunk verifies the whole manifest's sha256 and marks the
     * session ready for a backup_task_create request to consume.
     *
     * @uses \MainWP\Child\MainWP_Child_Backup_Manifest_Chunk_Receiver::receive_chunk()
     */
    public function backup_manifest_chunk() {
        // phpcs:disable WordPress.Security.NonceVerification -- authenticated MainWP request.
        $mtoken       = isset( $_POST['mtoken'] ) ? sanitize_text_field( wp_unslash( $_POST['mtoken'] ) ) : '';
        $offset       = isset( $_POST['offset'] ) ? (int) $_POST['offset'] : -1;
        $chunk_b64    = isset( $_POST['chunk_b64'] ) ? wp_unslash( $_POST['chunk_b64'] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- validated as strict base64 below.
        $chunk_sha256 = isset( $_POST['chunk_sha256'] ) ? sanitize_text_field( wp_unslash( $_POST['chunk_sha256'] ) ) : '';
        $total_sha256 = isset( $_POST['total_sha256'] ) ? sanitize_text_field( wp_unslash( $_POST['total_sha256'] ) ) : '';
        $final        = isset( $_POST['final'] ) && '1' === $_POST['final'];
        // phpcs:enable WordPress.Security.NonceVerification

        $raw = base64_decode( $chunk_b64, true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- wire encoding of manifest bytes, not code.
        if ( false === $raw ) {
            MainWP_Helper::instance()->error( esc_html( MainWP_Backup_Error::message( MainWP_Backup_Error::ERR_CHUNK_HASH ) ), MainWP_Backup_Error::ERR_CHUNK_HASH );
            return;
        }

        $outcome = MainWP_Child_Backup_Manifest_Chunk_Receiver::instance()->receive_chunk( $mtoken, $offset, $raw, $chunk_sha256, $total_sha256, $final );

        if ( isset( $outcome['error_code'] ) && MainWP_Backup_Error::ERR_CHUNK_GAP === $outcome['error_code'] ) {
            MainWP_Helper::write( $outcome ); // The gap response carries bytes_received so the Dashboard realigns.
            return;
        }
        if ( isset( $outcome['error'] ) ) {
            MainWP_Helper::instance()->error( $outcome['error'], isset( $outcome['error_code'] ) ? $outcome['error_code'] : null );
            return;
        }

        MainWP_Helper::write( $outcome );
    }

    /**
     * Method restore_artifact_chunk()
     *
     * Receive one hash-verified piece of a restore artifact. The write
     * path derives from the task id (scratch dir) plus the artifact's
     * basename, which must be in the list the task was created with. A
     * re-sent chunk at an already-written offset is acknowledged without
     * writing; a gap returns the current size so the Dashboard realigns;
     * the final chunk verifies the whole artifact's sha256 and discards
     * the file on mismatch.
     *
     * @uses \MainWP\Child\MainWP_Child_Restore_Task_Runner::scratch_dir()
     */
    public function restore_artifact_chunk() {
        // phpcs:disable WordPress.Security.NonceVerification -- authenticated MainWP request.
        $task_id      = isset( $_POST['task_id'] ) ? sanitize_text_field( wp_unslash( $_POST['task_id'] ) ) : '';
        $file         = isset( $_POST['file'] ) ? MainWP_Helper::sanitize_filename( wp_unslash( $_POST['file'] ) ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- sanitized via MainWP_Helper::sanitize_filename().
        $offset       = isset( $_POST['offset'] ) ? (int) $_POST['offset'] : -1;
        $chunk_b64    = isset( $_POST['chunk_b64'] ) ? wp_unslash( $_POST['chunk_b64'] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- validated as strict base64 below.
        $chunk_sha256 = isset( $_POST['chunk_sha256'] ) ? sanitize_text_field( wp_unslash( $_POST['chunk_sha256'] ) ) : '';
        $final        = isset( $_POST['final'] ) && '1' === $_POST['final'];
        // phpcs:enable WordPress.Security.NonceVerification

        $raw = base64_decode( $chunk_b64, true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- wire encoding of binary artifact bytes, not code.
        if ( false === $raw ) {
            MainWP_Helper::instance()->error( esc_html( MainWP_Backup_Error::message( MainWP_Backup_Error::ERR_CHUNK_HASH ) ), MainWP_Backup_Error::ERR_CHUNK_HASH );
            return;
        }

        $outcome = MainWP_Child_Restore_Task_Runner::instance()->receive_chunk( $task_id, $file, $offset, $raw, $chunk_sha256, $final );

        if ( isset( $outcome['error_code'] ) && MainWP_Backup_Error::ERR_CHUNK_GAP === $outcome['error_code'] ) {
            MainWP_Helper::write( $outcome ); // The gap response carries bytes_received so the Dashboard realigns.
            return;
        }
        if ( isset( $outcome['error'] ) ) {
            MainWP_Helper::instance()->error( $outcome['error'], isset( $outcome['error_code'] ) ? $outcome['error_code'] : null );
            return;
        }

        MainWP_Helper::write( $outcome );
    }

    /**
     * Sanitize the artifact metadata list from the wire.
     *
     * @param mixed $raw Decoded artifacts_json.
     *
     * @return array Clean artifact entries.
     */
    private function sanitize_restore_artifacts( $raw ) {
        if ( ! is_array( $raw ) ) {
            return array();
        }
        $clean = array();
        foreach ( $raw as $entry ) {
            if ( ! is_array( $entry ) || empty( $entry['file'] ) ) {
                continue;
            }
            $file = MainWP_Helper::sanitize_filename( (string) $entry['file'] );
            if ( '' === $file || $file !== (string) $entry['file'] ) {
                continue; // A name that needed sanitizing is not a name we issued.
            }
            $sha256  = isset( $entry['sha256'] ) && is_string( $entry['sha256'] ) && preg_match( '/^[a-f0-9]{64}$/', $entry['sha256'] ) ? $entry['sha256'] : '';
            $clean[] = array(
                'role'       => isset( $entry['role'] ) ? sanitize_text_field( (string) $entry['role'] ) : '',
                'file'       => $file,
                'size'       => isset( $entry['size'] ) ? (int) $entry['size'] : 0,
                'sha256'     => $sha256,
                'adopt_only' => ! empty( $entry['adopt_only'] ),
            );
        }
        return $clean;
    }

    /**
     * Load and validate the restore task named in the request.
     *
     * @param string $verb Request verb for the error message.
     *
     * @return object|null Task row, or null after writing an error.
     */
    private function load_restore_task_from_request( $verb ) {
        unset( $verb );
        $task_id = isset( $_POST['task_id'] ) ? sanitize_text_field( wp_unslash( $_POST['task_id'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification -- authenticated MainWP request.
        if ( '' === $task_id ) {
            MainWP_Helper::instance()->error( esc_html__( 'No restore task specified. The Dashboard did not send a task id with the request.', 'mainwp-child' ) );
            return null;
        }

        $spine = MainWP_Child_Backup_Task::instance();
        $spine->install();
        $spine->enforce_wall_clock_deadline( $task_id );

        $task = $spine->get_task( $task_id );
        if ( null === $task || ! MainWP_Child_Restore_Task_Runner::is_restore_type( $task->type ) ) {
            MainWP_Helper::instance()->error( esc_html__( 'Restore task not found. It may have been cleaned up or was never created on this site.', 'mainwp-child' ) );
            return null;
        }

        return $task;
    }

    /**
     * Method backup_full()
     *
     * Fire off the backup_full() function.
     *
     * @param string $file_name Contains the backup file name.
     *
     * @return array Action result.
     *
     * @uses \MainWP\Child\MainWP_Backup::backup_full()
     */
    protected function backup_full( $file_name ) {
        return MainWP_Backup::get()->backup_full( $file_name );
    }

    /**
     * Method backup_db(), NOT used?.
     *
     * Fire off the backup_db() function.
     *
     * @param string $file_name      Contains the backup file name.
     * @param string $file_extension Contains the backup file extension.
     *
     * @return array Action result.
     *
     * @uses \MainWP\Child\MainWP_Backup::backup_db()
     */
    protected function backup_db( $file_name = '', $file_extension = 'zip' ) {
        return MainWP_Backup::get()->backup_db( $file_name, $file_extension );
    }

    /**
     * Method get_site_icon()
     *
     * Fire off the get_site_icon() function.
     *
     * @uses \MainWP\Child\MainWP_Child_Misc::get_site_icon()
     */
    public function get_site_icon() {
        MainWP_Child_Misc::get_instance()->get_site_icon();
    }

    /**
     * Method check_abandoned()
     *
     * Fire off the check_abandoned() function.
     */
    public function check_abandoned() {
         // phpcs:disable WordPress.Security.NonceVerification
        $which = isset( $_POST['which'] ) ? sanitize_text_field( wp_unslash( $_POST['which'] ) ) : '';
         // phpcs:enable
        $infor         = array();
        $infor['sync'] = array();
        if ( 'plugin' === $which ) {
            $plugins_check = MainWP_Child_Plugins_Check::instance();
            $plugins_check->run_check();
            $infor['sync']['plugins_outdate_info'] = $plugins_check->get_plugins_outdate_info();
            $infor['success']                      = 1;
        } else {
            $themes_check = MainWP_Child_Themes_Check::instance();
            $themes_check->run_check();
            $infor['sync']['themes_outdate_info'] = $themes_check->get_themes_outdate_info();
            $infor['success']                     = 1;
        }
        MainWP_Helper::write( $infor );
    }

    /**
     * Method get_security_stats()
     *
     * Fire off the get_security_stats() function.
     *
     * @uses \MainWP\Child\MainWP_Child_Misc::get_security_stats()
     */
    public function get_security_stats() {
        MainWP_Child_Misc::get_instance()->get_security_stats();
    }

    /**
     * Method do_security_fix()
     *
     * Fire off the do_security_fix() function.
     *
     * @uses \MainWP\Child\MainWP_Child_Misc::do_security_fix()
     */
    public function do_security_fix() {
        MainWP_Child_Misc::get_instance()->do_security_fix();
    }

    /**
     * Method do_security_un_fix()
     *
     * Fire off the do_security_un_fix() function.
     *
     * @uses \MainWP\Child\MainWP_Child_Misc::do_security_un_fix()
     */
    public function do_security_un_fix() {
        MainWP_Child_Misc::get_instance()->do_security_un_fix();
    }

    /**
     * Method settings_tools()
     *
     * Fire off the settings_tools() function.
     *
     * @uses \MainWP\Child\MainWP_Child_Misc::do_security_un_fix()
     */
    public function settings_tools() {
        MainWP_Child_Misc::get_instance()->settings_tools();
    }

    /**
     * Method bulk_settings_manager()
     *
     * Fire off the action() function.
     *
     * @uses \MainWP\Child\MainWP_Child_Bulk_Settings_Manager::action()
     */
    public function bulk_settings_manager() {
        MainWP_Child_Bulk_Settings_Manager::instance()->action();
    }

    /**
     * Method custom_post_type()
     *
     * Fire off the action() function.
     *
     * @uses \MainWP\Child\MainWP_Custom_Post_Type::action()
     */
    public function custom_post_type() {
        MainWP_Custom_Post_Type::instance()->action();
    }

    /**
     * Method backup_buddy()
     *
     * Fire off the action() function.
     *
     * @uses \MainWP\Child\MainWP_Child_Back_Up_Buddy::action()
     */
    public function backup_buddy() {
        MainWP_Child_Back_Up_Buddy::instance()->action();
    }

    /**
     * Method vulner_checker()
     *
     * Fire off the action() function.
     *
     * @uses \MainWP\Child\MainWP_Child_Vulnerability_Checker::action()
     */
    public function vulner_checker() {
        MainWP_Child_Vulnerability_Checker::instance()->action();
    }

    /**
     * Method time_capsule()
     *
     * Fire off the action() function.
     *
     * @uses \MainWP\Child\MainWP_Child_Timecapsule::action()
     */
    public function time_capsule() {
        MainWP_Child_Timecapsule::instance()->action();
    }

    /**
     * Method wp_staging()
     *
     * Fire off the action() function.
     *
     * @uses \MainWP\Child\MainWP_Child_Staging::action()
     */
    public function wp_staging() {
        MainWP_Child_Staging::instance()->action();
    }

    /**
     * Method extra_execution()
     *
     * Additional functions to execute.
     *
     * @uses \MainWP\Child\MainWP_Helper::write()
     */
    public function extra_execution() {
         // phpcs:disable WordPress.Security.NonceVerification
        $post = $_POST;
         // phpcs:enable
        $information = array();
        /**
         * Filter 'mainwp_child_extra_execution'
         *
         * Additional functions to execute through the filter.
         *
         * @param array $information An array containing the synchronization information.
         * @param mixed $post Contains the POST request.
         *
         * @since 4.0
         */
        $information = apply_filters( 'mainwp_child_extra_execution', $information, $post );
        MainWP_Helper::write( $information );
    }

    /**
     * Method uploader_action()
     *
     * Fire off the uploader_action() function.
     *
     * @uses \MainWP\Child\MainWP_Child_Misc::uploader_action()
     */
    public function uploader_action() {
        MainWP_Child_Misc::get_instance()->uploader_action();
    }

    /** Dispatch the read-pure file deployment preflight. */
    public function uploader_preflight_v2() {
        ( new MainWP_Child_File_Deployment() )->handle_preflight();
    }

    /** Dispatch one verified file deployment. */
    public function uploader_deploy_v2() {
        ( new MainWP_Child_File_Deployment() )->handle_deploy();
    }

    /** Read one durable file deployment result. */
    public function uploader_deployment_state_v2() {
        ( new MainWP_Child_File_Deployment() )->handle_state();
    }

    /** Dispatch one exact file deployment rollback. */
    public function uploader_rollback_v2() {
        ( new MainWP_Child_File_Deployment() )->handle_rollback();
    }

    /**
     * Negotiate the narrow Early Access release protocol.
     */
    public function early_access_release_v2() {
        ( new MainWP_Child_Early_Access_Release() )->action();
    }

    /**
     * Read one exact favorite package state.
     */
    public function favorites_package_state_v2() {
        ( new MainWP_Child_Favorites() )->handle_package_state();
    }

    /**
     * Negotiate the verified favorite installer protocol.
     */
    public function favorites_install_verified_v2() {
        ( new MainWP_Child_Favorites() )->handle_install();
    }

    /**
     * Dispatch the narrow Virusdie signed-installer protocol.
     *
     * @return void
     */
    public function virusdie_sync_install_v1() {
        MainWP_Child_Misc::get_instance()->virusdie_sync_install_v1();
    }

    /**
     * Method wordpress_seo()
     *
     * Fire off the action() function.
     */
    public function wordpress_seo() {
        MainWP_WordPress_SEO::instance()->action();
    }

    /**
     * Method wp_seopress()
     *
     * Fire off the action() function.
     *
     * @uses \MainWP\Child\MainWP_Child_WP_Seopress::action();
     */
    public function wp_seopress() {
        MainWP_Child_WP_Seopress::instance()->action();
    }

    /**
     * Method client_report()
     *
     * Fire off the action() function.
     *
     * @uses \MainWP\Child\MainWP_Client_Report::action()
     */
    public function client_report() {
        MainWP_Client_Report::instance()->action();
    }

    /**
     * Method page_speed()
     *
     * Fire off the action() function.
     *
     * @uses \MainWP\Child\MainWP_Child_Pagespeed::action()
     */
    public function page_speed() {
        MainWP_Child_Pagespeed::instance()->action();
    }

    /**
     * Method woo_com_status()
     *
     * Fire off the action() function.
     *
     * @uses \MainWP\Child\MainWP_Child_WooCommerce_Status::action()
     */
    public function woo_com_status() {
        MainWP_Child_WooCommerce_Status::instance()->action();
    }

    /**
     * Method links_checker()
     *
     * Fire off the action() function.
     *
     * @uses \MainWP\Child\MainWP_Child_Links_Checker::action()
     */
    public function links_checker() {
        MainWP_Child_Links_Checker::instance()->action();
    }

    /**
     * Method wordfence()
     *
     * Fire off the action() function.
     *
     * @uses \MainWP\Child\MainWP_Child_Wordfence::action()
     */
    public function wordfence() {
        MainWP_Child_Wordfence::instance()->action();
    }

    /**
     * Method ithemes()
     *
     * Fire off the action() function.
     *
     * @uses \MainWP\Child\MainWP_Child_IThemes_Security::action()
     */
    public function ithemes() {
        MainWP_Child_IThemes_Security::instance()->action();
    }

    /**
     * Method updraftplus()
     *
     * Fire off the action() function.
     *
     * @uses \MainWP\Child\MainWP_Child_Updraft_Plus_Backups::action()
     */
    public function updraftplus() {
        MainWP_Child_Updraft_Plus_Backups::instance()->action();
    }

    /**
     * Method wpvivid_backuprestore()
     *
     * Fire off the action() function.
     *
     * @uses \MainWP\Child\MainWP_Child_WPvivid_BackupRestore::action()
     */
    public function wpvivid_backuprestore() {
        MainWP_Child_WPvivid_BackupRestore::instance()->action();
    }

    /**
     * Method backup_wp()
     *
     * Fire off the action() function.
     *
     * @uses \MainWP\Child\MainWP_Child_Back_Up_WordPress::action()
     * @uses \MainWP\Child\MainWP_Helper::write()
     */
    public function backup_wp() {
        if ( ! version_compare( phpversion(), '5.3', '>=' ) ) {
            // translators: %s: PHP Version number.
            $error = sprintf( esc_html__( 'PHP Version %s is unsupported.', 'mainwp-child' ), phpversion() );
            MainWP_Helper::write( array( 'error' => $error ) );
        }
        MainWP_Child_Back_Up_WordPress::instance()->action();
    }

    /**
     * Method wp_rocket()
     *
     * Fire off the action() function.
     *
     * @uses \MainWP\Child\MainWP_Child_WP_Rocket::action()
     */
    public function wp_rocket() {
        MainWP_Child_WP_Rocket::instance()->action();
    }

    /**
     * Method backwpup()
     *
     * Fire off the action() function.
     *
     * @uses \MainWP\Child\MainWP_Child_Back_WP_Up::action()
     */
    public function backwpup() {
        MainWP_Child_Back_WP_Up::instance()->action();
    }


    /**
     * Method db_updater()
     *
     * Fire off the action() function.
     */
    public function db_updater() {
        MainWP_Child_DB_Updater::instance()->action();
    }

    /**
     * Method jetpack_protect()
     *
     * Fire off the action() function.
     */
    public function jetpack_protect() {
        MainWP_Child_Jetpack_Protect::instance()->action();
    }


    /**
     * Method jetpack_scan()
     *
     * Fire off the action() function.
     */
    public function jetpack_scan() {
        MainWP_Child_Jetpack_Scan::instance()->action();
    }

    /**
     * Method delete_actions()
     *
     * Delete Non-MainWP actions.
     */
    public function delete_actions() {
        MainWP_Child_Actions::get_instance()->delete_actions();
    }

    /**
     * Method verify_action()
     *
     * Handle verify authed action.
     */
    public function verify_action() {
        // phpcs:disable WordPress.Security.NonceVerification
        if ( isset( $_REQUEST['actionnonce'] ) && ! empty( sanitize_text_field( wp_unslash( $_REQUEST['actionnonce'] ) ) ) ) {
            $valid = MainWP_Utility::instance()->verify_action_nonce( sanitize_text_field( wp_unslash( $_REQUEST['actionnonce'] ) ) );
            if ( $valid ) {
                MainWP_Helper::write( array( 'success' => 1 ) );
            }
        }
        // phpcs:enable
        MainWP_Helper::write( array( 'failed' => 1 ) );
    }




    /**
     * Method delete_backup()
     *
     * Delete backup.
     *
     * @uses \MainWP\Child\MainWP_Helper::get_mainwp_dir()
     * @uses \MainWP\Child\MainWP_Helper::write()
     */
    public function delete_backup() {
        // phpcs:disable WordPress.Security.NonceVerification
        $file = isset( $_REQUEST['del'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['del'] ) ) : '';
        // phpcs:enable

        // Resolve through the same lookup the download path uses: it rejects a
        // name carrying a separator, and it also finds an archive still sitting
        // in the pre-hash legacy directory, which a hashed-dir-only delete
        // silently failed to remove while still reporting ok.
        $path = MainWP_Helper::locate_backup_file( $file );
        if ( false !== $path ) {
            wp_delete_file( $path );  // NOSONAR .
        }

        MainWP_Helper::write( array( 'result' => 'ok' ) );
    }

    /**
     * Method update_child_values()
     *
     * Update the MainWP Child site options.
     *
     * @uses \MainWP\Child\MainWP_Helper::update_option()
     * @uses \MainWP\Child\MainWP_Helper::write()
     */
    public function update_child_values() {
         // phpcs:disable WordPress.Security.NonceVerification
        $unique_id = isset( $_POST['uniqueId'] ) ? sanitize_text_field( wp_unslash( $_POST['uniqueId'] ) ) : '';
         // phpcs:enable
        MainWP_Helper::update_option( 'mainwp_child_uniqueId', $unique_id );
        MainWP_Helper::write( array( 'result' => 'ok' ) );
    }

    /**
     * Method branding_child_plugin()
     *
     * Fire off the action() function.
     *
     * @uses \MainWP\Child\MainWP_Child_Branding::action()
     */
    public function branding_child_plugin() {
        MainWP_Child_Branding::instance()->action();
    }

    /**
     * Method update_child_plugin()
     *
     * Fire off the action() function.
     *
     * @uses MainWP_Child_Cache_Purge::action()
     * @used-by \MainWP\Extensions\CacheControl\MainWP_Cache_Control_Purge_View::ajax_cache_control_purge_cache_all()
     */
    public function cache_purge_action() {
        MainWP_Child_Cache_Purge::instance()->auto_purge_cache( 'true' );
    }

    /**
     * Method code_snippet()
     *
     * Fire off the code_snippet() function.
     *
     * @uses MainWP_Child_Misc::code_snippet()
     */
    public function code_snippet() {
        MainWP_Child_Misc::get_instance()->code_snippet();
    }

    /**
     * Method disconnect()
     *
     * Disconnect the child site from the current MainWP Dashboard.
     *
     * @uses \MainWP\Child\MainWP_Helper::write()
     */
    public function disconnect() {

        /**
         * MainWP Child instance.
         *
         * @global object
         */
        global $mainWPChild;

        $mainWPChild->delete_connection_data();

        MainWP_Helper::write( array( 'result' => 'success' ) );
    }


    /**
     * Method deactivate()
     *
     * Deactivate the MainWP Child plugin in the site.
     *
     * @uses \MainWP\Child\MainWP_Helper::error()
     * @uses \MainWP\Child\MainWP_Helper::write()
     */
    public function deactivate() {

        /**
         * MainWP Child instance.
         *
         * @global object
         */
        global $mainWPChild;

        include_once ABSPATH . 'wp-admin/includes/plugin.php'; // NOSONAR -- WP compatible.
        deactivate_plugins( $mainWPChild->plugin_slug, true );
        $information = array();
        if ( is_plugin_active( $mainWPChild->plugin_slug ) ) {
            MainWP_Helper::instance()->error( 'Plugin still active' );
        }

        // Deactivate reports plugin if active.
        $reports_slug = 'mainwp-child-reports/mainwp-child-reports.php';

        if ( is_plugin_active( $reports_slug ) ) {
            deactivate_plugins( $reports_slug, true );
        }

        /**
         * MainWP Child instance.
         *
         * @global object
         */
        global $mainWPChild;

        $mainWPChild->delete_connection_data();
        $information['deactivated'] = true;
        MainWP_Helper::write( $information );
    }

    /**
     * Method api_backups_mysqldump()
     *
     * Fire off the action() function.
     *
     * @uses MainWP_Child_Api_Backups::action()
     * @used-by \MainWP\Extensions\ApiBackups\MainWP_API_Backups_3rd_Party::api_backups_mysqldump()
     */
    public function api_backups_mysqldump() {
        MainWP_Child_Api_Backups::instance()->api_backups_mysqldump();
    }

    /**
     * Method patchstack()
     *
     * Fire off the action() function.
     */
    public function patchstack() {
        MainWP_Child_Patchstack::instance()->action();
    }

    /**
     * Method password_policy_settings()
     *
     * Fire off the action() function.
     *
     * @uses \MainWP\Child\MainWP_Child_Password_Policy::action()
     * @uses \MainWP\Child\MainWP_Helper::write()
     */
    public function password_policy_settings() {
        $information = MainWP_Child_Password_Policy::instance()->action();
        MainWP_Helper::write( $information );
        exit();
    }

    /**
     * Method clean_up_child_logs()
     *
     * Clean up the child site logs.
     *
     * @uses \MainWP\Child\MainWP_Child_Logs::clean_up_logs()
     */
    public function clean_up_child_logs() {
        $information = array();
        if ( class_exists( '\MainWP\Child\Changes\Changes_Logs_DB_Log' ) ) {
            \MainWP\Child\Changes\Changes_Logs_DB_Log::instance()->bulk_clean_up();
            $information['success']             = 1;
            $information['dbsize_activitylogs'] = \MainWP\Child\Changes\Changes_Logs_DB_Log::instance()->get_logs_db_size();
        } else {
            $error    = __( 'Changes Logs DB Log class does not exist.', 'mainwp-child' );
            $err_code = 'CHANGES_LOGS_ERROR_01';
            MainWP_Helper::instance()->error( $error, $err_code );
        }
        MainWP_Helper::write( $information );
    }

    /**
     * Method process_premium_updates()
     */
    public function process_premium_updates() {
        $response = MainWP_Child_Updates::get_instance()->process_premium_updates();
        if ( ! is_array( $response ) ) {
            $response = array();
        }
        if ( empty( $response ) ) {
            $response['error_code'] = 'PREMIUM_ACTION_ERROR';
            $response['error']      = esc_html__( 'An error occurred while processing the premium updates. Please try again later.', 'mainwp-child' );
        } elseif ( ! empty( $response['error'] ) && empty( $response['error_code'] ) ) {
            $response['error_code'] = 'PREMIUM_ACTION_ERROR';
        }
        MainWP_Helper::write( $response );
    }
}
