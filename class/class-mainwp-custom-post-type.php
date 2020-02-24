<?php

class MainWP_Custom_Post_Type {
	public static $instance = null;
	public static $information = array();
	public $plugin_translate = "mainwp-child";

	static function Instance() {
		if ( MainWP_Custom_Post_Type::$instance == null ) {
			MainWP_Custom_Post_Type::$instance = new MainWP_Custom_Post_Type();
		}

		return MainWP_Custom_Post_Type::$instance;
	}

	public function action() {
		error_reporting( 0 );
		function mainwp_custom_post_type_handle_fatal_error() {

			$error = error_get_last();
			if ( isset( $error['type'] ) && E_ERROR === $error['type'] && isset( $error['message'] ) ) {
				$data = array( 'error' => 'MainWPChild fatal error : ' . $error['message'] . ' Line: ' . $error['line'] . ' File: ' . $error['file'] );
//				die( '<mainwp>' . base64_encode( serialize(  ) ) . '</mainwp>' );
			} else {
				$data = MainWP_Custom_Post_Type::$information;
//				die( '<mainwp>' . base64_encode( serialize( MainWP_Custom_Post_Type::$information ) ) . '</mainwp>' );
			}
			
			if ( isset( $_REQUEST['json_result'] ) && $_REQUEST['json_result'] ) {
				$data = json_encode( $data );
			} else {
				$data = serialize( $data );
			}
			
			die('<mainwp>' . base64_encode( $data ) . '</mainwp>');
		}

		register_shutdown_function( "mainwp_custom_post_type_handle_fatal_error" );

		$information = array();
		switch ( $_POST['action'] ) {
			case 'custom_post_type_import':
				$information = $this->_import();
				break;

			default:
				$information = array( 'error' => 'Unknown action' );

		}

		MainWP_Custom_Post_Type::$information = $information;

		exit();
	}

	private function _import() {
		add_filter( 'http_request_host_is_external', '__return_true' );

		if ( ! isset( $_POST['data'] ) || strlen( $_POST['data'] ) < 2 ) {
			return array( 'error' => __( 'Missing data', $this->plugin_translate ) );
		}

		$data = stripslashes( $_POST['data'] );

		$data = json_decode( $data, true );

		if ( empty( $data ) || ! is_array( $data ) || ! isset( $data['post'] ) ) {
			return array( 'error' => __( 'Cannot decode data', $this->plugin_translate ) );
		}
        $edit_id = (isset($_POST['post_id']) && !empty($_POST['post_id'])) ? $_POST['post_id'] : 0;
		$return = $this->_insert_post($data, $edit_id, $parent_id = 0);
        if (isset($return['success']) && $return['success'] == 1) {
            if (isset($data['product_variation']) && is_array($data['product_variation'])) {
                foreach ($data['product_variation'] as $product_variation) {
                    $return_variantion = $this->_insert_post($product_variation, 0, $return['post_id']);
                }
            }
        }
        return $return;
	}



	/**
	 * Search image inside post content and upload it to child
	 **/
	private function _search_images( $post_content, $upload_dir, $check_image = false  ) {
		$foundMatches = preg_match_all( '/(<a[^>]+href=\"(.*?)\"[^>]*>)?(<img[^>\/]*src=\"((.*?)(png|gif|jpg|jpeg))\")/ix', $post_content, $matches, PREG_SET_ORDER );
		if ( $foundMatches > 0 ) {
			foreach ( $matches as $match ) {
				$hrefLink = $match[2];
				$imgUrl   = $match[4];

				if ( ! isset( $upload_dir['baseurl'] ) || ( 0 !== strripos( $imgUrl, $upload_dir['baseurl'] ) ) ) {
					continue;
				}

				if ( preg_match( '/-\d{3}x\d{3}\.[a-zA-Z0-9]{3,4}$/', $imgUrl, $imgMatches ) ) {
					$search         = $imgMatches[0];
					$replace        = '.' . $match[6];
					$originalImgUrl = str_replace( $search, $replace, $imgUrl );
				} else {
					$originalImgUrl = $imgUrl;
				}

				try {
					$downloadfile      = MainWP_Helper::uploadImage( $originalImgUrl , array(), $check_image );
					$localUrl          = $downloadfile['url'];
					$linkToReplaceWith = dirname( $localUrl );
					if ( '' !== $hrefLink ) {
						$server     = get_option( 'mainwp_child_server' );
						$serverHost = parse_url( $server, PHP_URL_HOST );
						if ( ! empty( $serverHost ) && strpos( $hrefLink, $serverHost ) !== false ) {
							$serverHref        = 'href="' . $serverHost;
							$replaceServerHref = 'href="' . parse_url( $localUrl, PHP_URL_SCHEME ) . '://' . parse_url( $localUrl, PHP_URL_HOST );
							$post_content      = str_replace( $serverHref, $replaceServerHref, $post_content );
						} else if ( strpos( $hrefLink, 'http' ) !== false ) {
							$lnkToReplace = dirname( $hrefLink );
							if ( 'http:' !== $lnkToReplace && 'https:' !== $lnkToReplace ) {
								$post_content = str_replace( $lnkToReplace, $linkToReplaceWith, $post_content );
							}
						}
					}

					$lnkToReplace = dirname( $imgUrl );
					if ( 'http:' !== $lnkToReplace && 'https:' !== $lnkToReplace ) {
						$post_content = str_replace( $lnkToReplace, $linkToReplaceWith, $post_content );
					}
				} catch ( Exception $e ) {

				}
			}
		}

		return $post_content;
	}

    private function _insert_post( $data, $edit_id, $parent_id = 0 ) {

		// Insert post
		$data_insert                = array();
		$data_post                  = $data['post'];
		$data_insert['post_author'] = get_current_user_id();

		$data_keys = array(
			'post_date',
			'post_date_gmt',
			'post_content',
			'post_title',
			'post_excerpt',
			'post_status',
			'comment_status',
			'ping_status',
			'post_password',
			'post_name',
			'to_ping',
			'pinged',
			'post_modified',
			'post_modified_gmt',
			'post_content_filtered',
			'menu_order',
			'post_type'
		);

		foreach ( $data_keys as $key ) {
			if ( ! isset( $data_post[ $key ] ) ) {
				return array( 'error' => _( 'Missing', $this->plugin_translate ) . ' ' . $key . ' ' . __( 'inside post data', $this->plugin_translate ) );
			}

			$data_insert[ $key ] = $data_post[ $key ];
		}

		if ( ! in_array( $data_insert['post_type'], get_post_types( array( '_builtin' => false ) ) ) ) {
			return array( 'error' => __( 'Please install', $this->plugin_translate ) . ' ' . $data_insert['post_type'] . ' ' . __( 'on child and try again', $this->plugin_translate ) );
		}

		//$data_insert['post_content'] = $this->_search_images( $data_insert['post_content'], $data['extras']['upload_dir'] );

		$is_woocomerce = false;
		if ( ($data_insert['post_type'] == 'product' || $data_insert['post_type'] == 'product_variation' )&& function_exists( 'wc_product_has_unique_sku' ) ) {
			$is_woocomerce = true;
		}

        $check_image_existed = false;

		// Support post_edit
		if ( !empty( $edit_id ) ) {
			$old_post_id = (int) $edit_id;
			$old_post    = get_post( $old_post_id, ARRAY_A );
			if ( is_null( $old_post ) ) {
				return array(
					'delete_connection' => 1,
					'error'             => __( 'Cannot get old post. Probably is deleted now. Please try again for create new post', $this->plugin_translate )
				);
			}

			if ( get_post_status( $old_post_id ) == 'trash' ) {
				return array( 'error' => __( 'This post is inside trash on child website. Please try publish it manually and try again.', $this->plugin_translate ) );
			}
            $check_image_existed = true;
			// Set id
			$data_insert['ID'] = $old_post_id;

			// Remove all previous post meta
			// Get all unique meta_key
			foreach ( get_post_meta( $old_post_id ) as $temp_meta_key => $temp_meta_val ) {
				if ( ! delete_post_meta( $old_post_id, $temp_meta_key ) ) {
					return array( 'error' => __( 'Cannot delete old post meta values', $this->plugin_translate ) );
				}
			}

			// Remove all previous taxonomy
			wp_delete_object_term_relationships( $old_post_id, get_object_taxonomies( $data_insert['post_type'] ) );
		}

        $data_insert['post_content'] = $this->_search_images( $data_insert['post_content'], $data['extras']['upload_dir'], $check_image_existed );

        if (!empty($parent_id)) {
            $data_insert['post_parent'] = $parent_id; // for product variation
        }
		$post_id = wp_insert_post( $data_insert, true );
		if ( is_wp_error( $post_id ) ) {
			return array( 'error' => __( 'Error when insert new post:', $this->plugin_translate ) . ' ' . $post_id->get_error_message() );
		}

		// Insert post meta
		if ( ! empty( $data['postmeta'] ) && is_array( $data['postmeta'] ) ) {
			foreach ( $data['postmeta'] as $key ) {
				if ( isset( $key['meta_key'] ) && isset( $key['meta_value'] ) ) {
					if ( $is_woocomerce ) {
						if ( $key['meta_key'] == '_sku' ) {
							if ( ! wc_product_has_unique_sku( $post_id, $key['meta_value'] ) ) {
								return array( 'error' => __( 'Product SKU must be unique', $this->plugin_translate ) );
							}
						}

						if ( $key['meta_key'] == '_product_image_gallery' ) {
							$product_image_gallery = array();
							if ( isset($data['extras']['woocommerce']['product_images']) ) {
								foreach ( $data['extras']['woocommerce']['product_images'] as $product_image ) {
									try {
										$upload_featured_image = MainWP_Helper::uploadImage( $product_image, array(), $check_image_existed );

										if ( null !== $upload_featured_image ) {
											$product_image_gallery[] = $upload_featured_image['id'];
										} else {
											return array( 'error' => __( 'Cannot add product image', $this->plugin_translate ) );
										}
									} catch ( Exception $e ) {
										continue;
									}
								}
								$key['meta_value'] = implode( $product_image_gallery, ',' );
							} else {
								continue;
							}
						}
					}

					if ( $key['meta_key'] == '_thumbnail_id' ) {
						if ( isset( $data['extras']['featured_image']) ) {
							try {
								$upload_featured_image = MainWP_Helper::uploadImage( $data['extras']['featured_image'], array(), $check_image_existed );

								if ( null !== $upload_featured_image ) {
									$key['meta_value'] = $upload_featured_image['id'];
								} else {
									return array( 'error' => __( 'Cannot add featured image', $this->plugin_translate ) );
								}
							} catch ( Exception $e ) {
								continue;
							}
						} else {
							continue;
						}
					}

                    $meta_value = maybe_unserialize( $key['meta_value'] );
					if ( add_post_meta( $post_id, $key['meta_key'], $meta_value ) === false ) {
						return array( 'error' => __( 'Error when adding post meta', $this->plugin_translate ) . ' `' . esc_html( $key['meta_key'] ) . '`' );
					}
				}
			}
		}

		// MainWP Categories
		if ( ! empty( $data['categories'] ) && is_array( $data['categories'] ) ) {
			// Contains wp_create_categories
			include_once( ABSPATH . 'wp-admin/includes/taxonomy.php' );
			$categories = $data['categories'];
			if ( $data['post_only_existing'] == '0' ) {
				$post_category = wp_create_categories( $categories, $post_id );
			} else {
				$cat_ids = array();
				foreach ( $categories as $cat ) {
					if ( $id = category_exists( $cat ) ) {
						$cat_ids[] = $id;
					}
				}
				if ( count( $cat_ids ) > 0 ) {
					wp_set_post_categories( $post_id, $cat_ids );
				}
			}
		}

		//Insert post terms except categories
		if ( ! empty( $data['terms'] ) && is_array( $data['terms'] ) ) {
			foreach ( $data['terms'] as $key ) {
				if ( ! taxonomy_exists( $key['taxonomy'] ) ) {
					return array( 'error' => __( 'Missing taxonomy', $this->plugin_translate ) . ' `' . esc_html( $key['taxonomy'] ) . '`' );
				}

				// @todo missing alias_of which means term_group
				$term = wp_insert_term( $key['name'], $key['taxonomy'], array(
					'description' => $key['description'],
					'slug'        => $key['slug']
				) );

				$term_taxonomy_id = 0;

				if ( is_wp_error( $term ) ) {
					if ( isset( $term->error_data['term_exists'] ) ) {
						$term_taxonomy_id = (int) $term->error_data['term_exists'];
					}
				} else {
					if ( isset( $term['term_taxonomy_id'] ) ) {
						$term_taxonomy_id = (int) $term['term_taxonomy_id'];
					}
				}

				if ( $term_taxonomy_id > 0 ) {
					$term_taxonomy_ids = wp_set_object_terms( $post_id, $term_taxonomy_id, $key['taxonomy'], true );
					if ( is_wp_error( $term_taxonomy_ids ) ) {
						return array( 'error' => __( 'Error when adding taxonomy to post', $this->plugin_translate ) );
					}
				}
			}
		}

		return array( 'success' => 1, 'post_id' => $post_id );
	}
}
