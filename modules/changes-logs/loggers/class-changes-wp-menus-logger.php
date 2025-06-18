<?php
/**
 * Logger: Menus sensor.
 *
 * Menus logger class file.
 *
 * @since     4.5.1
 * @package   mainwp/child
 */

declare(strict_types=1);

namespace MainWP\Child\Changes\Loggers;

use MainWP\Child\Changes\Changes_Logs_Manager;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

	/**
	 * Menus sensor.
	 *
	 * 2078 User created new menu
	 * 2079 User added content to a menu
	 * 2080 User removed content from a menu
	 * 2081 User deleted menu
	 * 2082 User changed menu setting
	 * 2083 User modified content in a menu
	 * 2084 User changed name of a menu
	 * 2085 User changed order of the objects in a menu
	 * 2089 User moved objects as a sub-item
	 *
	 */
	class Changes_WP_Menus_Logger {

		/**
		 * Menu object.
		 *
		 * @var object
		 *
		 * @since 4.5.0
		 */
		private static $old_menu = null;

		/**
		 * Old Menu objects.
		 *
		 * @var array
		 *
		 * @since 4.5.0
		 */
		private static $old_menu_terms = array();

		/**
		 * Old Menu Items.
		 *
		 * @var array
		 *
		 * @since 4.5.0
		 */
		private static $old_menu_items = array();

		/**
		 * Old Menu Locations.
		 *
		 * @var array
		 *
		 * @since 4.5.0
		 */
		private static $old_menu_locations = null;

		/**
		 * An array of menu IDs for which an order change has already been reported during current request.
		 *
		 * @var array
		 * @since 4.5.0
		 */
		private static $order_changed_menu_ids = array();

		/**
		 * Inits the main hooks
		 *
		 * @return void
		 *
		 * @since 4.5.0
		 */
		public static function init() {
			add_action( 'wp_create_nav_menu', array( __CLASS__, 'create_menu' ), 10, 2 );
			add_action( 'wp_delete_nav_menu', array( __CLASS__, 'delete_menu' ), 10, 1 );
			add_action( 'wp_update_nav_menu', array( __CLASS__, 'update_menu' ), 10, 2 );

			add_action( 'wp_update_nav_menu_item', array( __CLASS__, 'update_menu_item' ), 10, 3 );
			add_action( 'admin_menu', array( __CLASS__, 'manage_menu_locations' ) );
			add_action( 'admin_init', array( __CLASS__, 'event_admin_init' ) );

			// Customizer trigger.
			add_action( 'customize_register', array( __CLASS__, 'customize_init' ) );
			add_action( 'customize_save_after', array( __CLASS__, 'customize_save' ) );
		}

		/**
		 * Menu item updated.
		 *
		 * @param int   $menu_id         - Menu ID.
		 * @param int   $menu_item_db_id - Menu item DB ID.
		 * @param array $args            - An array of items used to update menu.
		 *
		 * @return boolean
		 *
		 * @since 4.5.0
		 */
		public static function update_menu_item( $menu_id, $menu_item_db_id, $args ) {
			// Filter $_POST global array for security.
			$post_array = filter_input_array( INPUT_POST );

			$old_menu_items = array();
			if ( isset( $post_array['menu-item-title'] ) && isset( $post_array['menu-name'] ) ) {
				$is_changed_order = false;
				$is_sub_item      = false;
				$new_menu_items   = array_keys( $post_array['menu-item-title'] );
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
									// Verify nonce.
									if ( ! wp_verify_nonce( $post_array['update-nav-menu-nonce'], 'update-nav_menu' ) ) {
										return false;
									}

									self::event_modified_items( $post_array['menu-item-object'][ $menu_item_db_id ], $post_array['menu-item-title'][ $menu_item_db_id ], $post_array['menu-name'], $menu_id );
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

				// Add Items to the menu.
				$added_items = array_diff( $new_menu_items, array_keys( $old_menu_items ) );
				if ( count( $added_items ) > 0 && wp_verify_nonce( $post_array['update-nav-menu-nonce'], 'update-nav_menu' ) ) {
					if ( in_array( $menu_item_db_id, $added_items, true ) ) {
						self::event_add_items( $post_array['menu-item-object'][ $menu_item_db_id ], $post_array['menu-item-title'][ $menu_item_db_id ], $post_array['menu-name'], $menu_id );
					}
				}

				// Remove items from the menu.
				$removed_items = array_diff( array_keys( $old_menu_items ), $new_menu_items );
				if ( count( $removed_items ) > 0 && wp_verify_nonce( $post_array['update-nav-menu-nonce'], 'update-nav_menu' ) ) {
					if ( array_search( $menu_item_db_id, $new_menu_items, true ) === ( count( $new_menu_items ) - 1 ) ) {
						foreach ( $removed_items as $removed_item_id ) {
							self::event_remove_items( $old_menu_items[ $removed_item_id ]['type'], $old_menu_items[ $removed_item_id ]['title'], $post_array['menu-name'], $menu_id );
						}
					}
				}

				// We want to ignore order changes when menu items are added, removed or another order change has already
				// been logged during this request.
				$ignore_order_change = ! empty( $removed_items ) || ! empty( $added_items );

				// Check if an order has changed.
				if ( ! $ignore_order_change && $is_changed_order && wp_verify_nonce( $post_array['meta-box-order-nonce'], 'meta-box-order' ) ) {
					$old_item    = $old_menu_items[ $menu_item_db_id ];
					$menu_object = wp_get_nav_menu_object( $menu_id );
					if ( $menu_object instanceof \WP_Term ) {
						self::event_change_order( $old_item['title'], $menu_object->name, $menu_id );
					}
				}

				if ( $is_sub_item && wp_verify_nonce( $post_array['update-nav-menu-nonce'], 'update-nav_menu' ) ) {
					$item_parent_id = $args['menu-item-parent-id'];
					$item_name      = $old_menu_items[ $menu_item_db_id ]['title'];
					if ( $old_menu_items[ $menu_item_db_id ]['parent'] !== $item_parent_id ) {
						$parent_name = isset( $old_menu_items[ $item_parent_id ]['title'] ) ? $old_menu_items[ $item_parent_id ]['title'] : false;
						self::event_change_sub_item( $item_name, $parent_name, $post_array['menu-name'], $menu_id );
					}
				}
			}
		}

		/**
		 * New menu created.
		 *
		 * @param int   $term_id - Term ID.
		 * @param array $menu_data - Menu data.
		 *
		 * @since 4.5.0
		 */
		public static function create_menu( $term_id, $menu_data ) {
			Changes_Logs_Manager::trigger_event(
				2078,
				array(
					'MenuName' => $menu_data['menu-name'],
					'MenuID'   => $term_id,
				)
			);
		}

		/**
		 * New menu created.
		 *
		 * @global array $_POST Data post.
		 *
		 * @since 4.5.0
		 */
		public static function manage_menu_locations() {
			// Filter $_POST global array for security.
			$post_array = filter_input_array( INPUT_POST );

			// Verify nonce.
			if ( ! isset( $post_array['_wpnonce'] ) || ! wp_verify_nonce( $post_array['_wpnonce'], 'save-menu-locations' ) ) {
				return;
			}

			// Manage Location tab.
			if ( isset( $post_array['menu-locations'] ) ) {
				$new_locations = \sanitize_text_field( \wp_unslash( $post_array['menu-locations'] ) );
				if ( isset( $new_locations['top'] ) ) {
					self::location_setting( $new_locations['top'], 'top' );
				}
				if ( isset( $new_locations['social'] ) ) {
					self::location_setting( $new_locations['social'], 'social' );
				}
			}
		}

		/**
		 * Menu location.
		 *
		 * @param integer $new_location - New location.
		 * @param string  $type - Location type.
		 *
		 * @since 4.5.0
		 */
		private static function location_setting( $new_location, $type ) {
			$old_locations = get_nav_menu_locations();
			if ( 0 !== $new_location ) {
				$menu = wp_get_nav_menu_object( $new_location );
				if ( isset( $old_locations[ $type ] ) && $old_locations[ $type ] !== $new_location ) {
					self::event_menu_setting( $menu->name, 'Enabled', 'Location: ' . $type . ' menu', $menu->term_id );
				}
			} elseif ( ! empty( $old_locations[ $type ] ) ) {
				$menu = wp_get_nav_menu_object( $old_locations[ $type ] );
				self::event_menu_setting( $menu->name, 'Disabled', 'Location: ' . $type . ' menu', $menu->term_id );
			}
		}

		/**
		 * Menu deleted.
		 *
		 * @param int $term_id - Term ID.
		 *
		 * @since 4.5.0
		 */
		public static function delete_menu( $term_id ) {
			if ( self::$old_menu ) {
				Changes_Logs_Manager::trigger_event(
					2081,
					array(
						'MenuName' => self::$old_menu->name,
						'MenuID'   => $term_id,
					)
				);
			}
		}

		/**
		 * Menu updated.
		 *
		 * @param int   $menu_id - Menu ID.
		 * @param array $menu_data (Optional) Menu data.
		 *
		 * @since 4.5.0
		 */
		public static function update_menu( $menu_id, $menu_data = null ) {
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

				// Filter $_POST global array for security.
				$post_array = filter_input_array( INPUT_POST );

				// Menu changed name.
				if ( ! empty( self::$old_menu_terms ) && isset( $post_array['menu'] ) && isset( $post_array['menu-name'] ) ) {
					foreach ( self::$old_menu_terms as $old_menu_term ) {
						if ( $old_menu_term['term_id'] === (int) $post_array['menu'] && wp_verify_nonce( $post_array['update-nav-menu-nonce'], 'update-nav_menu' ) ) {
							if ( $old_menu_term['name'] !== $post_array['menu-name'] ) {
								self::event_change_name( $old_menu_term['name'], $post_array['menu-name'], $menu_id );
							} elseif ( count( $content_names_old ) === 1 && count( $content_types_old ) === 1 ) {
								self::event_remove_items( $content_types_old[0], $content_names_old[0], $post_array['menu-name'], $menu_id );
							}
						}
					}
				}

				// Enable/Disable menu setting.
				$nav_menu_options = maybe_unserialize( get_option( 'nav_menu_options' ) );
				$auto_add         = null;
				if ( isset( $nav_menu_options['auto_add'] ) ) {
					if ( in_array( $menu_id, $nav_menu_options['auto_add'], true ) ) {
						if ( empty( $post_array['auto-add-pages'] ) ) {
							$auto_add = 'Disabled';
						}
					} elseif ( isset( $post_array['auto-add-pages'] ) ) {
							$auto_add = 'Enabled';
					}
				} elseif ( isset( $post_array['auto-add-pages'] ) ) {
						$auto_add = 'Enabled';
				}

				// Alert 2082 Auto add pages.
				if ( ! empty( $auto_add ) ) {
					self::event_menu_setting( $menu_data['menu-name'], $auto_add, 'Auto add pages', $menu_id );
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

				// Alert 2082 top menu.
				if ( ! empty( $location_top ) ) {
					self::event_menu_setting( $menu_data['menu-name'], $location_top, 'Location: top menu', $menu_id );
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

				// Alert 2082 Social links menu.
				if ( ! empty( $location_social ) ) {
					self::event_menu_setting( $menu_data['menu-name'], $location_social, 'Location: social menu', $menu_id );
				}
			}
		}

		/**
		 * Set old menu terms and items.
		 *
		 * @since 4.5.0
		 */
		private static function build_old_menu_terms_and_items() {
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
		 * Triggered when a user accesses the admin area.
		 *
		 * @since 4.5.0
		 */
		public static function event_admin_init() {
			// Filter global arrays for security.
			$server_array = filter_input_array( INPUT_SERVER );
			$get_array    = filter_input_array( INPUT_GET );

			// Check if SCRIPT_NAME exists or not.
			$script_name = '';
			if ( ! empty( $server_array['SCRIPT_NAME'] ) ) {
				$script_name = \sanitize_text_field( \wp_unslash( $server_array['SCRIPT_NAME'] ) );
			}

			$is_nav_menu = basename( $script_name ) === 'nav-menus.php';
			if ( $is_nav_menu ) {
				if ( isset( $get_array['action'] ) && 'delete' === $get_array['action'] ) {
					if ( isset( $get_array['menu'] ) ) {
						self::$old_menu = wp_get_nav_menu_object( $get_array['menu'] );
					}
				} else {
					self::build_old_menu_terms_and_items();
				}
				self::$old_menu_locations = get_nav_menu_locations();
			}
		}

		/**
		 * Customize set old data.
		 *
		 * @since 4.5.0
		 */
		public static function customize_init() {
			self::build_old_menu_terms_and_items();
			self::$old_menu_locations = get_nav_menu_locations();
		}

		/**
		 * Customize Events Function.
		 *
		 * @since 4.5.0
		 */
		public static function customize_save() {
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
						Changes_Logs_Manager::trigger_event(
							2081,
							array(
								'MenuName' => $term['name'],
							)
						);
					}
				}
			}

			// Filter $_POST global array for security.
			$post_array = filter_input_array( INPUT_POST );

			if ( isset( $post_array['action'] ) && 'customize_save' === $post_array['action'] ) {
				if ( isset( $post_array['wp_customize'], $post_array['customized'] ) ) {
					$customized = json_decode( wp_unslash( $post_array['customized'] ), true );
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
												self::event_modified_items( $value['type_label'], $content_name, $menu->name, $menu->term_id );
											}
											// Moved as a sub-item.
											if ( $old_item['menu_item_parent'] !== $value['menu_item_parent'] && 0 !== $value['menu_item_parent'] ) {
												$is_occurred_event = true;
												$parent_name       = self::get_item_name( $value['nav_menu_term_id'], $value['menu_item_parent'] );
												self::event_change_sub_item( $content_name, $parent_name, $menu->name, $menu->term_id );
											}
											// Changed order of the objects in a menu.
											if ( $old_item['menu_order'] !== $value['position'] ) {
												$is_occurred_event = true;
												self::event_change_order( $content_name, $menu->name, $menu->term_id );
											}
										}
									}
								}
								// Add Items to the menu.
								if ( ! $is_occurred_event ) {
									$menu_name = ! empty( $customized['new_menu_name'] ) ? $customized['new_menu_name'] : $menu->name;
									self::event_add_items( $value['type_label'], $content_name, $menu_name, $menu->term_id );
								}
							} else {
								// Menu changed name.
								if ( isset( $update_menus ) && isset( self::$old_menu_terms ) ) {
									foreach ( self::$old_menu_terms as $old_menu ) {
										foreach ( $update_menus as $update_menu ) {
											if ( $old_menu['term_id'] === $update_menu['term_id'] && $old_menu['name'] !== $update_menu['name'] ) {
												self::event_change_name( $old_menu['name'], $update_menu['name'], $menu->term_id );
											}
										}
									}
								}
								// Setting Auto add pages.
								if ( ! empty( $value ) && isset( $value['auto_add'] ) ) {
									if ( $value['auto_add'] ) {
										self::event_menu_setting( $value['name'], 'Enabled', 'Auto add pages', $menu->term_id );
									} else {
										self::event_menu_setting( $value['name'], 'Disabled', 'Auto add pages', $menu->term_id );
									}
								}
								// Setting Location.
								if ( false !== strpos( $key, 'nav_menu_locations[' ) ) {
									$loc = substr( trim( $key, ']' ), 19 );
									if ( ! empty( $value ) ) {
										$menu      = wp_get_nav_menu_object( $value );
										$menu_name = ! empty( $customized['new_menu_name'] ) ? $customized['new_menu_name'] : ( ! empty( $menu ) ? $menu->name : '' );
										self::event_menu_setting( $menu_name, 'Enabled', 'Location: ' . $loc . ' menu', $menu->term_id );
									} elseif ( ! empty( self::$old_menu_locations[ $loc ] ) ) {
											$menu      = wp_get_nav_menu_object( self::$old_menu_locations[ $loc ] );
											$menu_name = ! empty( $customized['new_menu_name'] ) ? $customized['new_menu_name'] : ( ! empty( $menu ) ? $menu->name : '' );
											self::event_menu_setting( $menu_name, 'Disabled', 'Location: ' . $loc . ' menu', $menu->term_id );
									}
								}
								// Remove items from the menu.
								if ( false !== strpos( $key, 'nav_menu_item[' ) ) {
									$item_id = substr( trim( $key, ']' ), 14 );
									if ( ! empty( self::$old_menu_items ) ) {
										foreach ( self::$old_menu_items as $old_item ) {
											if ( $old_item['item_id'] === $item_id ) {
												self::event_remove_items( $old_item['object'], $old_item['title'], $old_item['menu_name'], $menu->term_id );
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
		 *
		 * @since 4.5.0
		 */
		private static function event_add_items( $content_type, $content_name, $menu_name, $menu_id ) {
			Changes_Logs_Manager::trigger_event(
				2079,
				array(
					'ContentType' => 'custom' === $content_type ? 'custom link' : $content_type,
					'ContentName' => $content_name,
					'MenuName'    => $menu_name,
					'MenuID'      => $menu_id,
				)
			);
		}

		/**
		 * Removed content from a menu.
		 *
		 * @param string $content_type - Type of content.
		 * @param string $content_name - Name of content.
		 * @param string $menu_name    - Menu name.
		 * @param int    $menu_id      - Menu ID.
		 *
		 * @since 4.5.0
		 */
		private static function event_remove_items( $content_type, $content_name, $menu_name, $menu_id ) {
			Changes_Logs_Manager::trigger_event(
				2080,
				array(
					'ContentType' => 'custom' === $content_type ? 'custom link' : $content_type,
					'ContentName' => $content_name,
					'MenuName'    => $menu_name,
					'MenuID'      => $menu_id,
				)
			);
		}

		/**
		 * Changed menu setting.
		 *
		 * @param string $menu_name    - Menu Name.
		 * @param string $status       - Status of menu.
		 * @param string $menu_setting - Menu setting.
		 * @param int    $menu_id      - Menu ID.
		 *
		 * @since 4.5.0
		 */
		private static function event_menu_setting( $menu_name, $status, $menu_setting, $menu_id ) {
			$status = 'Enabled' === $status ? 'enabled' : 'disabled';
			Changes_Logs_Manager::trigger_event(
				2082,
				array(
					'EventType'   => $status,
					'MenuSetting' => $menu_setting,
					'MenuName'    => $menu_name,
					'MenuID'      => $menu_id,
				)
			);
		}

		/**
		 * Modified content in a menu.
		 *
		 * @param string $content_type - Type of content.
		 * @param string $content_name - Name of content.
		 * @param string $menu_name    - Menu name.
		 * @param int    $menu_id      - Menu ID.
		 *
		 * @since 4.5.0
		 */
		private static function event_modified_items( $content_type, $content_name, $menu_name, $menu_id ) {
			Changes_Logs_Manager::trigger_event(
				2083,
				array(
					'ContentType' => 'custom' === $content_type ? 'custom link' : $content_type,
					'ContentName' => $content_name,
					'MenuName'    => $menu_name,
					'MenuID'      => $menu_id,
				)
			);
		}

		/**
		 * Changed name of a menu.
		 *
		 * @param string $old_menu_name - Old Menu Name.
		 * @param string $new_menu_name - New Menu Name.
		 * @param int    $menu_id       Menu ID.
		 *
		 * @since 4.5.0
		 */
		private static function event_change_name( $old_menu_name, $new_menu_name, $menu_id ) {
			Changes_Logs_Manager::trigger_event(
				2084,
				array(
					'OldMenuName' => $old_menu_name,
					'MenuName'    => $new_menu_name,
					'MenuID'      => $menu_id,
				)
			);
		}

		/**
		 * Changed order of the objects in a menu.
		 *
		 * @param string $item_name - Item name.
		 * @param string $menu_name - Menu name.
		 * @param int    $menu_id - Menu ID.
		 *
		 * @since 4.5.0
		 */
		private static function event_change_order( $item_name, $menu_name, $menu_id ) {
			// Skip if an order change for this menu has already been reported during the current request.
			if ( in_array( $menu_id, self::$order_changed_menu_ids, true ) ) {
				return;
			}

			Changes_Logs_Manager::trigger_event(
				2085,
				array(
					'ItemName' => $item_name,
					'MenuName' => $menu_name,
					'MenuID'   => $menu_id,
				)
			);

			// Keep track of already reported order changes to prevent repetitive events.
			array_push( self::$order_changed_menu_ids, $menu_id );
		}

		/**
		 * Moved objects as a sub-item.
		 *
		 * @param string $item_name   - Item name.
		 * @param string $parent_name - Parent Name.
		 * @param string $menu_name   - Menu Name.
		 * @param int    $menu_id     - Menu ID.
		 *
		 * @since 4.5.0
		 */
		private static function event_change_sub_item( $item_name, $parent_name, $menu_name, $menu_id ) {
			Changes_Logs_Manager::trigger_event(
				2089,
				array(
					'ItemName'   => $item_name,
					'ParentName' => $parent_name,
					'MenuName'   => $menu_name,
					'MenuID'     => $menu_id,
				)
			);
		}

		/**
		 * Get menu item name.
		 *
		 * @param int $term_id - Term ID.
		 * @param int $item_id - Item ID.
		 *
		 * @return string
		 *
		 * @since 4.5.0
		 */
		private static function get_item_name( $term_id, $item_id ) {
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
