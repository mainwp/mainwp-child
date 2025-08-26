<?php
/**
 * Logger: Widgets
 *
 * Widgets logger class file.
 *
 * @since     5.5
 * @package   mainwp/child
 */

namespace MainWP\Child\Changes;

// Exit.
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Widgets.
 */
class Changes_Handle_WP_Widgets {

    /**
     * Widget Move Data
     *
     * @var array
     */
    private static $widget_change_data = null;

    /**
     * Inits the main hooks
     *
     * @return void
     */
    public static function init_hooks() {
        if ( current_user_can( 'edit_theme_options' ) ) {
            \add_action( 'admin_init', array( __CLASS__, 'callback_change_widget_move' ) );
            \add_action( 'admin_init', array( __CLASS__, 'callback_change_widget_post_move' ) );
        }
        \add_action( 'sidebar_admin_setup', array( __CLASS__, 'callback_change_widget_activity' ) );
    }

    /**
     * When a user accesses the admin area.
     * Moved widget.
     */
    public static function callback_change_widget_move() {
        $post_vars = filter_input_array( INPUT_POST );

        if ( ! isset( $post_vars['savewidgets'] ) || false === \check_ajax_referer( 'save-sidebar-widgets', 'savewidgets', false ) ) {
            return;
        }

        if ( isset( $post_vars ) && ! empty( $post_vars['sidebars'] ) ) {
            $current_sidebars = (array) $post_vars['sidebars'];
            $sidebars         = array();
            foreach ( $current_sidebars as $key => $val ) {
                $sb = array();
                if ( ! empty( $val ) ) {
                    $val = explode( ',', $val );
                    foreach ( $val as $k => $v ) {
                        if ( strpos( $v, 'widget-' ) === false ) {
                            continue;
                        }
                        $sb[ $k ] = substr( $v, strpos( $v, '_' ) + 1 );
                    }
                }
                $sidebars[ $key ] = $sb;
            }
            $current_sidebars = $sidebars;
            $db_sidebars      = get_option( 'sidebars_widgets' );
            $widget_name      = '';
            $current_sidebar  = '';
            $new_sidebar      = '';
            foreach ( $current_sidebars as $sidebar_name => $values ) {
                if ( is_array( $values ) && ! empty( $values ) && isset( $db_sidebars[ $sidebar_name ] ) ) {
                    foreach ( $values as $widget_name ) {
                        if ( ! in_array( $widget_name, $db_sidebars[ $sidebar_name ], true ) ) {
                            $new_sidebar = $sidebar_name;
                            foreach ( $db_sidebars as $name => $v ) {
                                if ( is_array( $v ) && ! empty( $v ) && in_array( $widget_name, $v, true ) ) {
                                    $current_sidebar = $name;
                                }
                            }
                        }
                    }
                }
            }

            if ( empty( $widget_name ) || empty( $current_sidebar ) || empty( $new_sidebar ) ) {
                return;
            }

            if ( preg_match( '/^sidebar-/', $current_sidebar ) || preg_match( '/^sidebar-/', $new_sidebar ) ) {
                self::$widget_change_data = array(
                    'name' => $widget_name,
                    'from' => $current_sidebar,
                    'to'   => $new_sidebar,
                );

                return;
            }
            $log_data = array(
                'widgetname' => \sanitize_text_field( \wp_unslash( $widget_name ) ),
                'oldsidebar' => \sanitize_text_field( \wp_unslash( $current_sidebar ) ),
                'newsidebar' => \sanitize_text_field( \wp_unslash( $new_sidebar ) ),
            );
            Changes_Logs_Logger::log_change( 1445, $log_data );
        }
    }

    /**
     * When a user accesses the admin area.
     */
    public static function callback_change_widget_post_move() {
        $post_vars = filter_input_array( INPUT_POST );

        // Generates the event 2071.
        if ( isset( $post_vars['action'] ) && ( 'widgets-order' === $post_vars['action'] ) ) {
            if ( isset( $post_vars['sidebars'] ) ) {
                $request_sidebars = array();
                if ( $post_vars['sidebars'] ) {
                    foreach ( (array) $post_vars['sidebars'] as $key => &$value ) {
                        if ( ! empty( $value ) ) {
                            $value = explode( ',', $value );
                            // Cleanup widgets' name.
                            foreach ( $value as $k => &$widget_name ) {
                                $widget_name = preg_replace( '/^([a-z]+-[0-9]+)+?_/i', '', $widget_name );
                            }
                            unset( $widget_name );
                            $request_sidebars[ $key ] = $value;
                        }
                    }
                    unset( $value );
                }

                if ( $request_sidebars ) {
                    $sidebar_widgets = \wp_get_sidebars_widgets();
                    global $wp_registered_sidebars;

                    foreach ( $request_sidebars as $sidebar_name => $widgets ) {
                        if ( isset( $sidebar_widgets[ $sidebar_name ] ) ) {
                            foreach ( $sidebar_widgets[ $sidebar_name ] as $i => $widget_name ) {
                                $index = array_search( $widget_name, $widgets, true );
                                if ( $i != $index ) { // phpcs:ignore
                                    $sn = $sidebar_name;
                                    if ( $wp_registered_sidebars && isset( $wp_registered_sidebars[ $sidebar_name ] ) ) {
                                        $sn = $wp_registered_sidebars[ $sidebar_name ]['name'];
                                    }

                                    $log_data = array(
                                        'widgetname'  => \sanitize_text_field( \wp_unslash( $widget_name ) ),
                                        'oldposition' => $i + 1,
                                        'newposition' => $index + 1,
                                        'sidebar'     => \sanitize_text_field( \wp_unslash( $sn ) ),
                                    );
                                    Changes_Logs_Logger::log_change( 1450, $log_data );
                                }
                            }
                        }
                    }
                }
            }
        }
        if ( self::$widget_change_data ) {
            $widget_name     = self::$widget_change_data['name'];
            $current_sidebar = self::$widget_change_data['from'];
            $new_sidebar     = self::$widget_change_data['to'];

            global $wp_registered_sidebars;

            if ( preg_match( '/^sidebar-/', $current_sidebar ) ) {
                $current_sidebar = isset( $wp_registered_sidebars[ $current_sidebar ] )
                ? $wp_registered_sidebars[ $current_sidebar ]['name']
                : $current_sidebar;
            }

            if ( preg_match( '/^sidebar-/', $new_sidebar ) ) {
                $new_sidebar = isset( $wp_registered_sidebars[ $new_sidebar ] )
                ? $wp_registered_sidebars[ $new_sidebar ]['name']
                : $new_sidebar;
            }

            $log_data = array(
                'widgetname' => \sanitize_text_field( \wp_unslash( $widget_name ) ),
                'oldsidebar' => \sanitize_text_field( \wp_unslash( $current_sidebar ) ),
                'newsidebar' => \sanitize_text_field( \wp_unslash( $new_sidebar ) ),
            );
            Changes_Logs_Logger::log_change( 1445, $log_data );
        }
    }

    /**
     * Widgets Activity (added, modified, deleted).
     */
    public static function callback_change_widget_activity() {
        $post_vars = filter_input_array( INPUT_POST );

        if ( ! isset( $post_vars ) || ! isset( $post_vars['widget-id'] ) || empty( $post_vars['widget-id'] ) ) {
            return;
        }

        if ( ! isset( $post_vars['savewidgets'] ) || false === check_ajax_referer( 'save-sidebar-widgets', 'savewidgets', false ) ) {
            return;
        }

        global $wp_registered_sidebars;
        $is_not_empty_sidebar = ! empty( $wp_registered_sidebars );

        switch ( true ) {
            // Added widget.
            case isset( $post_vars['add_new'] ) && 'multi' === $post_vars['add_new']:
                $sidebar = isset( $post_vars['sidebar'] ) ? \sanitize_text_field( \wp_unslash( $post_vars['sidebar'] ) ) : null;
                if ( $is_not_empty_sidebar && preg_match( '/^sidebar-/', $sidebar ) ) {
                    $sidebar = $wp_registered_sidebars[ $sidebar ]['name'];
                }

                $log_data = array(
                    'widgetname' => \sanitize_text_field( \wp_unslash( $post_vars['id_base'] ) ),
                    'sidebar'    => \sanitize_text_field( \wp_unslash( $sidebar ) ),
                );

                Changes_Logs_Logger::log_change( 1430, $log_data );
                break;
            // Deleted widget.
            case isset( $post_vars['delete_widget'] ) && 1 === intval( $post_vars['delete_widget'] ):
                $sidebar = isset( $post_vars['sidebar'] ) ? \sanitize_text_field( \wp_unslash( $post_vars['sidebar'] ) ) : null;
                if ( $is_not_empty_sidebar && preg_match( '/^sidebar-/', $sidebar ) ) {
                    $sidebar = $wp_registered_sidebars[ $sidebar ]['name'];
                }
                $log_data = array(
                    'widgetname' => \sanitize_text_field( \wp_unslash( $post_vars['id_base'] ) ),
                    'sidebar'    => \sanitize_text_field( \wp_unslash( $sidebar ) ),
                );
                Changes_Logs_Logger::log_change( 1440, $log_data );
                break;
            // Modified widget.
            case isset( $post_vars['id_base'] ) && ! empty( $post_vars['id_base'] ):
                $widget_id = 0;
                if ( ! empty( $post_vars['multi_number'] ) ) {
                    $widget_id = intval( $post_vars['multi_number'] );
                } elseif ( ! empty( $post_vars['widget_number'] ) ) {
                    $widget_id = intval( $post_vars['widget_number'] );
                }
                if ( empty( $widget_id ) ) {
                    return;
                }

                $widget_name = \sanitize_text_field( \wp_unslash( $post_vars['id_base'] ) );
                $sidebar     = isset( $post_vars['sidebar'] ) ? \sanitize_text_field( \wp_unslash( $post_vars['sidebar'] ) ) : null;
                $widget_data = isset( $post_vars[ "widget-$widget_name" ][ $widget_id ] )
                ? $post_vars[ "widget-$widget_name" ][ $widget_id ]
                : null;

                if ( empty( $widget_data ) ) {
                    return;
                }
                $widget_saved_data = get_option( 'widget_' . $widget_name );
                if ( empty( $widget_saved_data[ $widget_id ] ) ) {
                    return;
                }

                // Transform 'on' -> 1.
                foreach ( $widget_data as $k => $v ) {
                    if ( 'on' === $v ) {
                        $widget_data[ $k ] = 1;
                    }
                }

                // Checks for any changes inside widgets.
                $diff  = array_diff_assoc( $widget_data, $widget_saved_data[ $widget_id ] );
                $count = count( $diff );
                if ( $count > 0 ) {
                    if ( $is_not_empty_sidebar && preg_match( '/^sidebar-/', $sidebar ) ) {
                        $sidebar = $wp_registered_sidebars[ $sidebar ]['name'];
                    }
                    $log_data = array(
                        'widgetname' => \sanitize_text_field( \wp_unslash( $widget_name ) ),
                        'sidebar'    => \sanitize_text_field( \wp_unslash( $sidebar ) ),
                    );
                    Changes_Logs_Logger::log_change( 1435, $log_data );
                }
                break;
            default:
                break;
        }
    }
}
