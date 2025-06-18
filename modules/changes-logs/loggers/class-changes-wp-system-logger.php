<?php
/**
 * Logger: System
 *
 * System logger class file.
 *
 * @since      4.6.0
 * @package    mainwp/child
 */

declare(strict_types=1);

namespace MainWP\Child\Changes\Loggers;

use MainWP\Child\Changes\Helpers\Changes_User_Helper;
use MainWP\Child\Changes\Changes_Logs_Manager;
use MainWP\Child\Changes\Helpers\Changes_DateTime_Formatter_Helper;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}


/**
 * System Activity sensor.
 *
 * 6000 Events automatically pruned by system
 * 6001 Option Anyone Can Register in WordPress settings changed
 * 6002 New User Default Role changed
 * 6003 WordPress Administrator Notification email changed
 * 6004 WordPress was updated
 * 6005 User changes the WordPress Permalinks
 * 8009 User changed forum's role
 * 8010 User changed option of a forum
 * 8012 User changed time to disallow post editing
 * 8013 User changed the forum setting posting throttle time
 * 1006 User logged out all other sessions with the same username
 * 6004 WordPress was updated
 * 6008 Enabled/Disabled the option Discourage search engines from indexing this site
 * 6009 Enabled/Disabled comments on all the website
 * 6010 Enabled/Disabled the option Comment author must fill out name and email
 * 6011 Enabled/Disabled the option Users must be logged in and registered to comment
 * 6012 Enabled/Disabled the option to automatically close comments
 * 6013 Changed the value of the option Automatically close comments
 * 6014 Enabled/Disabled the option for comments to be manually approved
 * 6015 Enabled/Disabled the option for an author to have previously approved comments for the comments to appear
 * 6016 Changed the number of links that a comment must have to be held in the queue
 * 6017 Modified the list of keywords for comments moderation
 * 6018 Modified the list of keywords for comments blacklisting
 * 6061 Email was sent
 * 6064 Email was sent
 */
class Changes_WP_System_Logger {

    /**
     * Keeps the value of the old option.
     *
     * @var array
     *
     * @since 5.1.0
     */
    private static $old_option = array();

    /**
     * The executed cron is removed BEFORE execution, so we have to keep the value of it.
     *
     * @var \StdClass
     *
     * @since 5.1.0
     */
    private static $executed_cron = false;

    /**
     * The rescheduled cron is holding the old (original) value of the event.
     *
     * @var \StdClass
     *
     * @since 5.1.0
     */
    private static $rescheduled_cron = false;

    /**
     * Inits the main hooks
     *
     * @return void
     *
     */
    public static function init() {
        \add_action( 'mainwp_child_changes_logs_prune', array( __CLASS__, 'event_prune_events' ), 10, 2 );
        \add_action( 'admin_init', array( __CLASS__, 'event_admin_init' ) );
        \add_action( 'automatic_updates_complete', array( __CLASS__, 'wp_update' ), 10, 1 );

        // whitelist options.
        \add_action( 'allowed_options', array( __CLASS__, 'event_options' ), 10, 1 );

        // Update admin email alert.
        \add_action( 'update_option_admin_email', array( __CLASS__, 'admin_email_changed' ), 10, 3 );

        // Customizable settings for the dynamic theme editing start.
        // Blogname change.
        \add_action( 'customize_save_blogname', array( __CLASS__, 'site_blogname_change' ), 20, 1 );

        /**
         * Options sensors - most of that class must be switched to this way of logging.
         */
        \add_action( 'updated_option', array( __CLASS__, 'updated_option' ), 10, 3 );
        \add_action( 'added_option', array( __CLASS__, 'added_option' ), 10, 2 );
        // Use that to store the current option value, before the actual deletion.
        \add_action( 'delete_option', array( __CLASS__, 'delete_option' ) );
        // Option is deleted.
        \add_action( 'deleted_option', array( __CLASS__, 'deleted_option' ) );
    }

    /**
     * Loads all the plugin dependencies early.
     *
     * @return void
     *
     * @since 5.1.0
     */
    public static function early_init() {

        \add_action( 'wp_mail_succeeded', array( __CLASS__, 'mail_was_sent' ) );

        \add_action( 'wp_ajax_destroy-sessions', array( __CLASS__, 'on_destroy_user_session' ), 0 );

        /**
         * Cron events
         *
         * The WP cron logic is extremely complicated - in order to reschedule they delete and create a new event - so we inside the wp-cron they set last method variable to true (wp_error) and first to null - if there is an error they expect first (result of the action call) to be populated with error if there is one and then they check the error, otherwise because the wp_error is set from withing wp-cron to true, they know that this is a call from the wp-cron itself and not someone else creating / recreating event.
         * Pretty much same applies to everything which is coming from wp-cron.php
         *
         * Our logic is also complicated - we are attaching ourselves to pre_schedule_event
         * Check for the first param to be null and wp_error param to be false - if that is the case, we are assigning the cron class to the class variable and then check for it when schedule_event is called - if there is one set - that means that this call is not from wp-cron so we can log the event.
         */
        $self = __CLASS__;
        \add_action(
            'pre_schedule_event',
            function ( $pre, $hook, $wp_error = false ) use ( &$self ) {
                if ( null === $pre && ! defined( 'DOING_CRON' ) ) {
                    $self::set_executed_cron( $hook );
                }
            },
            PHP_INT_MAX,
            2
        );
        \add_action(
            'pre_reschedule_event',
            function ( $pre, $hook, $wp_error = false ) use ( &$self ) {
                if ( null === $pre && ! defined( 'DOING_CRON' ) ) {
                    $self::set_rescheduled_cron( \wp_get_scheduled_event( $hook->hook, $hook->args ) );
                }
            },
            PHP_INT_MAX,
            2
        );
        \add_action( 'schedule_event', array( __CLASS__, 'created_cron_job' ) );
        \add_action( 'pre_unschedule_event', array( __CLASS__, 'removed_cron_job' ), PHP_INT_MAX, 4 );

        self::attach_cron_actions();
    }

    /**
     * User sessions destroy event.
     *
     * @return void
     *
     * @since 5.2.1
     */
    public static function on_destroy_user_session() {

        if ( ! isset( $_POST['user_id'] ) ) {
            return;
        }

        $user = \get_userdata( (int) $_POST['user_id'] ); // phpcs:ignore WordPress.Security.NonceVerification.Missing

        if ( $user ) {
            if ( ! \current_user_can( 'edit_user', $user->ID ) ) {
                $user = false;
            } elseif ( isset( $_POST['nonce'] ) && ! \wp_verify_nonce( $_POST['nonce'], 'update-user_' . $user->ID ) ) { // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.MissingUnslash, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
                $user = false;
            }
        }

        if ( $user ) {
            // Destroy all the session of the same user from user profile page.

            Changes_Logs_Manager::trigger_event(
                1006,
                array(
                    'TargetUserID' => $user->ID,
                )
            );
        }
    }

    /**
     * Setter class method to set a value to the class variable
     *
     * @param \StdClass $executed_cron - The cron object.
     *
     * @return void
     *
     * @since 5.1.0
     */
    public static function set_executed_cron( $executed_cron ) {
        self::$executed_cron = $executed_cron;
    }

    /**
     * Setter class method to set a value to the class variable
     *
     * @param \StdClass $rescheduled_cron - The cron object.
     *
     * @return void
     *
     * @since 5.1.0
     */
    public static function set_rescheduled_cron( $rescheduled_cron ) {
        self::$rescheduled_cron = $rescheduled_cron;
    }

    /**
     * Called when the original wp-cron.php gets executed and tries to extract data from it.
     *
     * @return void
     *
     * @since 5.1.0
     */
    public static function attach_cron_actions() {
        if ( defined( 'DOING_CRON' ) ) {

            $self = __CLASS__;

            /**
            *
            * The WP cron logic is extremely complicated - in order to execute the event they first delete that event from the internal array and only then calling the assigned action, unfortunately at this point is to late to extract the event info which we need to log.
            *
            * Our logic is also complicated - we are attaching ourselves to pre_unschedule_event
            * Then storing that event before it get deleted in the class variable, and later on using that event info to properly populate our logs.
            */
            \add_action(
                'pre_unschedule_event',
                function ( $pre, $timestamp, $hook, $args, $wp_error ) use ( &$self ) {
                    $self::set_executed_cron( \wp_get_scheduled_event( $hook, $args, $timestamp ) );
                },
                PHP_INT_MAX,
                5
            );

            $crons = \wp_get_ready_cron_jobs();

            foreach ( $crons  as $timestamp => $cronhooks ) {

                foreach ( $cronhooks as $hook => $keys ) {
                    foreach ( $keys as $k => $v ) {
                        \add_action(
                            $hook,
                            function () use ( $hook ) {

                                if ( self::$executed_cron && self::$executed_cron->hook === $hook ) {
                                    $alert = 6069;

                                    $cron = self::$executed_cron;

                                    $data = array(
                                        'task_name'     => $cron->hook,
                                        'timestamp'     => Changes_DateTime_Formatter_Helper::get_formatted_date_time( $cron->timestamp, 'datetime', true, false ),
                                        'arguments'     => $cron->args,
                                        'CurrentUserID' => Changes_User_Helper::get_current_user()->ID,
                                        'Username'      => Changes_User_Helper::get_current_user()->user_login,
                                    );

                                    if ( empty( $data['CurrentUserID'] ) ) {

                                        unset( $data['CurrentUserID'] );
                                        $data['Username'] = 'System';

                                    }

                                    if ( $cron->schedule ) {
                                        $alert                = 6070;
                                        $data['schedule']     = $cron->schedule;
                                        $schedule_info        = isset( \wp_get_schedules()[ $cron->schedule ] ) ? \wp_get_schedules()[ $cron->schedule ] : false;
                                        $data['interval']     = ( isset( $schedule_info ) && \is_array( $schedule_info ) ) ? $schedule_info['interval'] : 0;
                                        $data['display_name'] = ( isset( $schedule_info ) && \is_array( $schedule_info ) ) ? $schedule_info['display'] : __( 'Unknown or Invalid (non-existing)', 'mainwp-child' );
                                    }

                                    Changes_Logs_Manager::trigger_event(
                                        $alert,
                                        $data
                                    );

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
     * Monitors and logs cron jobs creation.
     *
     * @param \StdClass $cron - The cron class instance of a cron job created.
     *
     * @return \StdClass $cron
     *
     * @since 5.1.0
     */
    public static function created_cron_job( $cron ) {
        if ( false === $cron ) {
            return $cron;
        }
        if (
            isset( self::$executed_cron ) &&
            ! empty( self::$executed_cron ) &&
            \is_object( $cron ) &&
            \is_object( self::$executed_cron ) &&
            \property_exists( self::$executed_cron, 'hook' ) &&
            \property_exists( $cron, 'hook' ) ) {

            if ( self::$executed_cron && self::$executed_cron->hook === $cron->hook ) {
                $alert = 6066;

                $data = array(
                    'task_name'     => $cron->hook,
                    'timestamp'     => Changes_DateTime_Formatter_Helper::get_formatted_date_time( $cron->timestamp, 'datetime', true, false ),
                    'arguments'     => $cron->args,
                    'CurrentUserID' => Changes_User_Helper::get_user()->ID,
                    'Username'      => Changes_User_Helper::get_user()->user_login,
                );

                if ( empty( $data['CurrentUserID'] ) ) {

                    unset( $data['CurrentUserID'] );
                    $data['Username'] = 'System';

                }

                if ( $cron->schedule ) {
                    $alert                = 6067;
                    $data['schedule']     = $cron->schedule;
                    $schedule_info        = \wp_get_schedules()[ $cron->schedule ];
                    $data['interval']     = $schedule_info['interval'];
                    $data['display_name'] = $schedule_info['display'];
                }

                Changes_Logs_Manager::trigger_event(
                    $alert,
                    $data
                );

                self::$executed_cron = false;
            }
        }
        if (
        isset( self::$rescheduled_cron ) &&
        ! empty( self::$rescheduled_cron ) &&
        \is_object( $cron ) &&
        \is_object( self::$rescheduled_cron ) &&
        \property_exists( self::$rescheduled_cron, 'hook' ) &&
        \property_exists( $cron, 'hook' ) ) {
            if ( self::$rescheduled_cron && self::$rescheduled_cron->hook === $cron->hook ) {
                $alert           = 6068;
                $reschedule_info = \wp_get_schedules()[ $cron->schedule ];
                $schedule_info   = \wp_get_schedules()[ self::$rescheduled_cron->schedule ];
                $data            = array(
                    'task_name'        => $cron->hook,
                    'timestamp'        => Changes_DateTime_Formatter_Helper::get_formatted_date_time( $cron->timestamp, 'datetime', true, false ),
                    'arguments'        => $cron->args,
                    'CurrentUserID'    => Changes_User_Helper::get_user()->ID,
                    'Username'         => Changes_User_Helper::get_user()->user_login,
                    'new_schedule'     => $cron->schedule,
                    'old_schedule'     => self::$rescheduled_cron,
                    'old_interval'     => $schedule_info['interval'],
                    'new_interval'     => $reschedule_info['interval'],
                    'old_display_name' => $schedule_info['display'],
                    'new_display_name' => $reschedule_info['display'],
                );

                if ( empty( $data['CurrentUserID'] ) ) {

                    unset( $data['CurrentUserID'] );
                    $data['Username'] = 'System';

                }

                Changes_Logs_Manager::trigger_event(
                    $alert,
                    $data
                );

                self::$rescheduled_cron = false;
            }
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
     *
     * @since 5.1.0
     */
    public static function removed_cron_job( $pre, $timestamp, $hook, $args, $wp_error = false ) {

        if ( ! $pre && ! defined( 'DOING_CRON' ) ) {
            $alert = 6071;

            $data = array(
                'task_name'     => $hook,
                'timestamp'     => Changes_DateTime_Formatter_Helper::get_formatted_date_time( $timestamp, 'datetime', true, false ),
                'arguments'     => $args,
                'CurrentUserID' => Changes_User_Helper::get_user()->ID,
                'Username'      => Changes_User_Helper::get_user()->user_login,
            );

            if ( empty( $data['CurrentUserID'] ) ) {

                unset( $data['CurrentUserID'] );
                $data['Username'] = 'System';

            }

            $cron = \wp_get_scheduled_event( $hook, $args, $timestamp );

            if ( false !== $cron && \is_object( $cron ) ) {
                if ( $cron->schedule ) {
                    $alert                = 6072;
                    $data['schedule']     = $cron->schedule;
                    $schedule_info        = \wp_get_schedules()[ $cron->schedule ];
                    $data['interval']     = $schedule_info['interval'];
                    $data['display_name'] = $schedule_info['display'];

                    Changes_Logs_Manager::trigger_event(
                        $alert,
                        $data
                    );
                }
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
     *
     * @since 5.1.0
     */
    public static function delete_option( $option ) {

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
     *
     * @since 5.1.0
     */
    public static function deleted_option( $option ) {

        // Site icon is changed - act accordingly.
        if ( 'site_icon' === $option ) {
            // That keeps the ids of the attachments.
            if ( ! empty( self::$old_option ) && isset( self::$old_option[ $option ] ) ) {

                $old_info = self::get_file_info_from_id( (int) self::$old_option[ $option ]['value'] );

                // Icon is removed.
                Changes_Logs_Manager::trigger_event(
                    6064,
                    array(
                        'old_attachment_id ' => (int) self::$old_option[ $option ]['value'],
                        'old_path'           => ( ( ! empty( $old_info ) && isset( $old_info['file_path'] ) ) ? $old_info['file_path'] : '' ),
                        'old_filename'       => ( ( ! empty( $old_info ) && isset( $old_info['file_name'] ) ) ? $old_info['file_name'] : '' ),
                        'old_attachment_url' => ( ( ! empty( $old_info ) && isset( $old_info['attachment_url'] ) ) ? $old_info['attachment_url'] : '' ),

                        'CurrentUserID'      => Changes_User_Helper::get_user()->ID,
                        'Username'           => Changes_User_Helper::get_user()->user_login,
                    )
                );
            } else {

                // Icon is removed.
                Changes_Logs_Manager::trigger_event(
                    6064,
                    array(
                        'old_attachment_id ' => 0,
                        'old_path'           => '',
                        'old_filename'       => '',
                        'old_attachment_url' => '',

                        'CurrentUserID'      => Changes_User_Helper::get_user()->ID,
                        'Username'           => Changes_User_Helper::get_user()->user_login,
                    )
                );
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
     *
     * @since 5.1.0
     */
    public static function added_option( $option, $new_value ) {

        // Site icon is added - act accordingly.
        if ( 'site_icon' === $option ) {
            $new_info = self::get_file_info_from_id( (int) $new_value );

                // New icon is introduced.
            if ( 0 !== (int) $new_value ) {
                Changes_Logs_Manager::trigger_event(
                    6063,
                    array(
                        'new_attachment_id ' => (int) $new_value,
                        'new_path'           => ( ( ! empty( $new_info ) && isset( $new_info['file_path'] ) ) ? $new_info['file_path'] : '' ),
                        'filename'           => ( ( ! empty( $new_info ) && isset( $new_info['file_name'] ) ) ? $new_info['file_name'] : '' ),
                        'attachment_url'     => ( ( ! empty( $new_info ) && isset( $new_info['attachment_url'] ) ) ? $new_info['attachment_url'] : '' ),

                        'CurrentUserID'      => Changes_User_Helper::get_user()->ID,
                        'Username'           => Changes_User_Helper::get_user()->user_login,
                    )
                );
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
     *
     * @since 5.1.0
     */
    public static function updated_option( $option, $old_value, $new_value ) {

        // Site icon is changed - act accordingly.
        if ( 'site_icon' === $option ) {
            // That keeps the ids of the attachments.
            if ( (int) $old_value !== (int) $new_value ) {

                $old_info = self::get_file_info_from_id( (int) $old_value );
                $new_info = self::get_file_info_from_id( (int) $new_value );

                // New icon is introduced.
                if ( 0 === (int) $old_value ) {
                    Changes_Logs_Manager::trigger_event(
                        6063,
                        array(
                            'new_attachment_id ' => (int) $new_value,
                            'new_path'           => ( ( ! empty( $new_info ) && isset( $new_info['file_path'] ) ) ? $new_info['file_path'] : '' ),
                            'filename'           => ( ( ! empty( $new_info ) && isset( $new_info['file_name'] ) ) ? $new_info['file_name'] : '' ),
                            'attachment_url'     => ( ( ! empty( $new_info ) && isset( $new_info['attachment_url'] ) ) ? $new_info['attachment_url'] : '' ),

                            'CurrentUserID'      => Changes_User_Helper::get_user()->ID,
                            'Username'           => Changes_User_Helper::get_user()->user_login,
                        )
                    );
                }

                // Icon is removed.
                if ( 0 === (int) $new_value ) {
                    Changes_Logs_Manager::trigger_event(
                        6065,
                        array(
                            'old_attachment_id ' => (int) $old_value,
                            'old_path'           => ( ( ! empty( $old_info ) && isset( $old_info['file_path'] ) ) ? $old_info['file_path'] : '' ),
                            'old_filename'       => ( ( ! empty( $old_info ) && isset( $old_info['file_name'] ) ) ? $old_info['file_name'] : '' ),
                            'old_attachment_url' => ( ( ! empty( $old_info ) && isset( $old_info['attachment_url'] ) ) ? $old_info['attachment_url'] : '' ),

                            'CurrentUserID'      => Changes_User_Helper::get_user()->ID,
                            'Username'           => Changes_User_Helper::get_user()->user_login,
                        )
                    );
                }

                // Icon is changed.
                if ( $new_value && $old_value ) {
                    Changes_Logs_Manager::trigger_event(
                        6064,
                        array(
                            'old_attachment_id ' => (int) $old_value,
                            'new_attachment_id ' => (int) $new_value,
                            'old_path'           => ( ( ! empty( $old_info ) && isset( $old_info['file_path'] ) ) ? $old_info['file_path'] : '' ),
                            'new_path'           => ( ( ! empty( $new_info ) && isset( $new_info['file_path'] ) ) ? $new_info['file_path'] : '' ),
                            'old_filename'       => ( ( ! empty( $old_info ) && isset( $old_info['file_name'] ) ) ? $old_info['file_name'] : '' ),
                            'filename'           => ( ( ! empty( $new_info ) && isset( $new_info['file_name'] ) ) ? $new_info['file_name'] : '' ),
                            'old_attachment_url' => ( ( ! empty( $old_info ) && isset( $old_info['attachment_url'] ) ) ? $old_info['attachment_url'] : '' ),
                            'attachment_url'     => ( ( ! empty( $new_info ) && isset( $new_info['attachment_url'] ) ) ? $new_info['attachment_url'] : '' ),

                            'CurrentUserID'      => Changes_User_Helper::get_user()->ID,
                            'Username'           => Changes_User_Helper::get_user()->user_login,
                        )
                    );
                }
            }
        }
    }

    /**
     * Helper method to extract file info from attachment - probably the proper place for that is in the Changes_WP_Helper class.
     *
     * @param integer $attachment_id - The attachment ID.
     *
     * @return array - Returns empty array if no attachment is found or wrong - otherwise associative array with file info:
     * [
     *    'file_name'      => string,
     *    'file_path'      => string,
     *    'attachment_url' => string,
     * ]
     *
     * @since 5.1.0
     */
    private static function get_file_info_from_id( int $attachment_id ): array {
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
     * Alert: Admin email changed.
     *
     * @param mixed  $old_value - The old option value.
     * @param mixed  $new_value - The new option value.
     * @param string $option    - Option name.
     *
     */
    public static function admin_email_changed( $old_value, $new_value, $option ) {
        // Check if the option is not empty and is admin_email.
        if ( ! empty( $old_value ) && ! empty( $new_value )
        && ! empty( $option ) && 'admin_email' === $option ) {
            if ( $old_value !== $new_value ) {
                Changes_Logs_Manager::trigger_event(
                    6003,
                    array(
                        'OldEmail'      => $old_value,
                        'NewEmail'      => $new_value,
                        'CurrentUserID' => Changes_User_Helper::get_user()->ID,
                    )
                );
            }
        }
    }

    /**
     * Method: Prune events function.
     *
     * @param int    $count The number of deleted events.
     * @param string $query Query that selected events for deletion.
     *
     */
    public static function event_prune_events( $count, $query ) {
        Changes_Logs_Manager::trigger_event(
            6000,
            array(
                'EventCount' => $count,
                'PruneQuery' => $query,
            )
        );
    }

    /**
     * Triggered when a user accesses the admin area.
     *
     */
    public static function event_admin_init() {

        // Make sure user can actually modify target options.
        if ( ! current_user_can( 'manage_options' ) ) {
            return;
        }

        // Filter global arrays for security.
        $post_array   = filter_input_array( INPUT_POST );
        $get_array    = filter_input_array( INPUT_GET );
        $server_array = filter_input_array( INPUT_SERVER );

        $actype = '';
        if ( ! empty( $server_array['SCRIPT_NAME'] ) ) {
            $actype = basename( \sanitize_text_field( \wp_unslash( $server_array['SCRIPT_NAME'] ) ), '.php' );
        }

        if ( isset( $post_array['action'] ) && 'toggle-auto-updates' === $post_array['action'] ) {
            $event_id = ( 'theme' === $post_array['type'] ) ? 5029 : 5028;

            $asset = \sanitize_text_field( \wp_unslash( $post_array['asset'] ) );

            if ( 'theme' === $post_array['type'] ) {
                $all_themes       = \wp_get_themes();
                $our_theme        = ( isset( $all_themes[ $asset ] ) ) ? $all_themes[ $asset ] : '';
                $install_location = $our_theme->get_template_directory();
                $name             = $our_theme->Name; // phpcs:ignore
            } elseif ( 'plugin' === $post_array['type'] ) {
                $all_plugins = \get_plugins();
                if ( ! \is_wp_error( \validate_plugin( $asset ) ) ) {
                    $our_plugin       = ( isset( $all_plugins[ $asset ] ) ) ? $all_plugins[ $asset ] : '';
                    $install_location = \plugin_dir_path( WP_PLUGIN_DIR . '/' . $asset );
                    $name             = $our_plugin['Name'];
                }
            }

            if ( isset( $name ) ) {
                Changes_Logs_Manager::trigger_event(
                    $event_id,
                    array(
                        'install_directory' => $install_location,
                        'name'              => $name,
                        'EventType'         => ( 'enable' === $post_array['state'] ) ? 'enabled' : 'disabled',
                    )
                );
            }
        }

        $is_option_page      = 'options' === $actype;
        $is_network_settings = 'settings' === $actype;
        $is_permalink_page   = 'options-permalink' === $actype;

        // WordPress URL changed.
        if ( $is_option_page && isset( $post_array['_wpnonce'] )
        && \wp_verify_nonce( $post_array['_wpnonce'], 'general-options' )
        && ! empty( $post_array['siteurl'] ) ) {
            $old_siteurl = \get_option( 'siteurl' );
            $new_siteurl = isset( $post_array['siteurl'] ) ? \sanitize_text_field( \wp_unslash( $post_array['siteurl'] ) ) : '';
            if ( $old_siteurl !== $new_siteurl ) {
                Changes_Logs_Manager::trigger_event(
                    6024,
                    array(
                        'old_url'       => $old_siteurl,
                        'new_url'       => $new_siteurl,
                        'CurrentUserID' => Changes_User_Helper::get_user()->ID,
                    )
                );
            }
        }

        // Site URL changed.
        if ( $is_option_page && isset( $post_array['_wpnonce'] )
        && \wp_verify_nonce( $post_array['_wpnonce'], 'general-options' )
        && ! empty( $post_array['home'] ) ) {
            $old_url = \get_option( 'home' );
            $new_url = isset( $post_array['home'] ) ? \sanitize_text_field( \wp_unslash( $post_array['home'] ) ) : '';
            if ( $old_url !== $new_url ) {
                Changes_Logs_Manager::trigger_event(
                    6025,
                    array(
                        'old_url'       => $old_url,
                        'new_url'       => $new_url,
                        'CurrentUserID' => Changes_User_Helper::get_user()->ID,
                    )
                );
            }
        }

        if ( isset( $post_array['option_page'] ) && 'reading' === $post_array['option_page'] && isset( $post_array['show_on_front'] ) && isset( $post_array['_wpnonce'] )
        && wp_verify_nonce( $post_array['_wpnonce'], 'reading-options' ) ) {
            $old_homepage = ( 'posts' === get_site_option( 'show_on_front' ) ) ? __( 'latest posts', 'mainwp-child' ) : __( 'static page', 'mainwp-child' );
            $new_homepage = ( 'posts' === $post_array['show_on_front'] ) ? __( 'latest posts', 'mainwp-child' ) : __( 'static page', 'mainwp-child' );
            if ( $old_homepage !== $new_homepage ) {
                Changes_Logs_Manager::trigger_event(
                    6035,
                    array(
                        'old_homepage' => $old_homepage,
                        'new_homepage' => $new_homepage,
                    )
                );
            }
        }

        if ( isset( $post_array['option_page'] ) && 'reading' === $post_array['option_page'] && isset( $post_array['page_on_front'] ) && isset( $post_array['_wpnonce'] )
        && wp_verify_nonce( $post_array['_wpnonce'], 'reading-options' ) ) {
            $old_frontpage = get_the_title( get_site_option( 'page_on_front' ) );
            $new_frontpage = get_the_title( $post_array['page_on_front'] );
            if ( $old_frontpage !== $new_frontpage ) {
                Changes_Logs_Manager::trigger_event(
                    6036,
                    array(
                        'old_page' => $old_frontpage,
                        'new_page' => $new_frontpage,
                    )
                );
            }
        }

        if ( isset( $post_array['option_page'] ) && 'reading' === $post_array['option_page'] && isset( $post_array['page_for_posts'] ) && isset( $post_array['_wpnonce'] )
        && wp_verify_nonce( $post_array['_wpnonce'], 'reading-options' ) ) {
            $old_postspage = get_the_title( get_site_option( 'page_for_posts' ) );
            $new_postspage = get_the_title( $post_array['page_for_posts'] );
            if ( $old_postspage !== $new_postspage ) {
                Changes_Logs_Manager::trigger_event(
                    6037,
                    array(
                        'old_page' => $old_postspage,
                        'new_page' => $new_postspage,
                    )
                );
            }
        }

        // Check timezone change.
        if ( $is_option_page && isset( $post_array['_wpnonce'] ) && wp_verify_nonce( $post_array['_wpnonce'], 'general-options' ) && ! empty( $post_array['timezone_string'] ) ) {
            self::check_timezone_change( $post_array );
        }

        // Check date format change.
        if ( $is_option_page && isset( $post_array['_wpnonce'] ) && wp_verify_nonce( $post_array['_wpnonce'], 'general-options' ) && ! empty( $post_array['date_format'] ) ) {
            $old_date_format = get_option( 'date_format' );
            $new_date_format = ( '\c\u\s\t\o\m' === $post_array['date_format'] ) ? \sanitize_text_field( \wp_unslash( $post_array['date_format_custom'] ) ) : \sanitize_text_field( \wp_unslash( $post_array['date_format'] ) );
            if ( $old_date_format !== $new_date_format ) {
                Changes_Logs_Manager::trigger_event(
                    6041,
                    array(
                        'old_date_format' => $old_date_format,
                        'new_date_format' => $new_date_format,
                        'CurrentUserID'   => Changes_User_Helper::get_user()->ID,
                    )
                );
            }
        }

        // Check time format change.
        if ( $is_option_page && isset( $post_array['_wpnonce'] ) && wp_verify_nonce( $post_array['_wpnonce'], 'general-options' ) && ! empty( $post_array['time_format'] ) ) {
            $old_time_format = get_option( 'time_format' );
            $new_time_format = ( '\c\u\s\t\o\m' === $post_array['time_format'] ) ? \sanitize_text_field( \wp_unslash( $post_array['time_format_custom'] ) ) : \sanitize_text_field( \wp_unslash( $post_array['time_format'] ) );
            if ( $old_time_format !== $new_time_format ) {
                Changes_Logs_Manager::trigger_event(
                    6042,
                    array(
                        'old_time_format' => $old_time_format,
                        'new_time_format' => $new_time_format,
                        'CurrentUserID'   => Changes_User_Helper::get_user()->ID,
                    )
                );
            }
        }

        // Registration Option.
        if ( $is_option_page && isset( $post_array['_wpnonce'] ) && wp_verify_nonce( $post_array['_wpnonce'], 'general-options' ) && ( get_option( 'users_can_register' ) xor isset( $post_array['users_can_register'] ) ) ) {
            $old = get_option( 'users_can_register' ) ? 'enabled' : 'disabled';
            $new = isset( $post_array['users_can_register'] ) ? 'enabled' : 'disabled';

            if ( $old !== $new ) {
                Changes_Logs_Manager::trigger_event(
                    6001,
                    array(
                        'EventType'     => $new,
                        'CurrentUserID' => Changes_User_Helper::get_user()->ID,
                    )
                );
            }
        }

        // Default Role option.
        if ( $is_option_page && isset( $post_array['_wpnonce'] ) && wp_verify_nonce( $post_array['_wpnonce'], 'general-options' ) && ! empty( $post_array['default_role'] ) ) {
            $old = get_option( 'default_role' );
            $new = trim( \sanitize_text_field( \wp_unslash( $post_array['default_role'] ) ) );
            if ( $old !== $new ) {
                Changes_Logs_Manager::trigger_event(
                    6002,
                    array(
                        'OldRole'       => $old,
                        'NewRole'       => $new,
                        'CurrentUserID' => Changes_User_Helper::get_user()->ID,
                    )
                );
            }
        }

        // Admin Email Option.
        if ( $is_option_page && isset( $post_array['_wpnonce'] ) && wp_verify_nonce( $post_array['_wpnonce'], 'general-options' ) && ! empty( $post_array['admin_email'] ) ) {
            $old = get_option( 'admin_email' );
            $new = trim( \sanitize_text_field( \wp_unslash( $post_array['admin_email'] ) ) );
            if ( $old !== $new ) {
                Changes_Logs_Manager::trigger_event(
                    6003,
                    array(
                        'OldEmail'      => $old,
                        'NewEmail'      => $new,
                        'CurrentUserID' => Changes_User_Helper::get_user()->ID,
                    )
                );
            }
        }

        // Admin Email of Network.
        if ( $is_network_settings && isset( $post_array['_wpnonce'] ) && ! empty( $post_array['new_admin_email'] ) && wp_verify_nonce( $post_array['_wpnonce'], 'siteoptions' ) ) {
            $old = get_site_option( 'admin_email' );
            $new = trim( \sanitize_text_field( \wp_unslash( $post_array['new_admin_email'] ) ) );
            if ( $old !== $new ) {
                Changes_Logs_Manager::trigger_event(
                    6003,
                    array(
                        'OldEmail'      => $old,
                        'NewEmail'      => $new,
                        'CurrentUserID' => Changes_User_Helper::get_user()->ID,
                    )
                );
            }
        }

        // Permalinks changed.
        if ( $is_permalink_page && ! empty( $post_array['permalink_structure'] ) && isset( $post_array['_wpnonce'] )
        && wp_verify_nonce( $post_array['_wpnonce'], 'update-permalink' ) ) {
            $old = get_option( 'permalink_structure' );
            $new = trim( \sanitize_text_field( \wp_unslash( $post_array['permalink_structure'] ) ) );
            if ( $old !== $new ) {
                Changes_Logs_Manager::trigger_event(
                    6005,
                    array(
                        'OldPattern'    => $old,
                        'NewPattern'    => $new,
                        'CurrentUserID' => Changes_User_Helper::get_user()->ID,
                    )
                );
            }
        }

        // Core Update.
        if ( isset( $get_array['action'] ) && 'do-core-upgrade' === $get_array['action'] && isset( $post_array['version'] ) && isset( $post_array['_wpnonce'] )
        && wp_verify_nonce( $post_array['_wpnonce'], 'upgrade-core' ) ) {
            $old_version = get_bloginfo( 'version' );
            $new_version = \sanitize_text_field( \wp_unslash( $post_array['version'] ) );
            if ( $old_version !== $new_version ) {
                Changes_Logs_Manager::trigger_event(
                    6004,
                    array(
                        'OldVersion' => $old_version,
                        'NewVersion' => $new_version,
                    )
                );
            }
        }

        // Enable core updates.
        if ( isset( $get_array['action'] ) && 'core-major-auto-updates-settings' === $get_array['action'] && isset( $get_array['value'] )
        && wp_verify_nonce( $get_array['_wpnonce'], 'core-major-auto-updates-nonce' ) ) {
            $status = ( 'enable' === $get_array['value'] ) ? esc_html__( 'automatically update to all new versions of WordPress', 'mainwp-child' ) : esc_html__( 'automatically update maintenance and security releases only', 'mainwp-child' );
            Changes_Logs_Manager::trigger_event(
                6044,
                array(
                    'updates_status' => $status,
                )
            );
        }

        // Site Language changed.
        if ( $is_option_page && isset( $post_array['_wpnonce'] )
        && wp_verify_nonce( $post_array['_wpnonce'], 'general-options' )
        && isset( $post_array['WPLANG'] ) ) {
            // Is there a better way to turn the language into a "nice name"?
            require_once ABSPATH . 'wp-admin/includes/translation-install.php';
            $available_translations = wp_get_available_translations();

            // When English (United States) is selected, the WPLANG post entry is empty so lets account for this.
            $wplang_setting = get_option( 'WPLANG' );
            $previous_value = ( ! empty( $wplang_setting ) ) ? $wplang_setting : 'en-US';
            $new_value      = ( ! empty( $post_array['WPLANG'] ) ) ? \sanitize_text_field( \wp_unslash( $post_array['WPLANG'] ) ) : 'en-US';

            // Now lets turn these into a nice, native name - the same as shown to the user when choosing a language.
            $previous_value = ( isset( $available_translations[ $previous_value ] ) ) ? $available_translations[ $previous_value ]['native_name'] : 'English (United States)';
            $new_value      = ( isset( $available_translations[ $new_value ] ) ) ? $available_translations[ $new_value ]['native_name'] : 'English (United States)';

            if ( $previous_value !== $new_value ) {
                Changes_Logs_Manager::trigger_event(
                    6045,
                    array(
                        'previous_value' => $previous_value,
                        'new_value'      => $new_value,
                    )
                );
            }
        }

        // Site title.
        if ( $is_option_page && isset( $post_array['_wpnonce'] )
        && wp_verify_nonce( $post_array['_wpnonce'], 'general-options' )
        && isset( $post_array['blogname'] ) ) {
            $previous_value = get_option( 'blogname' );
            $new_value      = ( ! empty( $post_array['blogname'] ) ) ? \sanitize_text_field( \wp_unslash( $post_array['blogname'] ) ) : '';

            if ( $previous_value !== $new_value ) {
                Changes_Logs_Manager::trigger_event(
                    6059,
                    array(
                        'previous_value' => $previous_value,
                        'new_value'      => $new_value,
                    )
                );
            }
        }
    }

    /**
     * WordPress auto core update.
     *
     * @param array $automatic - Automatic update array.
     *
     */
    public static function wp_update( $automatic ) {
        if ( isset( $automatic['core'][0] ) ) {
            $obj         = $automatic['core'][0];
            $old_version = get_bloginfo( 'version' );
            Changes_Logs_Manager::trigger_event(
                6004,
                array(
                    'OldVersion' => $old_version,
                    'NewVersion' => $obj->item->version . ' (auto update)',
                )
            );
        }
    }

    /**
     * Events from 6008 to 6018.
     *
     * @param array $whitelist - White list options.
     *
     * @return array|null
     *
     */
    public static function event_options( $whitelist = null ) {
        // Filter global arrays for security.
        $post_array = filter_input_array( INPUT_POST );

        if ( isset( $post_array['option_page'] ) && 'reading' === $post_array['option_page'] ) {
            $old_status = (int) get_option( 'blog_public', 1 );
            $new_status = isset( $post_array['blog_public'] ) ? 0 : 1;

            if ( $old_status !== $new_status ) {
                Changes_Logs_Manager::trigger_event(
                    6008,
                    array( 'EventType' => ( 0 === $new_status ) ? 'enabled' : 'disabled' )
                );
            }
        }

        if ( isset( $post_array['option_page'] ) && 'discussion' === $post_array['option_page'] ) {
            $old_status = get_option( 'default_comment_status', 'closed' );
            $new_status = isset( $post_array['default_comment_status'] ) ? 'open' : 'closed';

            if ( $old_status !== $new_status ) {
                Changes_Logs_Manager::trigger_event(
                    6009,
                    array( 'EventType' => ( 'open' === $new_status ) ? 'enabled' : 'disabled' )
                );
            }

            $old_status = (int) get_option( 'require_name_email', 0 );
            $new_status = isset( $post_array['require_name_email'] ) ? 1 : 0;

            if ( $old_status !== $new_status ) {
                Changes_Logs_Manager::trigger_event(
                    6010,
                    array( 'EventType' => ( 1 === $new_status ) ? 'enabled' : 'disabled' )
                );
            }

            $old_status = (int) get_option( 'comment_registration', 0 );
            $new_status = isset( $post_array['comment_registration'] ) ? 1 : 0;

            if ( $old_status !== $new_status ) {
                Changes_Logs_Manager::trigger_event(
                    6011,
                    array( 'EventType' => ( 1 === $new_status ) ? 'enabled' : 'disabled' )
                );
            }

            $old_status = (int) get_option( 'close_comments_for_old_posts', 0 );
            $new_status = isset( $post_array['close_comments_for_old_posts'] ) ? 1 : 0;

            if ( $old_status !== $new_status ) {
                $value = isset( $post_array['close_comments_days_old'] ) ? \sanitize_text_field( \wp_unslash( $post_array['close_comments_days_old'] ) ) : 0;
                Changes_Logs_Manager::trigger_event(
                    6012,
                    array(
                        'EventType' => ( 1 === $new_status ) ? 'enabled' : 'disabled',
                        'Value'     => $value,
                    )
                );
            }

            $old_value = get_option( 'close_comments_days_old', 0 );
            $new_value = isset( $post_array['close_comments_days_old'] ) ? \sanitize_text_field( \wp_unslash( $post_array['close_comments_days_old'] ) ) : 0;
            if ( $old_value !== $new_value ) {
                Changes_Logs_Manager::trigger_event(
                    6013,
                    array(
                        'OldValue' => $old_value,
                        'NewValue' => $new_value,
                    )
                );
            }

            $old_status = (int) get_option( 'comment_moderation', 0 );
            $new_status = isset( $post_array['comment_moderation'] ) ? 1 : 0;

            if ( $old_status !== $new_status ) {
                Changes_Logs_Manager::trigger_event(
                    6014,
                    array( 'EventType' => ( 1 === $new_status ) ? 'enabled' : 'disabled' )
                );
            }

            // Comment_whitelist option was renamed to comment_previously_approved in WordPress 5.5.0.
            $comment_whitelist_option_name = version_compare( get_bloginfo( 'version' ), '5.5.0', '<' ) ? 'comment_whitelist' : 'comment_previously_approved';
            $old_status                    = (int) get_option( $comment_whitelist_option_name, 0 );
            $new_status                    = isset( $post_array[ $comment_whitelist_option_name ] ) ? 1 : 0;

            if ( $old_status !== $new_status ) {
                Changes_Logs_Manager::trigger_event(
                    6015,
                    array( 'EventType' => ( 1 === $new_status ) ? 'enabled' : 'disabled' )
                );
            }

            $old_value = get_option( 'comment_max_links', 0 );
            $new_value = isset( $post_array['comment_max_links'] ) ? \sanitize_text_field( \wp_unslash( $post_array['comment_max_links'] ) ) : 0;
            if ( $old_value !== $new_value ) {
                Changes_Logs_Manager::trigger_event(
                    6016,
                    array(
                        'OldValue' => $old_value,
                        'NewValue' => $new_value,
                    )
                );
            }

            $old_value = get_option( 'moderation_keys', 0 );
            $new_value = isset( $post_array['moderation_keys'] ) ? \sanitize_text_field( \wp_unslash( $post_array['moderation_keys'] ) ) : 0;
            if ( $old_value !== $new_value ) {
                Changes_Logs_Manager::trigger_event( 6017, array() );
            }

            // Blacklist_keys option was renamed to disallowed_keys in WordPress 5.5.0.
            $blacklist_keys_option_name = version_compare( get_bloginfo( 'version' ), '5.5.0', '<' ) ? 'blacklist_keys' : 'disallowed_keys';
            $old_value                  = get_option( $blacklist_keys_option_name, 0 );
            $new_value                  = isset( $post_array[ $blacklist_keys_option_name ] ) ? \sanitize_text_field( \wp_unslash( $post_array[ $blacklist_keys_option_name ] ) ) : 0;
            if ( $old_value !== $new_value ) {
                Changes_Logs_Manager::trigger_event( 6018, array() );
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
     *
     */
    public static function site_blogname_change( $customizable ) {
        if ( isset( $_POST['customized'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing
            $post_values = json_decode( \sanitize_text_field( \wp_unslash( $_POST['customized'] ) ), true ); // phpcs:ignore WordPress.Security.NonceVerification.Missing

            if ( $customizable->default !== $post_values['blogname'] ) {
                Changes_Logs_Manager::trigger_event(
                    6059,
                    array(
                        'previous_value' => $customizable->default,
                        'new_value'      => $post_values['blogname'],
                    )
                );
            }
        }
    }

    /**
     * Logs event when email is sent
     *
     * @param array $mail_data - Array of mail data.
     *
     * @return void
     *
     * @since 4.6.0
     */
    public static function mail_was_sent( $mail_data ) {
        if ( \is_array( $mail_data ) && isset( $mail_data['to'] ) && isset( $mail_data['subject'] ) ) {
            Changes_Logs_Manager::trigger_event(
                6061,
                array(
                    'Username'     => 'System',
                    'EmailAddress' => \wp_unslash( $mail_data['to'] ),
                    'EmailSubject' => \wp_unslash( $mail_data['subject'] ),
                )
            );
        }
    }

    /**
     * Checks if the timezone settings have changed. Logs an events if it did.
     *
     * @param array $post_array Sanitized input array.
     *
     */
    private static function check_timezone_change( $post_array ) {
        $old_timezone_string = get_option( 'timezone_string' );
        $new_timezone_string = isset( $post_array['timezone_string'] ) ? \sanitize_text_field( \wp_unslash( $post_array['timezone_string'] ) ) : '';

        // Backup of the labels as we might change them below when dealing with UTC offset definitions.
        $old_timezone_label = $old_timezone_string;
        $new_timezone_label = $new_timezone_string;
        if ( strlen( $old_timezone_string ) === 0 ) {
            // The old timezone string can be empty if the time zone was configured using UTC offset selection
            // rather than using a country/city selection.
            $old_timezone_string = $old_timezone_label = wp_timezone_string(); // phpcs:ignore Squiz.PHP.DisallowMultipleAssignments.Found
            if ( 'UTC' === $old_timezone_string ) {
                $old_timezone_string = '+00:00';
            }

            // Adjusts label to show UTC offset consistently.
            $old_timezone_label = 'UTC' . $old_timezone_label;
        }

        // New timezone can be defined as UTC offset.

        // There is one UTC option that doesn't contain the offset, we need to tweak the value for further processing.
        if ( 'UTC' === $new_timezone_string ) {
            $new_timezone_string = 'UTC+0';
        }

        if ( preg_match( '/UTC([+\-][0-9\.]+)/', $new_timezone_string, $matches ) ) {
            $hours_decimal = floatval( $matches[1] );

            // The new timezone is also set using UTC offset, it needs to be converted to the same format used
            // by wp_timezone_string.
            $sign                = $hours_decimal < 0 ? '-' : '+';
            $abs_hours           = abs( $hours_decimal );
            $abs_mins            = abs( $hours_decimal * 60 % 60 );
            $new_timezone_string = sprintf( '%s%02d:%02d', $sign, floor( $abs_hours ), $abs_mins );

            // Adjusts label to show UTC offset consistently.
            $new_timezone_label = 'UTC' . $new_timezone_string;
        }

        if ( $old_timezone_string !== $new_timezone_string ) {
            Changes_Logs_Manager::trigger_event(
                6040,
                array(
                    'old_timezone'  => $old_timezone_label,
                    'new_timezone'  => $new_timezone_label,
                    'CurrentUserID' => Changes_User_Helper::get_current_user()->ID,
                )
            );
        }
    }
}
