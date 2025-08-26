<?php
/**
 * Logger: System
 *
 * System logger class file.
 *
 * @since      5.5
 * @package    mainwp/child
 */

namespace MainWP\Child\Changes;

// Exit.
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}


/**
 * System Activity.
 */
class Changes_Handle_WP_System {

    /**
     * Keeps the value of the old option.
     *
     * @var array
     */
    private static $old_option = array();

    /**
     * The executed cron is removed BEFORE execution, so we have to keep the value of it.
     *
     * @var \StdClass
     */
    private static $executed_cron = false;

    /**
     * The rescheduled cron is holding the old (original) value of the event.
     *
     * @var \StdClass
     */
    private static $rescheduled_cron = false;

    /**
     * Inits the main hooks
     *
     * @return void
     */
    public static function init_hooks() {
        \add_action( 'admin_init', array( __CLASS__, 'callback_change_admin_init' ) );
        \add_action( 'allowed_options', array( __CLASS__, 'callback_change_options' ), 10, 1 );
        \add_action( 'update_option_admin_email', array( __CLASS__, 'callback_change_admin_email' ), 10, 3 );
        \add_action( 'customize_save_blogname', array( __CLASS__, 'callback_change_site_blogname' ), 20, 1 );
        \add_action( 'updated_option', array( __CLASS__, 'callback_change_updated_option' ), 10, 3 );
        \add_action( 'added_option', array( __CLASS__, 'callback_change_added_option' ), 10, 2 );
        \add_action( 'delete_option', array( __CLASS__, 'callback_change_delete_option' ) );
        \add_action( 'deleted_option', array( __CLASS__, 'callback_change_deleted_option' ) );
    }

    /**
     * Loads all the plugin dependencies early.
     *
     * @return void
     */
    public static function before_init() {
        \add_action( 'pre_reschedule_event', array( __CLASS__, 'change_pre_reschedule_event' ), PHP_INT_MAX, 2 );
        \add_action( 'pre_schedule_event', array( __CLASS__, 'change_pre_schedule_event' ), PHP_INT_MAX, 2 );
        \add_action( 'pre_unschedule_event', array( __CLASS__, 'callback_change_unschedule_cron_job' ), PHP_INT_MAX, 4 );
        \add_action( 'schedule_event', array( __CLASS__, 'callback_change_create_new_cron_job' ) );
        \add_action( 'wp_ajax_destroy-sessions', array( __CLASS__, 'callback_change_destroy_user_session' ), 0 );
        self::change_attach_cron_actions();
    }

    /**
     * Pre schedule event.
     *
     * @param mixed     $pre - Pre schedule.
     * @param \StdClass $hook - The schedule cron.
     *
     * @return void
     */
    public static function change_pre_schedule_event( $pre, $hook ) {
        if ( null === $pre && ! defined( 'DOING_CRON' ) ) {
            static::change_set_executed_cron( $hook );
        }
    }

    /**
     * Pre reschedule event.
     *
     * @param mixed     $pre - Pre schedule.
     * @param \StdClass $hook - The schedule cron.
     *
     * @return void
     */
    public static function change_pre_reschedule_event( $pre, $hook ) {
        if ( null === $pre && ! defined( 'DOING_CRON' ) ) {
            static::change_set_rescheduled_cron( \wp_get_scheduled_event( $hook->hook, $hook->args ) );
        }
    }


    /**
     * User sessions destroy event.
     *
     * @return void
     */
    public static function callback_change_destroy_user_session() {

        if ( ! isset( $_POST['user_id'] ) ) {
            return;
        }

        $user = \get_userdata( (int) $_POST['user_id'] ); // phpcs:ignore WordPress.Security.NonceVerification.Missing

        if ( $user ) {
            if ( ! current_user_can( 'edit_user', $user->ID ) ) {
                $user = false;
            } elseif ( isset( $_POST['nonce'] ) && ! \wp_verify_nonce( $_POST['nonce'], 'update-user_' . $user->ID ) ) { // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.MissingUnslash, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
                $user = false;
            }
        }

        if ( $user ) {
            $log_data = array(
                'changeuserid' => $user->ID,
            );
            Changes_Logs_Logger::log_change( 1585, $log_data );
        }
    }

    /**
     * Setter class method to set a value to the class variable
     *
     * @param \StdClass $executed_cron - The cron object.
     *
     * @return void
     */
    public static function change_set_executed_cron( $executed_cron ) {
        self::$executed_cron = $executed_cron;
    }

    /**
     * Setter class method to set a value to the class variable
     *
     * @param \StdClass $rescheduled_cron - The cron object.
     *
     * @return void
     */
    public static function change_set_rescheduled_cron( $rescheduled_cron ) {
        self::$rescheduled_cron = $rescheduled_cron;
    }

    /**
     * Called when the original wp-cron.php gets executed.
     *
     * @return void
     */
    public static function change_attach_cron_actions() {
        if ( defined( 'DOING_CRON' ) ) {
            \add_action( 'pre_unschedule_event', array( __CLASS__, 'change_attach_cron_pre_unschedule_event' ), PHP_INT_MAX, 4 );
            $crons = \wp_get_ready_cron_jobs();
            foreach ( $crons  as $timestamp => $cronhooks ) {
                foreach ( $cronhooks as $hook => $keys ) {
                    foreach ( $keys as $k => $v ) {
                        \add_action(
                            $hook,
                            function () use ( $hook ) {
                                if ( self::$executed_cron && self::$executed_cron->hook === $hook ) {
                                    $type_id = 1940;
                                    $cron    = self::$executed_cron;
                                    $data    = array(
                                        'task_name'     => $cron->hook,
                                        'timestamp'     => Changes_Helper::get_formatted_date( $cron->timestamp ),
                                        'arguments'     => $cron->args,
                                        'currentuserid' => Changes_Helper::change_get_current_user()->ID,
                                        'username'      => Changes_Helper::change_get_current_user()->user_login,
                                    );
                                    if ( empty( $data['currentuserid'] ) ) {
                                        unset( $data['currentuserid'] );
                                        $data['username'] = 'System';
                                    }
                                    if ( $cron->schedule ) {
                                        $type_id              = 1945;
                                        $data['schedule']     = $cron->schedule;
                                        $schedule_info        = isset( \wp_get_schedules()[ $cron->schedule ] ) ? \wp_get_schedules()[ $cron->schedule ] : false;
                                        $data['interval']     = ( isset( $schedule_info ) && is_array( $schedule_info ) ) ? $schedule_info['interval'] : 0;
                                        $data['display_name'] = ( isset( $schedule_info ) && is_array( $schedule_info ) ) ? $schedule_info['display'] : __( 'Unknown or Invalid (non-existing)', 'mainwp-child' );
                                    }
                                    if ( static::if_system_change_log_enabled( $type_id, $data ) ) {
                                        Changes_Logs_Logger::log_change( $type_id, $data );
                                    }
                                    self::$executed_cron = false;
                                }
                            }
                        );
                    }
                }
            }
        }
    }

    /**
     * Attach cron actions.
     *
     * @param mixed     $pre - Pre schedule.
     * @param int       $timestamp - The schedule timestamp.
     * @param \StdClass $hook - The schedule cron.
     * @param array     $args - The schedule args.
     *
     * @return void
     */
    public static function change_attach_cron_pre_unschedule_event( $pre, $timestamp, $hook, $args ) { //phpcs:ignore -- NOSONAR - ok.
        static::change_set_executed_cron( \wp_get_scheduled_event( $hook, $args, $timestamp ) );
    }

    /**
     * Checks if some system logs are enabled or not.
     *
     * @param string $table_name Table name.
     * @param array  $log_data Log data.
     *
     * @return bool
     */
    private static function if_system_change_log_enabled( $type_id, $log_data ) {

        static $disabled_system_logs;

        if ( null === $disabled_system_logs ) {
            $default_disabled_logs = array(
                1940 => array( 'task_name' => array( 'do_pings' ) ),
            );
            $default_disabled_logs = apply_filters( 'mainwp_child_changes_logs_disabled_system_logs', $default_disabled_logs );
        }

        if ( ! is_array( $disabled_system_logs ) ) {
            $disabled_system_logs = array();
        }

        if ( 1940 === $type_id && isset( $disabled_system_logs[ $type_id ] ) ) {
            if ( ! empty( $log_data['task_name'] ) && isset( $disabled_system_logs[ $type_id ]['task_name'] ) && is_array( $disabled_system_logs[ $type_id ]['task_name'] ) ) {
                $disabled_task_names = $disabled_system_logs[ $type_id ]['task_name'];
                if ( is_string( $log_data['task_name'] ) ) {
                    return ! in_array( $log_data['task_name'], $disabled_task_names );
                }
                return false;
            }
        }

        return true;
    }

    /**
     * Monitors cron jobs creation.
     *
     * @param \StdClass $cron - A cron job created.
     *
     * @return \StdClass $cron
     */
    public static function callback_change_create_new_cron_job( $cron ) {
        if ( false === $cron ) {
            return $cron;
        }
        if (
            isset( self::$executed_cron ) &&
            ! empty( self::$executed_cron ) &&
            \is_object( $cron ) &&
            \is_object( self::$executed_cron ) &&
            \property_exists( self::$executed_cron, 'hook' ) &&
            \property_exists( $cron, 'hook' ) && self::$executed_cron && self::$executed_cron->hook === $cron->hook ) {

            $type_id = 1925;
            $data    = array(
                'task_name'     => $cron->hook,
                'timestamp'     => Changes_Helper::get_formatted_date( $cron->timestamp ),
                'arguments'     => $cron->args,
                'currentuserid' => Changes_Helper::get_user()->ID,
                'username'      => Changes_Helper::get_user()->user_login,
            );
            if ( empty( $data['currentuserid'] ) ) {
                unset( $data['currentuserid'] );
                $data['username'] = 'System';
            }
            if ( $cron->schedule ) {
                $type_id              = 1930;
                $data['schedule']     = $cron->schedule;
                $schedule_info        = \wp_get_schedules()[ $cron->schedule ];
                $data['interval']     = $schedule_info['interval'];
                $data['display_name'] = $schedule_info['display'];
            }
            Changes_Logs_Logger::log_change( $type_id, $data );
            self::$executed_cron = false;
        }
        if (
        isset( self::$rescheduled_cron ) &&
        ! empty( self::$rescheduled_cron ) &&
        \is_object( $cron ) &&
        \is_object( self::$rescheduled_cron ) &&
        \property_exists( self::$rescheduled_cron, 'hook' ) &&
        \property_exists( $cron, 'hook' ) && self::$rescheduled_cron && self::$rescheduled_cron->hook === $cron->hook ) {
            $type_id         = 1935;
            $reschedule_info = \wp_get_schedules()[ $cron->schedule ];
            $schedule_info   = \wp_get_schedules()[ self::$rescheduled_cron->schedule ];
            $data            = array(
                'task_name'        => $cron->hook,
                'timestamp'        => Changes_Helper::get_formatted_date( $cron->timestamp ),
                'arguments'        => $cron->args,
                'currentuserid'    => Changes_Helper::get_user()->ID,
                'username'         => Changes_Helper::get_user()->user_login,
                'new_schedule'     => $cron->schedule,
                'old_schedule'     => self::$rescheduled_cron,
                'old_interval'     => $schedule_info['interval'],
                'new_interval'     => $reschedule_info['interval'],
                'old_display_name' => $schedule_info['display'],
                'new_display_name' => $reschedule_info['display'],
            );
            if ( empty( $data['currentuserid'] ) ) {
                unset( $data['currentuserid'] );
                $data['username'] = 'System';

            }
            Changes_Logs_Logger::log_change( $type_id, $data );
            self::$rescheduled_cron = false;
        }

        return $cron;
    }

    /**
     * Monitors and logs cron jobs creation.
     *
     * @param null|bool|WP_Error $pre       Value to return instead. Default null to continue unscheduling the event.
     * @param int                $timestamp Timestamp for when to run the event.
     * @param string             $hook      Action hook, the execution of which will be unscheduled.
     * @param array              $args      Arguments to pass to the hook's callback function.
     * @param bool               $wp_error  Whether to return a WP_Error on failure.
     *
     * @return null|bool|WP_Error
     */
    public static function callback_change_unschedule_cron_job( $pre, $timestamp, $hook, $args, $wp_error = false ) {

        if ( ! $pre && ! defined( 'DOING_CRON' ) ) {
            $type_id = 1950;

            $data = array(
                'task_name'     => $hook,
                'timestamp'     => Changes_Helper::get_formatted_date( $timestamp ),
                'arguments'     => $args,
                'currentuserid' => Changes_Helper::get_user()->ID,
                'username'      => Changes_Helper::get_user()->user_login,
            );

            if ( empty( $data['currentuserid'] ) ) {

                unset( $data['currentuserid'] );
                $data['username'] = 'System';

            }

            $cron = \wp_get_scheduled_event( $hook, $args, $timestamp );

            if ( false !== $cron && \is_object( $cron ) && $cron->schedule ) {
                $type_id              = 1955;
                $data['schedule']     = $cron->schedule;
                $schedule_info        = \wp_get_schedules()[ $cron->schedule ];
                $data['interval']     = $schedule_info['interval'];
                $data['display_name'] = $schedule_info['display'];
                Changes_Logs_Logger::log_change( $type_id, $data );
            }
        }

        return $pre;
    }

    /**
     * Stores option values before deletion.
     *
     * @param string $option - Name of the updated option.
     *
     * @return void
     */
    public static function callback_change_delete_option( $option ) {

        $option_value = \get_option( $option );

        if ( isset( $option ) ) {
            self::$old_option[ $option ] = array(
                'name'  => $option,
                'value' => $option_value,
            );
        }
    }

    /**
     * Monitors all the option deletes and triggers alerts.
     *
     * @param string $option - Name of the updated option.
     *
     * @return void
     */
    public static function callback_change_deleted_option( $option ) {

        // Site icon is changed.
        if ( 'site_icon' === $option ) {
            if ( ! empty( self::$old_option ) && isset( self::$old_option[ $option ] ) ) {

                $old_info = self::change_get_file_info_from_id( (int) self::$old_option[ $option ]['value'] );
                $log_data = array(
                    'old_attachment_id ' => (int) self::$old_option[ $option ]['value'],
                    'old_path'           => ( ( ! empty( $old_info ) && isset( $old_info['file_path'] ) ) ? $old_info['file_path'] : '' ),
                    'old_filename'       => ( ( ! empty( $old_info ) && isset( $old_info['file_name'] ) ) ? $old_info['file_name'] : '' ),
                    'old_attachment_url' => ( ( ! empty( $old_info ) && isset( $old_info['attachment_url'] ) ) ? $old_info['attachment_url'] : '' ),
                    'currentuserid'      => Changes_Helper::get_user()->ID,
                    'username'           => Changes_Helper::get_user()->user_login,
                );
                // Icon is removed.
                Changes_Logs_Logger::log_change( 1920, $log_data );
            } else {
                // Icon is removed.
                $log_data = array(
                    'old_attachment_id ' => 0,
                    'old_path'           => '',
                    'old_filename'       => '',
                    'old_attachment_url' => '',
                    'currentuserid'      => Changes_Helper::get_user()->ID,
                    'username'           => Changes_Helper::get_user()->user_login,
                );
                Changes_Logs_Logger::log_change( 1920, $log_data );
            }
        }
    }

    /**
     * Monitors all the option updates and triggers alerts.
     *
     * @param string $option - Name of the updated option.
     * @param mixed  $new_value - The new option value.
     *
     * @return void
     */
    public static function callback_change_added_option( $option, $new_value ) {

        // Site icon is added.
        if ( 'site_icon' === $option ) {
            $new_info = self::change_get_file_info_from_id( (int) $new_value );

                // New icon.
            if ( 0 !== (int) $new_value ) {
                $log_data = array(
                    'new_attachment_id ' => (int) $new_value,
                    'new_path'           => ( ( ! empty( $new_info ) && isset( $new_info['file_path'] ) ) ? $new_info['file_path'] : '' ),
                    'filename'           => ( ( ! empty( $new_info ) && isset( $new_info['file_name'] ) ) ? $new_info['file_name'] : '' ),
                    'attachment_url'     => ( ( ! empty( $new_info ) && isset( $new_info['attachment_url'] ) ) ? $new_info['attachment_url'] : '' ),
                    'currentuserid'      => Changes_Helper::get_user()->ID,
                    'username'           => Changes_Helper::get_user()->user_login,
                );
                Changes_Logs_Logger::log_change( 1915, $log_data );
            }
        }
    }

    /**
     * Monitors all the option updates and triggers alerts.
     *
     * @param string $option - Name of the updated option.
     * @param mixed  $old_value - The old option value.
     * @param mixed  $new_value - The new option value.
     *
     * @return void
     */
    public static function callback_change_updated_option( $option, $old_value, $new_value ) {

        // Site icon is changed.
        if ( 'site_icon' === $option ) {
            if ( (int) $old_value !== (int) $new_value ) {

                $old_info = self::change_get_file_info_from_id( (int) $old_value );
                $new_info = self::change_get_file_info_from_id( (int) $new_value );

                // New icon.
                if ( 0 === (int) $old_value ) {
                    $log_data = array(
                        'new_attachment_id ' => (int) $new_value,
                        'new_path'           => ( ( ! empty( $new_info ) && isset( $new_info['file_path'] ) ) ? $new_info['file_path'] : '' ),
                        'filename'           => ( ( ! empty( $new_info ) && isset( $new_info['file_name'] ) ) ? $new_info['file_name'] : '' ),
                        'attachment_url'     => ( ( ! empty( $new_info ) && isset( $new_info['attachment_url'] ) ) ? $new_info['attachment_url'] : '' ),
                        'currentuserid'      => Changes_Helper::get_user()->ID,
                        'username'           => Changes_Helper::get_user()->user_login,
                    );
                    Changes_Logs_Logger::log_change( 1915, $log_data );
                }

                // Icon is changed.
                if ( $new_value && $old_value ) {
                    $log_data = array(
                        'old_attachment_id ' => (int) $old_value,
                        'new_attachment_id ' => (int) $new_value,
                        'old_path'           => ( ( ! empty( $old_info ) && isset( $old_info['file_path'] ) ) ? $old_info['file_path'] : '' ),
                        'new_path'           => ( ( ! empty( $new_info ) && isset( $new_info['file_path'] ) ) ? $new_info['file_path'] : '' ),
                        'old_filename'       => ( ( ! empty( $old_info ) && isset( $old_info['file_name'] ) ) ? $old_info['file_name'] : '' ),
                        'filename'           => ( ( ! empty( $new_info ) && isset( $new_info['file_name'] ) ) ? $new_info['file_name'] : '' ),
                        'old_attachment_url' => ( ( ! empty( $old_info ) && isset( $old_info['attachment_url'] ) ) ? $old_info['attachment_url'] : '' ),
                        'attachment_url'     => ( ( ! empty( $new_info ) && isset( $new_info['attachment_url'] ) ) ? $new_info['attachment_url'] : '' ),
                        'currentuserid'      => Changes_Helper::get_user()->ID,
                        'username'           => Changes_Helper::get_user()->user_login,
                    );
                    Changes_Logs_Logger::log_change( 1920, $log_data );
                }
            }
        }
    }

    /**
     * Helper method to extract file info from attachment.
     *
     * @param integer $attachment_id - The attachment ID.
     *
     * @return array - Returns empty array if no attachment is found or wrong:
     * [
     *    'file_name'      => string,
     *    'file_path'      => string,
     *    'attachment_url' => string,
     * ]
     */
    private static function change_get_file_info_from_id( int $attachment_id ) {
        $info = array();

        $file = \get_attached_file( $attachment_id );
        if ( false !== $file ) {
            $info = array(
                'file_name'      => basename( $file ),
                'file_path'      => dirname( $file ),
                'attachment_url' => \wp_get_attachment_url( $attachment_id ),
            );
        }

        return $info;
    }

    /**
     * Log: Admin email changed.
     *
     * @param mixed  $old_value - The old option value.
     * @param mixed  $new_value - The new option value.
     * @param string $option    - Option name.
     */
    public static function callback_change_admin_email( $old_value, $new_value, $option ) {
        if ( ! empty( $old_value ) && ! empty( $new_value )
        && ! empty( $option ) && 'admin_email' === $option && $old_value !== $new_value ) {
            $log_data = array(
                'oldemail'      => $old_value,
                'newemail'      => $new_value,
                'currentuserid' => Changes_Helper::get_user()->ID,
            );
            Changes_Logs_Logger::log_change( 1795, $log_data );
        }
    }

    /**
     * When a user accesses the admin area.
     */
    public static function callback_change_admin_init() {

        if ( ! current_user_can( 'manage_options' ) ) {
            return;
        }

        $post_vars   = filter_input_array( INPUT_POST );
        $get_vars    = filter_input_array( INPUT_GET );
        $server_vars = filter_input_array( INPUT_SERVER );

        $actype = '';
        if ( ! empty( $server_vars['SCRIPT_NAME'] ) ) {
            $actype = basename( \sanitize_text_field( \wp_unslash( $server_vars['SCRIPT_NAME'] ) ), '.php' );
        }

        if ( isset( $post_vars['action'] ) && 'toggle-auto-updates' === $post_vars['action'] ) {
            $type_id = ( 'theme' === $post_vars['type'] ) ? 1470 : 1460;

            $asset = \sanitize_text_field( \wp_unslash( $post_vars['asset'] ) );

            if ( 'theme' === $post_vars['type'] ) {
                $all_themes       = \wp_get_themes();
                $our_theme        = ( isset( $all_themes[ $asset ] ) ) ? $all_themes[ $asset ] : '';
                $install_location = $our_theme->get_template_directory();
                $name             = $our_theme->Name; // phpcs:ignore
            } elseif ( 'plugin' === $post_vars['type'] ) {
                $all_plugins = \get_plugins();
                if ( ! is_wp_error( validate_plugin( $asset ) ) ) {
                    $our_plugin       = ( isset( $all_plugins[ $asset ] ) ) ? $all_plugins[ $asset ] : '';
                    $install_location = \plugin_dir_path( WP_PLUGIN_DIR . '/' . $asset );
                    $name             = $our_plugin['Name'];
                }
            }

            if ( isset( $name ) ) {
                $log_data = array(
                    'install_directory' => $install_location,
                    'name'              => $name,
                    'actionname'        => ( 'enable' === $post_vars['state'] ) ? 'enabled' : 'disabled',
                );
                Changes_Logs_Logger::log_change( $type_id, $log_data );
            }
        }

        $is_option_page      = 'options' === $actype;
        $is_network_settings = 'settings' === $actype;
        $is_permalink_page   = 'options-permalink' === $actype;

        // WordPress URL changed.
        if ( $is_option_page && isset( $post_vars['_wpnonce'] )
        && \wp_verify_nonce( $post_vars['_wpnonce'], 'general-options' )
        && ! empty( $post_vars['siteurl'] ) ) {
            $old_siteurl = \get_option( 'siteurl' );
            $new_siteurl = isset( $post_vars['siteurl'] ) ? \sanitize_text_field( \wp_unslash( $post_vars['siteurl'] ) ) : '';
            if ( $old_siteurl !== $new_siteurl ) {
                $log_data = array(
                    'old_url'       => $old_siteurl,
                    'new_url'       => $new_siteurl,
                    'currentuserid' => Changes_Helper::get_user()->ID,
                );
                Changes_Logs_Logger::log_change( 1860, $log_data );
            }
        }

        // Site URL changed.
        if ( $is_option_page && isset( $post_vars['_wpnonce'] )
        && \wp_verify_nonce( $post_vars['_wpnonce'], 'general-options' )
        && ! empty( $post_vars['home'] ) ) {
            $old_url = \get_option( 'home' );
            $new_url = isset( $post_vars['home'] ) ? \sanitize_text_field( \wp_unslash( $post_vars['home'] ) ) : '';
            if ( $old_url !== $new_url ) {
                $log_data = array(
                    'old_url'       => $old_url,
                    'new_url'       => $new_url,
                    'currentuserid' => Changes_Helper::get_user()->ID,
                );
                Changes_Logs_Logger::log_change( 1865, $log_data );
            }
        }

        if ( isset( $post_vars['option_page'] ) && 'reading' === $post_vars['option_page'] && isset( $post_vars['show_on_front'] ) && isset( $post_vars['_wpnonce'] )
        && wp_verify_nonce( $post_vars['_wpnonce'], 'reading-options' ) ) {
            $old_homepage = ( 'posts' === get_site_option( 'show_on_front' ) ) ? __( 'latest posts', 'mainwp-child' ) : __( 'static page', 'mainwp-child' );
            $new_homepage = ( 'posts' === $post_vars['show_on_front'] ) ? __( 'latest posts', 'mainwp-child' ) : __( 'static page', 'mainwp-child' );
            if ( $old_homepage !== $new_homepage ) {
                $log_data = array(
                    'old_homepage' => $old_homepage,
                    'new_homepage' => $new_homepage,
                );
                Changes_Logs_Logger::log_change( 1870, $log_data );
            }
        }

        if ( isset( $post_vars['option_page'] ) && 'reading' === $post_vars['option_page'] && isset( $post_vars['page_on_front'] ) && isset( $post_vars['_wpnonce'] )
        && wp_verify_nonce( $post_vars['_wpnonce'], 'reading-options' ) ) {
            $old_frontpage = get_the_title( get_site_option( 'page_on_front' ) );
            $new_frontpage = get_the_title( $post_vars['page_on_front'] );
            if ( $old_frontpage !== $new_frontpage ) {
                $log_data = array(
                    'old_page' => $old_frontpage,
                    'new_page' => $new_frontpage,
                );
                Changes_Logs_Logger::log_change( 1875, $log_data );
            }
        }

        if ( isset( $post_vars['option_page'] ) && 'reading' === $post_vars['option_page'] && isset( $post_vars['page_for_posts'] ) && isset( $post_vars['_wpnonce'] )
        && wp_verify_nonce( $post_vars['_wpnonce'], 'reading-options' ) ) {
            $old_postspage = get_the_title( get_site_option( 'page_for_posts' ) );
            $new_postspage = get_the_title( $post_vars['page_for_posts'] );
            if ( $old_postspage !== $new_postspage ) {
                $log_data = array(
                    'old_page' => $old_postspage,
                    'new_page' => $new_postspage,
                );
                Changes_Logs_Logger::log_change( 1880, $log_data );
            }
        }

        // Check timezone change.
        if ( $is_option_page && isset( $post_vars['_wpnonce'] ) && wp_verify_nonce( $post_vars['_wpnonce'], 'general-options' ) && ! empty( $post_vars['timezone_string'] ) ) {
            static::change_check_timezone( $post_vars );
        }

        // Check date format change.
        if ( $is_option_page && isset( $post_vars['_wpnonce'] ) && wp_verify_nonce( $post_vars['_wpnonce'], 'general-options' ) && ! empty( $post_vars['date_format'] ) ) {
            $old_date_format = get_option( 'date_format' );
            $new_date_format = ( '\c\u\s\t\o\m' === $post_vars['date_format'] ) ? \sanitize_text_field( \wp_unslash( $post_vars['date_format_custom'] ) ) : \sanitize_text_field( \wp_unslash( $post_vars['date_format'] ) );
            if ( $old_date_format !== $new_date_format ) {
                $log_data = array(
                    'old_date_format' => $old_date_format,
                    'new_date_format' => $new_date_format,
                    'currentuserid'   => Changes_Helper::get_user()->ID,
                );
                Changes_Logs_Logger::log_change( 1890, $log_data );
            }
        }

        // Check time format change.
        if ( $is_option_page && isset( $post_vars['_wpnonce'] ) && wp_verify_nonce( $post_vars['_wpnonce'], 'general-options' ) && ! empty( $post_vars['time_format'] ) ) {
            $old_time_format = get_option( 'time_format' );
            $new_time_format = ( '\c\u\s\t\o\m' === $post_vars['time_format'] ) ? \sanitize_text_field( \wp_unslash( $post_vars['time_format_custom'] ) ) : \sanitize_text_field( \wp_unslash( $post_vars['time_format'] ) );
            if ( $old_time_format !== $new_time_format ) {
                $log_data = array(
                    'old_time_format' => $old_time_format,
                    'new_time_format' => $new_time_format,
                    'currentuserid'   => Changes_Helper::get_user()->ID,
                );
                Changes_Logs_Logger::log_change( 1895, $log_data );
            }
        }

        // Registration Option.
        if ( $is_option_page && isset( $post_vars['_wpnonce'] ) && wp_verify_nonce( $post_vars['_wpnonce'], 'general-options' ) && ( get_option( 'users_can_register' ) xor isset( $post_vars['users_can_register'] ) ) ) {
            $old = get_option( 'users_can_register' ) ? 'enabled' : 'disabled';
            $new = isset( $post_vars['users_can_register'] ) ? 'enabled' : 'disabled';

            if ( $old !== $new ) {
                $log_data = array(
                    'actionname'    => $new,
                    'currentuserid' => Changes_Helper::get_user()->ID,
                );
                Changes_Logs_Logger::log_change( 1785, $log_data );
            }
        }

        // Default Role option.
        if ( $is_option_page && isset( $post_vars['_wpnonce'] ) && wp_verify_nonce( $post_vars['_wpnonce'], 'general-options' ) && ! empty( $post_vars['default_role'] ) ) {
            $old = get_option( 'default_role' );
            $new = trim( \sanitize_text_field( \wp_unslash( $post_vars['default_role'] ) ) );
            if ( $old !== $new ) {
                $log_data = array(
                    'oldrole'       => $old,
                    'newrole'       => $new,
                    'currentuserid' => Changes_Helper::get_user()->ID,
                );
                Changes_Logs_Logger::log_change( 1790, $log_data );
            }
        }

        // Admin Email Option.
        if ( $is_option_page && isset( $post_vars['_wpnonce'] ) && wp_verify_nonce( $post_vars['_wpnonce'], 'general-options' ) && ! empty( $post_vars['admin_email'] ) ) {
            $old = get_option( 'admin_email' );
            $new = trim( \sanitize_text_field( \wp_unslash( $post_vars['admin_email'] ) ) );
            if ( $old !== $new ) {
                $log_data = array(
                    'oldemail'      => $old,
                    'newemail'      => $new,
                    'currentuserid' => Changes_Helper::get_user()->ID,
                );
                Changes_Logs_Logger::log_change( 1795, $log_data );
            }
        }

        // Admin Email of Network.
        if ( $is_network_settings && isset( $post_vars['_wpnonce'] ) && ! empty( $post_vars['new_admin_email'] ) && wp_verify_nonce( $post_vars['_wpnonce'], 'siteoptions' ) ) {
            $old = get_site_option( 'admin_email' );
            $new = trim( \sanitize_text_field( \wp_unslash( $post_vars['new_admin_email'] ) ) );
            if ( $old !== $new ) {
                $log_data = array(
                    'oldemail'      => $old,
                    'newemail'      => $new,
                    'currentuserid' => Changes_Helper::get_user()->ID,
                );
                Changes_Logs_Logger::log_change( 1795, $log_data );
            }
        }

        // Permalinks changed.
        if ( $is_permalink_page && ! empty( $post_vars['permalink_structure'] ) && isset( $post_vars['_wpnonce'] )
        && wp_verify_nonce( $post_vars['_wpnonce'], 'update-permalink' ) ) {
            $old = get_option( 'permalink_structure' );
            $new = trim( \sanitize_text_field( \wp_unslash( $post_vars['permalink_structure'] ) ) );
            if ( $old !== $new ) {
                $log_data = array(
                    'oldpattern'    => $old,
                    'newpattern'    => $new,
                    'currentuserid' => Changes_Helper::get_user()->ID,
                );
                Changes_Logs_Logger::log_change( 1800, $log_data );
            }
        }

        // Enable core updates.
        if ( isset( $get_vars['action'] ) && 'core-major-auto-updates-settings' === $get_vars['action'] && isset( $get_vars['value'] )
        && wp_verify_nonce( $get_vars['_wpnonce'], 'core-major-auto-updates-nonce' ) ) {
            $status   = ( 'enable' === $get_vars['value'] ) ? esc_html__( 'automatically update to all new versions of WordPress', 'mainwp-child' ) : esc_html__( 'automatically update maintenance and security releases only', 'mainwp-child' );
            $log_data = array(
                'updates_status' => $status,
            );
            Changes_Logs_Logger::log_change( 1900, $log_data );
        }

        // Site Language changed.
        if ( $is_option_page && isset( $post_vars['_wpnonce'] )
        && wp_verify_nonce( $post_vars['_wpnonce'], 'general-options' )
        && isset( $post_vars['WPLANG'] ) ) {
            require_once ABSPATH . 'wp-admin/includes/translation-install.php';
            $available_translations = wp_get_available_translations();

            // When English (United States) is selected, the WPLANG post entry is empty so lets account for this.
            $wplang_setting = get_option( 'WPLANG' );
            $previous_value = ( ! empty( $wplang_setting ) ) ? $wplang_setting : 'en-US';
            $new_value      = ( ! empty( $post_vars['WPLANG'] ) ) ? \sanitize_text_field( \wp_unslash( $post_vars['WPLANG'] ) ) : 'en-US';

            $previous_value = ( isset( $available_translations[ $previous_value ] ) ) ? $available_translations[ $previous_value ]['native_name'] : 'English (United States)';
            $new_value      = ( isset( $available_translations[ $new_value ] ) ) ? $available_translations[ $new_value ]['native_name'] : 'English (United States)';

            if ( $previous_value !== $new_value ) {
                $log_data = array(
                    'previous_value' => $previous_value,
                    'new_value'      => $new_value,
                );
                Changes_Logs_Logger::log_change( 1905, $log_data );
            }
        }

        // Site title.
        if ( $is_option_page && isset( $post_vars['_wpnonce'] )
        && wp_verify_nonce( $post_vars['_wpnonce'], 'general-options' )
        && isset( $post_vars['blogname'] ) ) {
            $previous_value = get_option( 'blogname' );
            $new_value      = ( ! empty( $post_vars['blogname'] ) ) ? \sanitize_text_field( \wp_unslash( $post_vars['blogname'] ) ) : '';

            if ( $previous_value !== $new_value ) {
                $log_data = array(
                    'previous_value' => $previous_value,
                    'new_value'      => $new_value,
                );
                Changes_Logs_Logger::log_change( 1910, $log_data );
            }
        }
    }

    /**
     * Events options.
     *
     * @param array $whitelist - White list options.
     *
     * @return array|null
     */
    public static function callback_change_options( $whitelist = null ) {
        $post_vars = filter_input_array( INPUT_POST );

        if ( isset( $post_vars['option_page'] ) && 'reading' === $post_vars['option_page'] ) {
            $old_status = (int) get_option( 'blog_public', 1 );
            $new_status = isset( $post_vars['blog_public'] ) ? 0 : 1;
            if ( $old_status !== $new_status ) {
                $log_data = array( 'actionname' => ( 0 === $new_status ) ? 'enabled' : 'disabled' );
                Changes_Logs_Logger::log_change( 1805, $log_data );
            }
        }

        if ( isset( $post_vars['option_page'] ) && 'discussion' === $post_vars['option_page'] ) {
            $old_status = get_option( 'default_comment_status', 'closed' );
            $new_status = isset( $post_vars['default_comment_status'] ) ? 'open' : 'closed';

            if ( $old_status !== $new_status ) {
                $log_data = array( 'actionname' => ( 'open' === $new_status ) ? 'enabled' : 'disabled' );
                Changes_Logs_Logger::log_change( 1810, $log_data );
            }

            $old_status = (int) get_option( 'require_name_email', 0 );
            $new_status = isset( $post_vars['require_name_email'] ) ? 1 : 0;

            if ( $old_status !== $new_status ) {
                $log_data = array( 'actionname' => ( 1 === $new_status ) ? 'enabled' : 'disabled' );
                Changes_Logs_Logger::log_change( 1815, $log_data );
            }

            $old_status = (int) get_option( 'comment_registration', 0 );
            $new_status = isset( $post_vars['comment_registration'] ) ? 1 : 0;

            if ( $old_status !== $new_status ) {
                $log_data = array( 'actionname' => ( 1 === $new_status ) ? 'enabled' : 'disabled' );
                Changes_Logs_Logger::log_change( 1820, $log_data );
            }

            $old_status = (int) get_option( 'close_comments_for_old_posts', 0 );
            $new_status = isset( $post_vars['close_comments_for_old_posts'] ) ? 1 : 0;

            if ( $old_status !== $new_status ) {
                $value    = isset( $post_vars['close_comments_days_old'] ) ? \sanitize_text_field( \wp_unslash( $post_vars['close_comments_days_old'] ) ) : 0;
                $log_data = array(
                    'actionname' => ( 1 === $new_status ) ? 'enabled' : 'disabled',
                    'value'      => $value,
                );
                Changes_Logs_Logger::log_change( 1825, $log_data );
            }

            $old_value = get_option( 'close_comments_days_old', 0 );
            $new_value = isset( $post_vars['close_comments_days_old'] ) ? \sanitize_text_field( \wp_unslash( $post_vars['close_comments_days_old'] ) ) : 0;
            if ( $old_value !== $new_value ) {
                $log_data = array(
                    'oldvalue' => $old_value,
                    'newvalue' => $new_value,
                );
                Changes_Logs_Logger::log_change( 1830, $log_data );
            }

            $old_status = (int) get_option( 'comment_moderation', 0 );
            $new_status = isset( $post_vars['comment_moderation'] ) ? 1 : 0;

            if ( $old_status !== $new_status ) {
                $log_data = array( 'actionname' => ( 1 === $new_status ) ? 'enabled' : 'disabled' );
                Changes_Logs_Logger::log_change( 1835, $log_data );
            }

            $comment_whitelist_option_name = version_compare( get_bloginfo( 'version' ), '5.5.0', '<' ) ? 'comment_whitelist' : 'comment_previously_approved';
            $old_status                    = (int) get_option( $comment_whitelist_option_name, 0 );
            $new_status                    = isset( $post_vars[ $comment_whitelist_option_name ] ) ? 1 : 0;

            if ( $old_status !== $new_status ) {
                $log_data = array( 'actionname' => ( 1 === $new_status ) ? 'enabled' : 'disabled' );
                Changes_Logs_Logger::log_change( 1840, $log_data );
            }

            $old_value = get_option( 'comment_max_links', 0 );
            $new_value = isset( $post_vars['comment_max_links'] ) ? \sanitize_text_field( \wp_unslash( $post_vars['comment_max_links'] ) ) : 0;
            if ( $old_value !== $new_value ) {
                $log_data = array(
                    'oldvalue' => $old_value,
                    'newvalue' => $new_value,
                );
                Changes_Logs_Logger::log_change( 1845, $log_data );
            }

            $old_value = get_option( 'moderation_keys', 0 );
            $new_value = isset( $post_vars['moderation_keys'] ) ? \sanitize_text_field( \wp_unslash( $post_vars['moderation_keys'] ) ) : 0;
            if ( $old_value !== $new_value ) {
                Changes_Logs_Logger::log_change( 1850, array() );
            }

            // Blacklist_keys option was renamed to disallowed_keys in WordPress 5.5.0.
            $blacklist_keys_option_name = version_compare( get_bloginfo( 'version' ), '5.5.0', '<' ) ? 'blacklist_keys' : 'disallowed_keys';
            $old_value                  = get_option( $blacklist_keys_option_name, 0 );
            $new_value                  = isset( $post_vars[ $blacklist_keys_option_name ] ) ? \sanitize_text_field( \wp_unslash( $post_vars[ $blacklist_keys_option_name ] ) ) : 0;
            if ( $old_value !== $new_value ) {
                Changes_Logs_Logger::log_change( 1855, array() );
            }
        }
        return $whitelist;
    }

    /**
     * Site blogname change via customizable theme page
     *
     * @param \WP_Customize_Setting $customizable - The class with the customizable properties.
     *
     * @return void
     */
    public static function callback_change_site_blogname( $customizable ) {
        if ( isset( $_POST['customized'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing
            $post_values = json_decode( \sanitize_text_field( \wp_unslash( $_POST['customized'] ) ), true ); // phpcs:ignore WordPress.Security.NonceVerification.Missing

            if ( $customizable->default !== $post_values['blogname'] ) {
                $log_data = array(
                    'previous_value' => $customizable->default,
                    'new_value'      => $post_values['blogname'],
                );
                Changes_Logs_Logger::log_change( 1910, $log_data );
            }
        }
    }

    /**
     * Checks if the timezone settings have changed. Logs an events if it did.
     *
     * @param array $post_vars Sanitized input array.
     */
    private static function change_check_timezone( $post_vars ) {
        $old_timezone_string = get_option( 'timezone_string' );
        $new_timezone_string = isset( $post_vars['timezone_string'] ) ? \sanitize_text_field( \wp_unslash( $post_vars['timezone_string'] ) ) : '';

        $old_timezone_label = $old_timezone_string;
        $new_timezone_label = $new_timezone_string;
        if ( strlen( $old_timezone_string ) === 0 ) {
            $old_timezone_string = $old_timezone_label = wp_timezone_string(); // phpcs:ignore Squiz.PHP.DisallowMultipleAssignments.Found
            if ( 'UTC' === $old_timezone_string ) {
                $old_timezone_string = '+00:00';
            }

            $old_timezone_label = 'UTC' . $old_timezone_label;
        }

        // New timezone can be defined as UTC offset.
        if ( 'UTC' === $new_timezone_string ) {
            $new_timezone_string = 'UTC+0';
        }

        if ( preg_match( '/UTC([+\-][0-9\.]+)/', $new_timezone_string, $matches ) ) {
            $hours_decimal = floatval( $matches[1] );

            $sign                = $hours_decimal < 0 ? '-' : '+';
            $abs_hours           = abs( $hours_decimal );
            $abs_mins            = abs( $hours_decimal * 60 % 60 );
            $new_timezone_string = sprintf( '%s%02d:%02d', $sign, floor( $abs_hours ), $abs_mins );

            $new_timezone_label = 'UTC' . $new_timezone_string;
        }

        if ( $old_timezone_string !== $new_timezone_string ) {
            $log_data = array(
                'old_timezone'  => $old_timezone_label,
                'new_timezone'  => $new_timezone_label,
                'currentuserid' => Changes_Helper::change_get_current_user()->ID,
            );
            Changes_Logs_Logger::log_change( 1885, $log_data );
        }
    }
}
