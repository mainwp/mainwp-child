<?php
/**
 * Logger: Menus.
 *
 * Menus logger class file.
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
     * Menus.
     */
class Changes_Handle_WP_Menus {

    /**
     * Menu object.
     *
     * @var object
     */
    private static $old_menu = null;

    /**
     * Old Menu objects.
     *
     * @var array
     */
    private static $old_menu_terms = array();

    /**
     * Old Menu Items.
     *
     * @var array
     */
    private static $old_menu_items = array();

    /**
     * Old Menu Locations.
     *
     * @var array
     */
    private static $old_menu_locations = null;

    /**
     * An array of menu IDs.
     *
     * @var array
     */
    private static $order_changed_menu_ids = array();

    /**
     * Inits
     *
     * @return void
     */
    public static function init_hooks() {
        add_action( 'admin_init', array( __CLASS__, 'callback_change_admin_init' ) );
        add_action( 'admin_menu', array( __CLASS__, 'change_manage_menu_locations' ) );
        add_action( 'customize_register', array( __CLASS__, 'change_customize_init' ) );
        add_action( 'customize_save_after', array( __CLASS__, 'change_customize_save' ) );
        add_action( 'wp_create_nav_menu', array( __CLASS__, 'callback_change_create_menu' ), 10, 2 );
        add_action( 'wp_delete_nav_menu', array( __CLASS__, 'callback_change_delete_menu' ), 10, 1 );
        add_action( 'wp_update_nav_menu', array( __CLASS__, 'callback_change_update_menu' ), 10, 2 );
        add_action( 'wp_update_nav_menu_item', array( __CLASS__, 'callback_change_update_menu_item' ), 10, 3 );
    }

    /**
     * Menu updated.
     *
     * @param int   $menu_id         - Menu ID.
     * @param int   $menu_item_db_id - Menu item DB ID.
     * @param array $args            - An array of items.
     *
     * @return boolean
     */
    public static function callback_change_update_menu_item( $menu_id, $menu_item_db_id, $args ) {
        $post_vars = filter_input_array( INPUT_POST );

        $old_menu_items = array();
        if ( isset( $post_vars['menu-item-title'] ) && isset( $post_vars['menu-name'] ) ) {
            $is_changed_order = false;
            $is_sub_item      = false;
            $new_menu_items   = array_keys( $post_vars['menu-item-title'] );
            if ( ! empty( self::$old_menu_items ) ) {
                foreach ( self::$old_menu_items as $old_item ) {
                    if ( $old_item['menu_id'] === $menu_id ) {
                        $item_id = $old_item['item_id'];
                        if ( $item_id === $menu_item_db_id ) {
                            if ( $old_item['menu_order'] !== (int) $args['menu-item-position'] ) {
                                $is_changed_order = true;
                            }
                            if ( ! empty( $args['menu-item-parent-id'] ) ) {
                                $is_sub_item = true;
                            }
                            if ( ! empty( $args['menu-item-title'] ) && $old_item['title'] !== $args['menu-item-title'] ) {
                                if ( ! wp_verify_nonce( $post_vars['update-nav-menu-nonce'], 'update-nav_menu' ) ) {
                                    return false;
                                }

                                self::callback_change_modified_items( $post_vars['menu-item-object'][ $menu_item_db_id ], $post_vars['menu-item-title'][ $menu_item_db_id ], $post_vars['menu-name'], $menu_id );
                            }
                        }
                        $old_menu_items[ $item_id ] = array(
                            'type'   => $old_item['object'],
                            'title'  => $old_item['title'],
                            'parent' => $old_item['menu_item_parent'],
                        );
                    }
                }
            }

            $added_items = array_diff( $new_menu_items, array_keys( $old_menu_items ) );
            if ( count( $added_items ) > 0 && wp_verify_nonce( $post_vars['update-nav-menu-nonce'], 'update-nav_menu' ) ) {
                if ( in_array( $menu_item_db_id, $added_items, true ) ) {
                    self::callback_change_add_items( $post_vars['menu-item-object'][ $menu_item_db_id ], $post_vars['menu-item-title'][ $menu_item_db_id ], $post_vars['menu-name'], $menu_id );
                }
            }

            $removed_items = array_diff( array_keys( $old_menu_items ), $new_menu_items );
            if ( count( $removed_items ) > 0 && wp_verify_nonce( $post_vars['update-nav-menu-nonce'], 'update-nav_menu' ) ) {
                if ( array_search( $menu_item_db_id, $new_menu_items, true ) === ( count( $new_menu_items ) - 1 ) ) {
                    foreach ( $removed_items as $removed_item_id ) {
                        self::callback_change_remove_items( $old_menu_items[ $removed_item_id ]['type'], $old_menu_items[ $removed_item_id ]['title'], $post_vars['menu-name'], $menu_id );
                    }
                }
            }

            // We want to ignore order changes when menu items are added.
            $ignore_order_change = ! empty( $removed_items ) || ! empty( $added_items );

            // Check if an order has changed.
            if ( ! $ignore_order_change && $is_changed_order && wp_verify_nonce( $post_vars['meta-box-order-nonce'], 'meta-box-order' ) ) {
                $old_item    = $old_menu_items[ $menu_item_db_id ];
                $menu_object = wp_get_nav_menu_object( $menu_id );
                if ( $menu_object instanceof \WP_Term ) {
                    self::change_menu_order( $old_item['title'], $menu_object->name, $menu_id );
                }
            }

            if ( $is_sub_item && wp_verify_nonce( $post_vars['update-nav-menu-nonce'], 'update-nav_menu' ) ) {
                $item_parent_id = $args['menu-item-parent-id'];
                $item_name      = $old_menu_items[ $menu_item_db_id ]['title'];
                if ( $old_menu_items[ $menu_item_db_id ]['parent'] !== $item_parent_id ) {
                    $parent_name = isset( $old_menu_items[ $item_parent_id ]['title'] ) ? $old_menu_items[ $item_parent_id ]['title'] : false;
                    self::callback_change_change_sub_item( $item_name, $parent_name, $post_vars['menu-name'], $menu_id );
                }
            }
        }
    }

    /**
     * New menu created.
     *
     * @param int   $term_id - Term ID.
     * @param array $menu_data - Menu data.
     */
    public static function callback_change_create_menu( $term_id, $menu_data ) {
        $log_data = array(
            'menuname' => $menu_data['menu-name'],
            'menuid'   => $term_id,
        );
        Changes_Logs_Logger::log_change( 1475, $log_data );
    }

    /**
     * New menu created.
     *
     * @global array $_POST Data post.
     */
    public static function change_manage_menu_locations() {
        // Filter $_POST array.
        $post_vars = filter_input_array( INPUT_POST );

        // Verify nonce.
        if ( ! isset( $post_vars['_wpnonce'] ) || ! wp_verify_nonce( $post_vars['_wpnonce'], 'save-menu-locations' ) ) {
            return;
        }

        // Manage Location tab.
        if ( isset( $post_vars['menu-locations'] ) ) {
            $new_locations = \sanitize_text_field( \wp_unslash( $post_vars['menu-locations'] ) );
            if ( isset( $new_locations['top'] ) ) {
                self::change_menu_location_setting( $new_locations['top'], 'top' );
            }
            if ( isset( $new_locations['social'] ) ) {
                self::change_menu_location_setting( $new_locations['social'], 'social' );
            }
        }
    }

    /**
     * Menu location.
     *
     * @param integer $new_location - New location.
     * @param string  $type - Location type.
     */
    private static function change_menu_location_setting( $new_location, $type ) {
        $old_locations = get_nav_menu_locations();
        if ( 0 !== $new_location ) {
            $menu = wp_get_nav_menu_object( $new_location );
            if ( isset( $old_locations[ $type ] ) && $old_locations[ $type ] !== $new_location ) {
                self::callback_change_menu_setting( $menu->name, 'Enabled', 'Location: ' . $type . ' menu', $menu->term_id );
            }
        } elseif ( ! empty( $old_locations[ $type ] ) ) {
            $menu = wp_get_nav_menu_object( $old_locations[ $type ] );
            self::callback_change_menu_setting( $menu->name, 'Disabled', 'Location: ' . $type . ' menu', $menu->term_id );
        }
    }

    /**
     * Menu deleted.
     *
     * @param int $term_id - Term ID.
     */
    public static function callback_change_delete_menu( $term_id ) {
        if ( self::$old_menu ) {
            $log_data = array(
                'menuname' => self::$old_menu->name,
                'menuid'   => $term_id,
            );
            Changes_Logs_Logger::log_change( 1490, $log_data );
        }
    }

    /**
     * Menu updated.
     *
     * @param int   $menu_id - Menu ID.
     * @param array $menu_data (Optional) Menu data.
     */
    public static function callback_change_update_menu( $menu_id, $menu_data = null ) {
        if ( ! empty( $menu_data ) ) {
            $content_names_old = array();
            $content_types_old = array();
            $content_order_old = array();

            $items = wp_get_nav_menu_items( $menu_id );
            if ( ! empty( $items ) ) {
                foreach ( $items as $item ) {
                    array_push( $content_names_old, $item->title );
                    array_push( $content_types_old, $item->object );
                    $content_order_old[ $item->ID ] = $item->menu_order;
                }
            }

            // Filter $_POST array.
            $post_vars = filter_input_array( INPUT_POST );

            // Menu changed name.
            if ( ! empty( self::$old_menu_terms ) && isset( $post_vars['menu'] ) && isset( $post_vars['menu-name'] ) ) {
                foreach ( self::$old_menu_terms as $old_menu_term ) {
                    if ( $old_menu_term['term_id'] === (int) $post_vars['menu'] && wp_verify_nonce( $post_vars['update-nav-menu-nonce'], 'update-nav_menu' ) ) {
                        if ( $old_menu_term['name'] !== $post_vars['menu-name'] ) {
                            self::callback_change_change_name( $old_menu_term['name'], $post_vars['menu-name'], $menu_id );
                        } elseif ( count( $content_names_old ) === 1 && count( $content_types_old ) === 1 ) {
                            self::callback_change_remove_items( $content_types_old[0], $content_names_old[0], $post_vars['menu-name'], $menu_id );
                        }
                    }
                }
            }

            // Enable/Disable menu setting.
            $nav_menu_options = maybe_unserialize( get_option( 'nav_menu_options' ) );
            $auto_add         = null;
            if ( isset( $nav_menu_options['auto_add'] ) ) {
                if ( in_array( $menu_id, $nav_menu_options['auto_add'], true ) ) {
                    if ( empty( $post_vars['auto-add-pages'] ) ) {
                        $auto_add = 'Disabled';
                    }
                } elseif ( isset( $post_vars['auto-add-pages'] ) ) {
                        $auto_add = 'Enabled';
                }
            } elseif ( isset( $post_vars['auto-add-pages'] ) ) {
                    $auto_add = 'Enabled';
            }

            // Log Auto add pages.
            if ( ! empty( $auto_add ) ) {
                self::callback_change_menu_setting( $menu_data['menu-name'], $auto_add, 'Auto add pages', $menu_id );
            }

            $nav_menu_locations = get_nav_menu_locations();

            $location_top = null;
            if ( isset( self::$old_menu_locations['top'] ) && isset( $nav_menu_locations['top'] ) ) {
                if ( $nav_menu_locations['top'] === $menu_id && self::$old_menu_locations['top'] !== $nav_menu_locations['top'] ) {
                    $location_top = 'Enabled';
                }
            } elseif ( empty( self::$old_menu_locations['top'] ) && isset( $nav_menu_locations['top'] ) ) {
                if ( $nav_menu_locations['top'] === $menu_id ) {
                    $location_top = 'Enabled';
                }
            } elseif ( isset( self::$old_menu_locations['top'] ) && empty( $nav_menu_locations['top'] ) ) {
                if ( self::$old_menu_locations['top'] === $menu_id ) {
                    $location_top = 'Disabled';
                }
            }

            // Log top menu.
            if ( ! empty( $location_top ) ) {
                self::callback_change_menu_setting( $menu_data['menu-name'], $location_top, 'Location: top menu', $menu_id );
            }

            $location_social = null;
            if ( isset( self::$old_menu_locations['social'] ) && isset( $nav_menu_locations['social'] ) ) {
                if ( $nav_menu_locations['social'] === $menu_id && self::$old_menu_locations['social'] !== $nav_menu_locations['social'] ) {
                    $location_social = 'Enabled';
                }
            } elseif ( empty( self::$old_menu_locations['social'] ) && isset( $nav_menu_locations['social'] ) ) {
                if ( $nav_menu_locations['social'] === $menu_id ) {
                    $location_social = 'Enabled';
                }
            } elseif ( isset( self::$old_menu_locations['social'] ) && empty( $nav_menu_locations['social'] ) ) {
                if ( self::$old_menu_locations['social'] === $menu_id ) {
                    $location_social = 'Disabled';
                }
            }

            // Social links menu.
            if ( ! empty( $location_social ) ) {
                self::callback_change_menu_setting( $menu_data['menu-name'], $location_social, 'Location: social menu', $menu_id );
            }
        }
    }

    /**
     * Set old menu terms and items.
     */
    private static function change_build_old_menu_terms_and_items() {
        $menus = wp_get_nav_menus();
        if ( ! empty( $menus ) ) {
            foreach ( $menus as $menu ) {
                array_push(
                    self::$old_menu_terms,
                    array(
                        'term_id' => $menu->term_id,
                        'name'    => $menu->name,
                    )
                );
                $items = wp_get_nav_menu_items( $menu->term_id );
                if ( ! empty( $items ) ) {
                    foreach ( $items as $item ) {
                        array_push(
                            self::$old_menu_items,
                            array(
                                'menu_id'          => $menu->term_id,
                                'item_id'          => $item->ID,
                                'title'            => $item->title,
                                'object'           => $item->object,
                                'menu_name'        => $menu->name,
                                'menu_order'       => $item->menu_order,
                                'url'              => $item->url,
                                'menu_item_parent' => $item->menu_item_parent,
                            )
                        );
                    }
                }
            }
        }
    }

    /**
     * When a user accesses the admin area.
     */
    public static function callback_change_admin_init() {
        $server_vars = filter_input_array( INPUT_SERVER );
        $get_vars    = filter_input_array( INPUT_GET );

        $script_name = '';
        if ( ! empty( $server_vars['SCRIPT_NAME'] ) ) {
            $script_name = \sanitize_text_field( \wp_unslash( $server_vars['SCRIPT_NAME'] ) );
        }

        $is_nav_menu = basename( $script_name ) === 'nav-menus.php';
        if ( $is_nav_menu ) {
            if ( isset( $get_vars['action'] ) && 'delete' === $get_vars['action'] ) {
                if ( isset( $get_vars['menu'] ) ) {
                    self::$old_menu = wp_get_nav_menu_object( $get_vars['menu'] );
                }
            } else {
                self::change_build_old_menu_terms_and_items();
            }
            self::$old_menu_locations = get_nav_menu_locations();
        }
    }

    /**
     * Customize set old data.
     */
    public static function change_customize_init() {
        self::change_build_old_menu_terms_and_items();
        self::$old_menu_locations = get_nav_menu_locations();
    }

    /**
     * Customize Events Function.
     */
    public static function change_customize_save() {
        $update_menus = array();
        $menus        = wp_get_nav_menus();
        if ( ! empty( $menus ) ) {
            foreach ( $menus as $menu ) {
                array_push(
                    $update_menus,
                    array(
                        'term_id' => $menu->term_id,
                        'name'    => $menu->name,
                    )
                );
            }
        }

        // Deleted Menu.
        if ( isset( $update_menus ) && isset( self::$old_menu_terms ) ) {
            $terms = array_diff( array_map( 'serialize', self::$old_menu_terms ), array_map( 'serialize', $update_menus ) );
            $terms = array_map( 'unserialize', $terms );

            if ( isset( $terms ) && count( $terms ) > 0 ) {
                foreach ( $terms as $term ) {
                    $log_data = array(
                        'menuname' => $term['name'],
                    );
                    Changes_Logs_Logger::log_change( 1490, $log_data );
                }
            }
        }

        // Filter $_POST array.
        $post_vars = filter_input_array( INPUT_POST );

        if ( isset( $post_vars['action'] ) && 'customize_save' === $post_vars['action'] ) {
            if ( isset( $post_vars['wp_customize'], $post_vars['customized'] ) ) {
                $customized = json_decode( wp_unslash( $post_vars['customized'] ), true );
                if ( is_array( $customized ) ) {
                    foreach ( $customized as $key => $value ) {
                        if ( ! empty( $value['nav_menu_term_id'] ) ) {
                            $is_occurred_event = false;
                            $menu              = wp_get_nav_menu_object( $value['nav_menu_term_id'] );
                            $content_name      = ! empty( $value['title'] ) ? $value['title'] : 'no title';
                            if ( ! empty( self::$old_menu_items ) ) {
                                foreach ( self::$old_menu_items as $old_item ) {
                                    $item_id = substr( trim( $key, ']' ), 14 );
                                    if ( $old_item['item_id'] === $item_id ) {
                                        // Modified Items in the menu.
                                        if ( $old_item['title'] !== $content_name ) {
                                            $is_occurred_event = true;
                                            self::callback_change_modified_items( $value['type_label'], $content_name, $menu->name, $menu->term_id );
                                        }
                                        // Moved as a sub-item.
                                        if ( $old_item['menu_item_parent'] !== $value['menu_item_parent'] && 0 !== $value['menu_item_parent'] ) {
                                            $is_occurred_event = true;
                                            $parent_name       = self::change_get_menu_item_name( $value['nav_menu_term_id'], $value['menu_item_parent'] );
                                            self::callback_change_change_sub_item( $content_name, $parent_name, $menu->name, $menu->term_id );
                                        }
                                        // Changed order of the objects in a menu.
                                        if ( $old_item['menu_order'] !== $value['position'] ) {
                                            $is_occurred_event = true;
                                            self::change_menu_order( $content_name, $menu->name, $menu->term_id );
                                        }
                                    }
                                }
                            }
                            // Add Items to the menu.
                            if ( ! $is_occurred_event ) {
                                $menu_name = ! empty( $customized['new_menu_name'] ) ? $customized['new_menu_name'] : $menu->name;
                                self::callback_change_add_items( $value['type_label'], $content_name, $menu_name, $menu->term_id );
                            }
                        } else {
                            // Menu changed name.
                            if ( isset( $update_menus ) && isset( self::$old_menu_terms ) ) {
                                foreach ( self::$old_menu_terms as $old_menu ) {
                                    foreach ( $update_menus as $update_menu ) {
                                        if ( $old_menu['term_id'] === $update_menu['term_id'] && $old_menu['name'] !== $update_menu['name'] ) {
                                            self::callback_change_change_name( $old_menu['name'], $update_menu['name'], $menu->term_id );
                                        }
                                    }
                                }
                            }
                            // Setting Auto add pages.
                            if ( ! empty( $value ) && isset( $value['auto_add'] ) ) {
                                if ( $value['auto_add'] ) {
                                    self::callback_change_menu_setting( $value['name'], 'Enabled', 'Auto add pages', $menu->term_id );
                                } else {
                                    self::callback_change_menu_setting( $value['name'], 'Disabled', 'Auto add pages', $menu->term_id );
                                }
                            }
                            // Setting Location.
                            if ( false !== strpos( $key, 'nav_menu_locations[' ) ) {
                                $loc = substr( trim( $key, ']' ), 19 );
                                if ( ! empty( $value ) ) {
                                    $menu      = wp_get_nav_menu_object( $value );
                                    $menu_name = ! empty( $customized['new_menu_name'] ) ? $customized['new_menu_name'] : ( ! empty( $menu ) ? $menu->name : '' );
                                    self::callback_change_menu_setting( $menu_name, 'Enabled', 'Location: ' . $loc . ' menu', $menu->term_id );
                                } elseif ( ! empty( self::$old_menu_locations[ $loc ] ) ) {
                                        $menu      = wp_get_nav_menu_object( self::$old_menu_locations[ $loc ] );
                                        $menu_name = ! empty( $customized['new_menu_name'] ) ? $customized['new_menu_name'] : ( ! empty( $menu ) ? $menu->name : '' );
                                        self::callback_change_menu_setting( $menu_name, 'Disabled', 'Location: ' . $loc . ' menu', $menu->term_id );
                                }
                            }
                            // Remove items from the menu.
                            if ( false !== strpos( $key, 'nav_menu_item[' ) ) {
                                $item_id = substr( trim( $key, ']' ), 14 );
                                if ( ! empty( self::$old_menu_items ) ) {
                                    foreach ( self::$old_menu_items as $old_item ) {
                                        if ( $old_item['item_id'] === $item_id ) {
                                            self::callback_change_remove_items( $old_item['object'], $old_item['title'], $old_item['menu_name'], $menu->term_id );
                                        }
                                    }
                                }
                            }
                        }
                    }
                }
            }
        }
    }

    /**
     * Added content to a menu.
     *
     * @param string $content_type - Type of content.
     * @param string $content_name - Name of content.
     * @param string $menu_name    - Menu name.
     * @param int    $menu_id      - Menu ID.
     */
    private static function callback_change_add_items( $content_type, $content_name, $menu_name, $menu_id ) {
        $log_data = array(
            'contenttype' => 'custom' === $content_type ? 'custom link' : $content_type,
            'contentname' => $content_name,
            'menuname'    => $menu_name,
            'menuid'      => $menu_id,
        );
        Changes_Logs_Logger::log_change( 1480, $log_data );
    }

    /**
     * Removed content from a menu.
     *
     * @param string $content_type - Type of content.
     * @param string $content_name - Name of content.
     * @param string $menu_name    - Menu name.
     * @param int    $menu_id      - Menu ID.
     */
    private static function callback_change_remove_items( $content_type, $content_name, $menu_name, $menu_id ) {
        $log_data = array(
            'contenttype' => 'custom' === $content_type ? 'custom link' : $content_type,
            'contentname' => $content_name,
            'menuname'    => $menu_name,
            'menuid'      => $menu_id,
        );
        Changes_Logs_Logger::log_change( 1485, $log_data );
    }

    /**
     * Changed menu setting.
     *
     * @param string $menu_name    - Menu Name.
     * @param string $status       - Status of menu.
     * @param string $menu_setting - Menu setting.
     * @param int    $menu_id      - Menu ID.
     */
    private static function callback_change_menu_setting( $menu_name, $status, $menu_setting, $menu_id ) {
        $status   = 'Enabled' === $status ? 'enabled' : 'disabled';
        $log_data = array(
            'actionname'  => $status,
            'MenuSetting' => $menu_setting,
            'menuname'    => $menu_name,
            'menuid'      => $menu_id,
        );
        Changes_Logs_Logger::log_change( 1495, $log_data );
    }

    /**
     * Modified content in a menu.
     *
     * @param string $content_type - Type of content.
     * @param string $content_name - Name of content.
     * @param string $menu_name    - Menu name.
     * @param int    $menu_id      - Menu ID.
     */
    private static function callback_change_modified_items( $content_type, $content_name, $menu_name, $menu_id ) {
        $log_data = array(
            'contenttype' => 'custom' === $content_type ? 'custom link' : $content_type,
            'contentname' => $content_name,
            'menuname'    => $menu_name,
            'menuid'      => $menu_id,
        );
        Changes_Logs_Logger::log_change( 1500, $log_data );
    }

    /**
     * Changed name of a menu.
     *
     * @param string $old_menu_name - Old Menu Name.
     * @param string $new_menu_name - New Menu Name.
     * @param int    $menu_id       Menu ID.
     */
    private static function callback_change_change_name( $old_menu_name, $new_menu_name, $menu_id ) {
        $log_data = array(
            'oldmenuname' => $old_menu_name,
            'menuname'    => $new_menu_name,
            'menuid'      => $menu_id,
        );
        Changes_Logs_Logger::log_change( 1505, $log_data );
    }

    /**
     * Changed order of the objects in a menu.
     *
     * @param string $item_name - Item name.
     * @param string $menu_name - Menu name.
     * @param int    $menu_id - Menu ID.
     */
    private static function change_menu_order( $item_name, $menu_name, $menu_id ) {
        // Skip if an order change for this menu has already been reported during the current request.
        if ( in_array( $menu_id, self::$order_changed_menu_ids, true ) ) {
            return;
        }
        $log_data = array(
            'itemname' => $item_name,
            'menuname' => $menu_name,
            'menuid'   => $menu_id,
        );
        Changes_Logs_Logger::log_change( 1510, $log_data );
        // To prevent repetitive events.
        array_push( self::$order_changed_menu_ids, $menu_id );
    }

    /**
     * Moved objects as a sub-item.
     *
     * @param string $item_name   - Item name.
     * @param string $parent_name - Parent Name.
     * @param string $menu_name   - Menu Name.
     * @param int    $menu_id     - Menu ID.
     */
    private static function callback_change_change_sub_item( $item_name, $parent_name, $menu_name, $menu_id ) {
        $log_data = array(
            'itemname'   => $item_name,
            'parentname' => $parent_name,
            'menuname'   => $menu_name,
            'menuid'     => $menu_id,
        );
        Changes_Logs_Logger::log_change( 1515, $log_data );
    }

    /**
     * Get menu item name.
     *
     * @param int $term_id - Term ID.
     * @param int $item_id - Item ID.
     *
     * @return string
     */
    private static function change_get_menu_item_name( $term_id, $item_id ) {
        $item_name  = '';
        $menu_items = wp_get_nav_menu_items( $term_id );
        foreach ( $menu_items as $menu_item ) {
            if ( $menu_item->ID === $item_id ) {
                $item_name = $menu_item->title;
                break;
            }
        }
        return $item_name;
    }
}
