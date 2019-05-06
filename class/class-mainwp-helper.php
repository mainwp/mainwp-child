<?php

class MainWP_Helper {

	static function write( $val ) {
		$output = serialize( $val );
		die( '<mainwp>' . base64_encode( $output ) . '</mainwp>' );
	}

	static function close_connection( $val = null ) {
		$output = serialize( $val );
		$output = '<mainwp>' . base64_encode( $output ) . '</mainwp>';
		// Close browser connection so that it can resume AJAX polling
		header( 'Content-Length: ' . strlen( $output ) );
		header( 'Connection: close' );
		header( 'Content-Encoding: none' );
		if ( session_id() ) {
			session_write_close();
		}
		echo $output;
		if ( ob_get_level() ) {
			ob_end_flush();
		}
		flush();
	}

	static function error( $error, $code = null ) {
		$information['error'] = $error;
		if (null !== $code)
			$information['error_code'] = $code;
		MainWP_Helper::write( $information );
	}

	/**
	 * PARSE
	 * Parses some CSS into an array
	 * CSSPARSER
	 * Copyright (C) 2009 Peter Kröner
	 */
	public static function parse_css($css){

		// Remove CSS-Comments
		$css = preg_replace('/\/\*.*?\*\//ms', '', $css);
		// Remove HTML-Comments
		$css = preg_replace('/([^\'"]+?)(\<!--|--\>)([^\'"]+?)/ms', '$1$3', $css);
		// Extract @media-blocks into $blocks
		preg_match_all('/@.+?\}[^\}]*?\}/ms',$css, $blocks);
		// Append the rest to $blocks
		array_push($blocks[0],preg_replace('/@.+?\}[^\}]*?\}/ms','',$css));
		$ordered = array();
		for($i=0;$i<count($blocks[0]);$i++){
			// If @media-block, strip declaration and parenthesis
			if(substr($blocks[0][$i],0,6) === '@media')
			{
				$ordered_key = preg_replace('/^(@media[^\{]+)\{.*\}$/ms','$1',$blocks[0][$i]);
				$ordered_value = preg_replace('/^@media[^\{]+\{(.*)\}$/ms','$1',$blocks[0][$i]);
			}
			// Rule-blocks of the sort @import or @font-face
			elseif(substr($blocks[0][$i],0,1) === '@')
			{
				$ordered_key = $blocks[0][$i];
				$ordered_value = $blocks[0][$i];
			}
			else
			{
				$ordered_key = 'main';
				$ordered_value = $blocks[0][$i];
			}
			// Split by parenthesis, ignoring those inside content-quotes
			$ordered[$ordered_key] = preg_split('/([^\'"\{\}]*?[\'"].*?(?<!\\\)[\'"][^\'"\{\}]*?)[\{\}]|([^\'"\{\}]*?)[\{\}]/',trim($ordered_value," \r\n\t"),-1,PREG_SPLIT_NO_EMPTY|PREG_SPLIT_DELIM_CAPTURE);
		}

		// Beginning to rebuild new slim CSS-Array
		foreach($ordered as $key => $val){
			$new = array();
			for($i = 0; $i<count($val); $i++){
				// Split selectors and rules and split properties and values
				$selector = trim($val[$i]," \r\n\t");

				if(!empty($selector)){
					if(!isset($new[$selector])) $new[$selector] = array();
					$rules = explode(';',$val[++$i]);
					foreach($rules as $rule){
						$rule = trim($rule," \r\n\t");
						if(!empty($rule)){
							$rule = array_reverse(explode(':', $rule));
							$property = trim(array_pop($rule)," \r\n\t");
							$value = implode(':', array_reverse($rule));

							if(!isset($new[$selector][$property]) || !preg_match('/!important/',$new[$selector][$property])) $new[$selector][$property] = $value;
							elseif(preg_match('/!important/',$new[$selector][$property]) && preg_match('/!important/',$value)) $new[$selector][$property] = $value;
						}
					}
				}
			}
			$ordered[$key] = $new;
		}
		$parsed = $ordered;

		$output = '';
		foreach($parsed as $media => $content){
			if(substr($media,0,6) === '@media'){
				$output .= $media . " {\n";
				$prefix = "\t";
			}
			else $prefix = "";

			foreach($content as $selector => $rules){
				$output .= $prefix.$selector . " {\n";
				foreach($rules as $property => $value){
					$output .= $prefix."\t".$property.': '.$value;
					$output .= ";\n";
				}
				$output .= $prefix."}\n\n";
			}
			if(substr($media,0,6) === '@media'){
				$output .= "}\n\n";
			}
		}
		return $output;

	}

    // $check_file_existed: to support checking if file existed
    // $parent_id: optional
	static function uploadImage( $img_url, $img_data = array() , $check_file_existed = false, $parent_id = 0 ) {
		if ( !is_array($img_data) )
			$img_data = array();
		include_once( ABSPATH . 'wp-admin/includes/file.php' ); //Contains download_url
		$upload_dir     = wp_upload_dir();
		//Download $img_url
		$temporary_file = download_url( $img_url );

		if ( is_wp_error( $temporary_file ) ) {
			throw new Exception( 'Error: ' . $temporary_file->get_error_message() );
		} else {
            $filename = basename( $img_url );
			$local_img_path = $upload_dir['path'] . DIRECTORY_SEPARATOR . $filename; //Local name
            $local_img_url  = $upload_dir['url'] . '/' . basename( $local_img_path );

            $gen_unique_fn = true;

            // to fix issue re-create new attachment
            if ( $check_file_existed ) {
                if ( file_exists( $local_img_path ) ) {

                    if ( filesize( $local_img_path ) == filesize( $temporary_file ) ) { // file exited
                        $result = self::get_maybe_existed_attached_id( $local_img_url );
                        if ( is_array($result) ) { // found attachment
                            $attach = current($result);
                            if (is_object($attach)) {
                                if ( file_exists( $temporary_file ) ) {
                                    unlink( $temporary_file );
                                }
                                return array( 'id' => $attach->ID, 'url' => $local_img_url );
                            }
                        }
                    }

                } else { // find in other path
                    $result = self::get_maybe_existed_attached_id( $filename, false );

                    if ( is_array( $result ) ) {  // found attachment
                        $attach = current($result);
                        if (is_object($attach)) {
                            $basedir = $upload_dir['basedir'];
                            $baseurl = $upload_dir['baseurl'];
                            $local_img_path = str_replace( $baseurl, $basedir, $attach->guid );
                            if ( file_exists($local_img_path) && (filesize( $local_img_path ) == filesize( $temporary_file )) ) { // file exited

                                if ( file_exists( $temporary_file ) ) {
                                    unlink( $temporary_file );
                                }
                                return array( 'id' => $attach->ID, 'url' => $attach->guid );
                            }
                        }
                    }
                }
            }

            if ( $gen_unique_fn ) {
                $local_img_path = dirname( $local_img_path ) . '/' . wp_unique_filename( dirname( $local_img_path ), basename( $local_img_path ) );
                $local_img_url  = $upload_dir['url'] . '/' . basename( $local_img_path );
            }

			$moved          = @rename( $temporary_file, $local_img_path );

			if ( $moved ) {
				$wp_filetype = wp_check_filetype( basename( $img_url ), null ); //Get the filetype to set the mimetype
				$attachment  = array(
					'post_mime_type' => $wp_filetype['type'],
					'post_title'     => isset( $img_data['title'] ) && !empty( $img_data['title'] ) ? $img_data['title'] : preg_replace( '/\.[^.]+$/', '', basename( $img_url ) ),
					'post_content'   => isset( $img_data['description'] ) && !empty( $img_data['description'] ) ? $img_data['description'] : '',
					'post_excerpt' => isset( $img_data['caption'] ) && !empty( $img_data['caption'] ) ? $img_data['caption'] : '',
					'post_status'    => 'inherit',
                    'guid' => $local_img_url // to fix
				);

                // for post attachments, thumbnail
                if ( $parent_id ) {
                    $attachment['post_parent'] = $parent_id;
                }

				$attach_id   = wp_insert_attachment( $attachment, $local_img_path ); //Insert the image in the database
				require_once( ABSPATH . 'wp-admin/includes/image.php' );
				$attach_data = wp_generate_attachment_metadata( $attach_id, $local_img_path );
				wp_update_attachment_metadata( $attach_id, $attach_data ); //Update generated metadata
				if ( isset( $img_data['alt'] ) && !empty( $img_data['alt'] ) )
					update_post_meta( $attach_id, '_wp_attachment_image_alt', $img_data['alt'] );
				return array( 'id' => $attach_id, 'url' => $local_img_url );
			}
		}
		if ( file_exists( $temporary_file ) ) {
			unlink( $temporary_file );
		}

		return null;
	}

	static function get_maybe_existed_attached_id( $filename, $full_guid = true ) {
        global $wpdb;
        if ( $full_guid ) {
            $sql = $wpdb->prepare(
                "SELECT ID,guid FROM $wpdb->posts WHERE post_type = 'attachment' AND guid = %s",
                $filename
            );
        } else {
            $sql = "SELECT ID,guid FROM $wpdb->posts WHERE post_type = 'attachment' AND guid LIKE '%/" . $filename . "'";
        }
        return $wpdb->get_results( $sql );
	}

	static function uploadFile( $file_url, $path, $file_name ) {
        // to fix uploader extension rename htaccess file issue
        if ( $file_name != '.htaccess' && $file_name != '.htpasswd' ) {
            $file_name      = sanitize_file_name( $file_name );
        }

		$full_file_name = $path . DIRECTORY_SEPARATOR . $file_name; //Local name

		$response = wp_remote_get( $file_url, array(
			'timeout'  => 10 * 60 * 60,
			'stream'   => true,
			'filename' => $full_file_name,
		) );

		if ( is_wp_error( $response ) ) {
			@unlink( $full_file_name );
			throw new Exception( 'Error: ' . $response->get_error_message() );
		}

		if ( 200 !== (int) wp_remote_retrieve_response_code( $response ) ) {
			@unlink( $full_file_name );
			throw new Exception( 'Error 404: ' . trim( wp_remote_retrieve_response_message( $response ) ) );
		}
		if ( '.phpfile.txt' === substr( $file_name, - 12 ) ) {
			$new_file_name = substr( $file_name, 0, - 12 ) . '.php';
			$new_file_name = $path . DIRECTORY_SEPARATOR . $new_file_name;
			$moved         = @rename( $full_file_name, $new_file_name );
			if ( $moved ) {
				return array( 'path' => $new_file_name );
			} else {
				@unlink( $full_file_name );
				throw new Exception( 'Error: Copy file.' );
			}
		}

		return array( 'path' => $full_file_name );
	}

	static function createPost( $new_post, $post_custom, $post_category, $post_featured_image, $upload_dir, $post_tags, $others = array() ) {
		global $current_user;

        /**
        * Hook: `mainwp_before_post_update`
        *
        * Runs before creating or updating a post via MainWP dashboard.
        *
        * @param array  $new_post      – Post data array.
        * @param array  $post_custom   – Post custom meta data.
        * @param string $post_category – Post categories.
        * @param string $post_tags     – Post tags.
        */

        do_action( 'mainwp_before_post_update', $new_post, $post_custom, $post_category, $post_tags );

		// Options fields.
		$wprocket_fields = array(
			'lazyload',
			'lazyload_iframes',
			'minify_html',
			'minify_css',
			'minify_js',
			'cdn',
			'async_css',
			'defer_all_js',
		);

		$wprocket_activated = false;
		if ( MainWP_Child_WP_Rocket::isActivated() ) {
			if ( function_exists( 'get_rocket_option' ) ) {
				$wprocket_activated = true;
				foreach ( $wprocket_fields as $field ) {
					if ( ! isset( $post_custom[ '_rocket_exclude_' . $field ] ) ) {  // check not exclude only
						if ( ! get_rocket_option( $field ) ) {
							$post_custom[ '_rocket_exclude_' . $field ] = array( true ); // set as excluded
						}
					}
				}
			}
		}

		if ( ! $wprocket_activated ) {
			foreach ( $wprocket_fields as $field ) {
				if ( isset( $post_custom[ '_rocket_exclude_' . $field ] ) ) {
					unset( $post_custom[ '_rocket_exclude_' . $field ] );
				}
			}
		}

        // current user may be connected admin or alternative admin
        $current_uid = $current_user->ID;
		//Set up a new post (adding addition information)
		//$usr = get_user_by( 'login', $_POST['user'] );
		//$new_post['post_author'] = $current_user->ID;

		$is_robot_post = false; // retirement soon
		if ( isset( $_POST['isMainWPRobot'] ) && ! empty( $_POST['isMainWPRobot'] ) ) {
			$is_robot_post = true;
		}

		$post_author = isset( $new_post['post_author'] ) ? $new_post['post_author'] : $current_uid;
		if ( $is_robot_post ) { // retirement soon
			if ( 1 === $post_author ) {
				$new_post['post_author'] = $current_uid;
			} else if ( ! is_numeric( $post_author ) ) {
				$user_author = get_user_by( 'login', $post_author );
				if ( $user_author ) {
					$post_author = $user_author->ID;
				} else {
					$random_password = wp_generate_password( $length = 12, $include_standard_special_chars = false );
					$post_author     = wp_create_user( $post_author, $random_password, $post_author . '@asdf.com' );
				}
			}
		} else if ( isset( $new_post['custom_post_author'] ) && ! empty( $new_post['custom_post_author'] ) ) {
			$_author = get_user_by( 'login', $new_post['custom_post_author'] );
			if ( ! empty( $_author ) ) {
				$new_post['post_author'] = $_author->ID;
			} else {
				$new_post['post_author'] = $current_uid;
			}
			unset( $new_post['custom_post_author'] );
		}

		$post_author             = ! empty( $post_author ) ? $post_author : $current_uid;
		$new_post['post_author'] = $post_author;

		$is_ezine_post = ! empty( $post_custom['_ezine_post_article_source'] ) ? true : false;
		$terms         = isset( $new_post['_ezin_post_category'] ) ? $new_post['_ezin_post_category'] : false ;
		unset( $new_post['_ezin_post_category'] );
		$is_post_plus = isset( $post_custom['_mainwp_post_plus'] ) ? true : false;

		$wp_error = null;

		if ( $is_ezine_post || $is_post_plus ) {
			if ( isset( $new_post['post_date_gmt'] ) && ! empty( $new_post['post_date_gmt'] ) && $new_post['post_date_gmt'] != '0000-00-00 00:00:00' ) {
				$post_date_timestamp     = strtotime( $new_post['post_date_gmt'] ) + get_option( 'gmt_offset' ) * 60 * 60;
				$new_post['post_date']   = date( 'Y-m-d H:i:s', $post_date_timestamp );
				//$new_post['post_status'] = ( $post_date_timestamp <= current_time( 'timestamp' ) ) ? 'publish' : 'future';
			}
//            else {
//				$new_post['post_status'] = 'publish';
//			}
		}

		$wpr_options = isset( $_POST['wpr_options'] ) ? $_POST['wpr_options'] : array();

		$edit_post_id = 0;

		if ( isset( $post_custom['_mainwp_edit_post_id'] ) && $post_custom['_mainwp_edit_post_id'] ) {
			$edit_post_id = current($post_custom['_mainwp_edit_post_id']);
        } else if (isset( $new_post['ID'] ) && $new_post['ID']) {
            $edit_post_id = $new_post['ID'];
        }


        require_once ABSPATH . 'wp-admin/includes/post.php';
        if ($edit_post_id) {
			if ( $user_id = wp_check_post_lock( $edit_post_id ) ) {
				$user = get_userdata( $user_id );
				$error = sprintf( __( 'This content is currently locked. %s is currently editing.' ), $user->display_name );
				return array( 'error' => $error);
			}
		}

        $check_image_existed = false;
        if ( $edit_post_id )
            $check_image_existed = true; // if editing post then will check if image existed

		//Search for all the images added to the new post
		//some images have a href tag to click to navigate to the image.. we need to replace this too
		$foundMatches = preg_match_all( '/(<a[^>]+href=\"(.*?)\"[^>]*>)?(<img[^>\/]*src=\"((.*?)(png|gif|jpg|jpeg))\")/ix', $new_post['post_content'], $matches, PREG_SET_ORDER );
		if ( ( $foundMatches > 0 || ( $is_robot_post && isset( $wpr_options['wpr_save_images'] ) && 'Yes' === $wpr_options['wpr_save_images'] ) ) && ( ! $is_ezine_post ) ) {
			//We found images, now to download them so we can start balbal
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
					$downloadfile      = MainWP_Helper::uploadImage( $originalImgUrl, array(), $check_image_existed );
					$localUrl          = $downloadfile['url'];
					$linkToReplaceWith = dirname( $localUrl );
					if ( '' !== $hrefLink ) {
						$server     = get_option( 'mainwp_child_server' );
						$serverHost = parse_url( $server, PHP_URL_HOST );
						if ( ! empty( $serverHost ) && strpos( $hrefLink, $serverHost ) !== false ) {
							$serverHref               = 'href="' . $serverHost;
							$replaceServerHref        = 'href="' . parse_url( $localUrl, PHP_URL_SCHEME ) . '://' . parse_url( $localUrl, PHP_URL_HOST );
							$new_post['post_content'] = str_replace( $serverHref, $replaceServerHref, $new_post['post_content'] );
						}
						// To fix bug
//						else if ( strpos( $hrefLink, 'http' ) !== false ) {
//							$lnkToReplace = dirname( $hrefLink );
//							if ( 'http:' !== $lnkToReplace && 'https:' !== $lnkToReplace ) {
//								$new_post['post_content'] = str_replace( $lnkToReplace, $linkToReplaceWith, $new_post['post_content'] );
//							}
//						}
					}
					$lnkToReplace = dirname( $imgUrl );
					if ( 'http:' !== $lnkToReplace && 'https:' !== $lnkToReplace ) {
						$new_post['post_content'] = str_replace( $lnkToReplace, $linkToReplaceWith, $new_post['post_content'] );
					}
				} catch ( Exception $e ) {

				}
			}
		}

		if ( has_shortcode( $new_post['post_content'], 'gallery' ) ) {
			if ( preg_match_all( '/\[gallery[^\]]+ids=\"(.*?)\"[^\]]*\]/ix', $new_post['post_content'], $matches, PREG_SET_ORDER ) ) {
				$replaceAttachedIds = array();
				if ( isset( $_POST['post_gallery_images'] ) ) {
					$post_gallery_images = unserialize(base64_decode( $_POST['post_gallery_images'] ));
					if (is_array($post_gallery_images)) {
						foreach($post_gallery_images as $gallery){
							if (isset($gallery['src'])) {
								try {
									$upload = MainWP_Helper::uploadImage( $gallery['src'], $gallery ); //Upload image to WP
									if ( null !== $upload ) {
										$replaceAttachedIds[$gallery['id']] = $upload['id'];
									}
								} catch ( Exception $e ) {

								}
							}
						}
					}
				}
				if (count($replaceAttachedIds) > 0) {
					foreach ( $matches as $match ) {
						$idsToReplace = $match[1];
						$idsToReplaceWith = "";
						$originalIds = explode(',', $idsToReplace);
						foreach($originalIds as $attached_id) {
							if (!empty($originalIds) && isset($replaceAttachedIds[$attached_id])) {
								$idsToReplaceWith .= $replaceAttachedIds[$attached_id].",";
							}
						}
						$idsToReplaceWith = rtrim($idsToReplaceWith,",");
						if (!empty($idsToReplaceWith)) {
							$new_post['post_content'] = str_replace( '"' . $idsToReplace . '"', '"'.$idsToReplaceWith.'"', $new_post['post_content'] );
						}
					}
				}
			}
		}

		if ( $is_post_plus ) {
			$random_publish_date = isset( $post_custom['_saved_draft_random_publish_date'] ) ? $post_custom['_saved_draft_random_publish_date'] : false;
			$random_publish_date = is_array( $random_publish_date ) ? current( $random_publish_date ) : null;
			if ( ! empty( $random_publish_date ) ) {
				$random_date_from = isset( $post_custom['_saved_draft_publish_date_from'] ) ? $post_custom['_saved_draft_publish_date_from'] : 0;
				$random_date_from = is_array( $random_date_from ) ? current( $random_date_from ) : 0;

				$random_date_to = isset( $post_custom['_saved_draft_publish_date_to'] ) ? $post_custom['_saved_draft_publish_date_to'] : 0;
				$random_date_to = is_array( $random_date_to ) ? current( $random_date_to ) : 0;

				$now = current_time( 'timestamp' );

				if ( empty( $random_date_from ) ) {
					$random_date_from = $now;
				}

				if ( empty( $random_date_to ) ) {
					$random_date_to = $now;
				}

				if ( $random_date_from === $now && $random_date_from === $random_date_to ) {
					$random_date_to = $now + 7 * 24 * 3600;
				}

				if ( $random_date_from > $random_date_to ) {
					$tmp              = $random_date_from;
					$random_date_from = $random_date_to;
					$random_date_to   = $tmp;
				}

				$random_timestamp        = rand( $random_date_from, $random_date_to );
//				$post_status             = ( $random_timestamp <= current_time( 'timestamp' ) ) ? 'publish' : 'future';
//				$new_post['post_status'] = $post_status;
				$new_post['post_date']   = date( 'Y-m-d H:i:s', $random_timestamp );
			}
		}

		if ( isset( $post_tags ) && '' !== $post_tags ) {
			$new_post['tags_input'] = $post_tags;
		}

		//Save the post to the wp
		remove_filter( 'content_save_pre', 'wp_filter_post_kses' );  // to fix brake scripts or html
		$post_status             = $new_post['post_status'];
		$new_post['post_status'] = 'auto-draft';

		// update post
		if ( $edit_post_id ) {
			// check if post existed
			$current_post = get_post($edit_post_id);
			if ( $current_post && ( ( !isset( $new_post['post_type'] ) && $current_post->post_type == 'post' ) || ( isset( $new_post['post_type'] ) && $new_post['post_type'] == $current_post->post_type ) ) ) {
				$new_post['ID'] = $edit_post_id;
			}
		}

		$new_post_id             = wp_insert_post( $new_post, $wp_error );

		//Show errors if something went wrong
		if ( is_wp_error( $wp_error ) ) {
			return $wp_error->get_error_message();
		}
		if ( empty( $new_post_id ) ) {
			return array( 'error' => 'Empty post id');
		}

		wp_update_post( array( 'ID' => $new_post_id, 'post_status' => $post_status ) );

		if ( ! empty( $terms ) ) {
			wp_set_object_terms( $new_post_id, array_map( intval, $terms ), 'category' );
		}

		$permalink = get_permalink( $new_post_id );

		$seo_ext_activated = false;
		if ( class_exists( 'WPSEO_Meta' ) && class_exists( 'WPSEO_Admin' ) ) {
			$seo_ext_activated = true;
		}

		//Set custom fields
		$not_allowed   = array(
			'_slug',
			'_tags',
			'_edit_lock',
			'_selected_sites',
			'_selected_groups',
			'_selected_by',
			'_categories',
			'_edit_last',
			'_sticky',
		);
		$not_allowed[] = '_mainwp_boilerplate_sites_posts';
		$not_allowed[] = '_ezine_post_keyword';
		$not_allowed[] = '_ezine_post_display_sig';
		$not_allowed[] = '_ezine_post_remove_link';
		$not_allowed[] = '_ezine_post_grab_image';
		$not_allowed[] = '_ezine_post_grab_image_placement';
		$not_allowed[] = '_ezine_post_template_id';

		$not_allowed[] = '_mainwp_post_plus';
		$not_allowed[] = '_saved_as_draft';
		$not_allowed[] = '_saved_draft_categories';
		$not_allowed[] = '_saved_draft_tags';
		$not_allowed[] = '_saved_draft_random_privelege';
		$not_allowed[] = '_saved_draft_random_category';
		$not_allowed[] = '_saved_draft_random_publish_date';
		$not_allowed[] = '_saved_draft_publish_date_from';
		$not_allowed[] = '_saved_draft_publish_date_to';
		$not_allowed[] = '_post_to_only_existing_categories';
		$not_allowed[] = '_mainwp_robot_post_comments';
		$not_allowed[] = '_mainwp_edit_post_site_id';
		$not_allowed[] = '_mainwp_edit_post_id';
		$not_allowed[] = '_edit_post_status';

		$post_to_only_existing_categories = false;

        if (is_array($post_custom)) {
            foreach ( $post_custom as $meta_key => $meta_values ) {
			if ( ! in_array( $meta_key, $not_allowed ) ) {
				foreach ( $meta_values as $meta_value ) {
					if (strpos($meta_key, "_mainwp_spinner_") === 0)
						continue; // not save

					if ( ! $seo_ext_activated ) {
						// if Wordpress SEO plugin is not activated do not save yoast post meta
						if ( strpos( $meta_key, '_yoast_wpseo_' ) === false ) {
							update_post_meta( $new_post_id, $meta_key, $meta_value );
						}
					} else {
						update_post_meta( $new_post_id, $meta_key, $meta_value );
					}
				}
			} else if ( '_sticky' === $meta_key ) {
				foreach ( $meta_values as $meta_value ) {
					if ( 'sticky' === base64_decode( $meta_value ) ) {
						stick_post( $new_post_id );
					}
				}
			} else if ( '_post_to_only_existing_categories' === $meta_key ) {
				if ( isset( $meta_values[0] ) && $meta_values[0] ) {
					$post_to_only_existing_categories = true;
				}
			}
		}
        }

		// yoast seo extension
		if ( $seo_ext_activated ) {
			$_seo_opengraph_image = isset( $post_custom[ WPSEO_Meta::$meta_prefix . 'opengraph-image' ] ) ? $post_custom[ WPSEO_Meta::$meta_prefix . 'opengraph-image' ] : array();
			$_seo_opengraph_image = current( $_seo_opengraph_image );
			$_server_domain       = '';
			$_server              = get_option( 'mainwp_child_server' );
			if ( preg_match( '/(https?:\/\/[^\/]+\/).+/', $_server, $matchs ) ) {
				$_server_domain = isset( $matchs[1] ) ? $matchs[1] : '';
			}

			// upload image if it on the server
			if ( ! empty( $_seo_opengraph_image ) && strpos( $_seo_opengraph_image, $_server_domain ) !== false ) {
				try {
					$upload = MainWP_Helper::uploadImage( $_seo_opengraph_image ); //Upload image to WP
					if ( null !== $upload ) {
						update_post_meta( $new_post_id, WPSEO_Meta::$meta_prefix . 'opengraph-image', $upload['url'] ); //Add the image to the post!
					}
				} catch ( Exception $e ) {

				}

			}
		}

		//If categories exist, create them (second parameter of wp_create_categories adds the categories to the post)
		include_once( ABSPATH . 'wp-admin/includes/taxonomy.php' ); //Contains wp_create_categories
		if ( isset( $post_category ) && '' !== $post_category ) {
			$categories = explode( ',', $post_category );
			if ( count( $categories ) > 0 ) {
				if ( ! $post_to_only_existing_categories ) {
					$post_category = wp_create_categories( $categories, $new_post_id );
				} else {
					$cat_ids = array();
					foreach ( $categories as $cat ) {
						if ( $id = category_exists( $cat ) ) {
							$cat_ids[] = $id;
						}
					}
					if ( count( $cat_ids ) > 0 ) {
						wp_set_post_categories( $new_post_id, $cat_ids );
					}
				}
			}
		}

		$featured_image_exist = false;
		//If featured image exists - set it
		if ( null !== $post_featured_image ) {
			try {
				$upload = MainWP_Helper::uploadImage( $post_featured_image, array(), $check_image_existed, $new_post_id); //Upload image to WP
				if ( null !== $upload ) {
					update_post_meta( $new_post_id, '_thumbnail_id', $upload['id'] ); //Add the thumbnail to the post!
					$featured_image_exist = true;
                    if (isset($others['featured_image_data'])) {
                        $_image_data = $others['featured_image_data'];
                        update_post_meta( $upload['id'], '_wp_attachment_image_alt', $_image_data['alt'] );
                        wp_update_post( array( 'ID' => $upload['id'],
                                            'post_excerpt' => $_image_data['caption'],
                                            'post_content' => $_image_data['description'],
                                            'post_title' => $_image_data['title']
                                        )
                                    );
                    }
				}
			} catch ( Exception $e ) {

			}
		}

		if ( !$featured_image_exist ) {
			delete_post_meta( $new_post_id, '_thumbnail_id' );
		}

		// post plus extension process
		if ( $is_post_plus ) {
			$random_privelege      = isset( $post_custom['_saved_draft_random_privelege'] ) ? $post_custom['_saved_draft_random_privelege'] : null;
			$random_privelege      = is_array( $random_privelege ) ? current( $random_privelege ) : null;
			$random_privelege_base = base64_decode( $random_privelege );
			$random_privelege      = maybe_unserialize( $random_privelege_base );

			if ( is_array( $random_privelege ) && count( $random_privelege ) > 0 ) {
				$random_post_authors = array();
				foreach ( $random_privelege as $role ) {
					$users = get_users( array( 'role' => $role ) );
					foreach ( $users as $user ) {
						$random_post_authors[] = $user->ID;
					}
				}
				if ( count( $random_post_authors ) > 0 ) {
					shuffle( $random_post_authors );
					$key = array_rand( $random_post_authors );
					wp_update_post( array( 'ID' => $new_post_id, 'post_author' => $random_post_authors[ $key ] ) );
				}
			}

			$random_category = isset( $post_custom['_saved_draft_random_category'] ) ? $post_custom['_saved_draft_random_category'] : false;
			$random_category = is_array( $random_category ) ? current( $random_category ) : null;
			if ( ! empty( $random_category ) ) {
				$cats        = get_categories( array( 'type' => 'post', 'hide_empty' => 0 ) );
				$random_cats = array();
				if ( is_array( $cats ) ) {
					foreach ( $cats as $cat ) {
						$random_cats[] = $cat->term_id;
					}
				}
				if ( count( $random_cats ) > 0 ) {
					shuffle( $random_cats );
					$key = array_rand( $random_cats );
					wp_set_post_categories( $new_post_id, array( $random_cats[ $key ] ), false );
				}
			}
		}
		// end of post plus

		// to support custom post author
		$custom_post_author = apply_filters('mainwp_create_post_custom_author', false, $new_post_id);
		if ( !empty( $custom_post_author ) ) {
			wp_update_post( array( 'ID' => $new_post_id, 'post_author' => $custom_post_author ) );
		}

		// MainWP Robot
		if ( $is_robot_post ) {
			$all_comments = $post_custom['_mainwp_robot_post_comments'];
			MainWP_Child_Robot::Instance()->wpr_insertcomments( $new_post_id, $all_comments );
		}

        // unlock if edit post
        if ($edit_post_id) {
            update_post_meta( $edit_post_id, '_edit_lock', '' );
        }

		$ret['success']  = true;
		$ret['link']     = $permalink;
		$ret['added_id'] = $new_post_id;

		return $ret;
	}

	static function getMainWPDir( $what = null, $dieOnError = true ) {
		$upload_dir = wp_upload_dir();
		$dir        = $upload_dir['basedir'] . DIRECTORY_SEPARATOR . 'mainwp' . DIRECTORY_SEPARATOR;
		self::checkDir( $dir, $dieOnError );
		if ( ! file_exists( $dir . 'index.php' ) ) {
			@touch( $dir . 'index.php' );
		}
		$url = $upload_dir['baseurl'] . '/mainwp/';

		if ( 'backup' === $what ) {
			$dir .= 'backup' . DIRECTORY_SEPARATOR;
			self::checkDir( $dir, $dieOnError );
			if ( ! file_exists( $dir . 'index.php' ) ) {
				@touch( $dir . 'index.php' );
			}

			$another_name = '.htaccess';
			if ( ! file_exists( $dir . $another_name ) ) {
				$file = @fopen( $dir . $another_name, 'w+' );
				@fwrite( $file, 'deny from all' );
				@fclose( $file );
			}
			$url .= 'backup/';
		}

		return array( $dir, $url );
	}

	static function checkDir( $dir, $dieOnError, $chmod = 0755 ) {
		MainWP_Helper::getWPFilesystem();
		global $wp_filesystem;
		if ( ! file_exists( $dir ) ) {
			if ( empty( $wp_filesystem ) ) {
				@mkdir( $dir, $chmod, true );
			} else {
				if ( ( 'ftpext' === $wp_filesystem->method ) && defined( 'FTP_BASE' ) ) {
					$ftpBase = FTP_BASE;
					$ftpBase = trailingslashit( $ftpBase );
					$tmpdir  = str_replace( ABSPATH, $ftpBase, $dir );
				} else {
					$tmpdir = $dir;
				}
				$wp_filesystem->mkdir( $tmpdir, $chmod );
			}

			if ( ! file_exists( $dir ) ) {
				$error = __( 'Unable to create directory ', 'mainwp-child' ) . str_replace( ABSPATH, '', $dir ) . '.' . __( ' Is its parent directory writable by the server?', 'mainwp-child' );
				if ( $dieOnError ) {
					self::error( $error );
				} else {
					throw new Exception( $error );
				}
			}
		}
	}

	public static function validateMainWPDir() {
		$done = false;
		$dir  = MainWP_Helper::getMainWPDir();
		$dir  = $dir[0];
		if ( MainWP_Helper::getWPFilesystem() ) {
			global $wp_filesystem;
			try {
				MainWP_Helper::checkDir( $dir, false );
			} catch ( Exception $e ) {

			}
			if ( ! empty( $wp_filesystem ) ) {
				if ( $wp_filesystem->is_writable( $dir ) ) {
					$done = true;
				}
			}
		}

		if ( ! $done ) {
			if ( ! file_exists( $dir ) ) {
				@mkdirs( $dir );
			}
			if ( is_writable( $dir ) ) {
				$done = true;
			}
		}

		return $done;
	}

	static function search( $array, $key ) {
		if ( is_object( $array ) ) {
			$array = (array) $array;
		}
		if ( is_array( $array ) || is_object( $array ) ) {
			if ( isset( $array[ $key ] ) ) {
				return $array[ $key ];
			}

			foreach ( $array as $subarray ) {
				$result = self::search( $subarray, $key );
				if ( null !== $result ) {
					return $result;
				}
			}
		}

		return null;
	}

	/**
	 * @return WP_Filesystem_Base
	 */
	public static function getWPFilesystem() {
		global $wp_filesystem;

		if ( empty( $wp_filesystem ) ) {
			ob_start();
			if ( file_exists( ABSPATH . '/wp-admin/includes/deprecated.php' ) ) {
				include_once( ABSPATH . '/wp-admin/includes/deprecated.php' );
			}
			if ( file_exists( ABSPATH . '/wp-admin/includes/screen.php' ) ) {
				include_once( ABSPATH . '/wp-admin/includes/screen.php' );
			}
			if ( file_exists( ABSPATH . '/wp-admin/includes/template.php' ) ) {
				include_once( ABSPATH . '/wp-admin/includes/template.php' );
			}
			$creds = request_filesystem_credentials( 'test' );
			ob_end_clean();
			if ( empty( $creds ) ) {
				if ( ! defined( 'MAINWP_SAVE_FS_METHOD' ) ) {
					define( 'MAINWP_SAVE_FS_METHOD', get_filesystem_method() );
				}
				define( 'FS_METHOD', 'direct' );
			}
			$init = WP_Filesystem( $creds );
		} else {
			$init = true;
		}

		return $init;
	}

	public static function startsWith( $haystack, $needle ) {
		return ! strncmp( $haystack, $needle, strlen( $needle ) );
	}

	public static function endsWith( $haystack, $needle ) {
		$length = strlen( $needle );
		if ( 0 == $length ) {
			return true;
		}

		return ( substr( $haystack, - $length ) == $needle );
	}

	public static function getNiceURL( $pUrl, $showHttp = false ) {
		$url = $pUrl;

		if ( self::startsWith( $url, 'http://' ) ) {
			if ( ! $showHttp ) {
				$url = substr( $url, 7 );
			}
		} else if ( self::startsWith( $pUrl, 'https://' ) ) {
			if ( ! $showHttp ) {
				$url = substr( $url, 8 );
			}
		} else {
			if ( $showHttp ) {
				$url = 'http://' . $url;
			}
		}

		if ( self::endsWith( $url, '/' ) ) {
			if ( ! $showHttp ) {
				$url = substr( $url, 0, strlen( $url ) - 1 );
			}
		} else {
			$url = $url . '/';
		}

		return $url;
	}

	public static function clean( $string ) {
		$string = trim( $string );
		$string = htmlentities( $string, ENT_QUOTES );
		$string = str_replace( "\n", '<br>', $string );
		if ( get_magic_quotes_gpc() ) {
			$string = stripslashes( $string );
		}

		return $string;
	}

	public static function endSession() {
		@session_write_close();
		@ob_end_flush();
	}

	static function fetchUrl( $url, $postdata ) {
		try {
			$tmpUrl = $url;
			if ( '/' !== substr( $tmpUrl, - 1 ) ) {
				$tmpUrl .= '/';
			}

			return self::_fetchUrl( $tmpUrl . 'wp-admin/', $postdata );
		} catch ( Exception $e ) {
			try {
				return self::_fetchUrl( $url, $postdata );
			} catch ( Exception $ex ) {
				throw $e;
			}
		}
	}

	public static function _fetchUrl( $url, $postdata ) {
		//$agent = 'Mozilla/4.0 (compatible; MSIE 5.01; Windows NT 5.0)';
		$agent = 'Mozilla/5.0 (compatible; MainWP-Child/' . MainWP_Child::$version . '; +http://mainwp.com)';

		$ch = curl_init();
		curl_setopt( $ch, CURLOPT_URL, $url );
		curl_setopt( $ch, CURLOPT_RETURNTRANSFER, true );
		curl_setopt( $ch, CURLOPT_POST, true );
		curl_setopt( $ch, CURLOPT_POSTFIELDS, $postdata );
		curl_setopt( $ch, CURLOPT_CONNECTTIMEOUT, 10 );
		curl_setopt( $ch, CURLOPT_USERAGENT, $agent );
		$data        = curl_exec( $ch );
		$http_status = curl_getinfo( $ch, CURLINFO_HTTP_CODE );
		$err         = curl_error( $ch );
		curl_close( $ch );

		if ( ( false === $data ) && ( 0 === $http_status ) ) {
			throw new Exception( 'Http Error: ' . $err );
		} else if ( preg_match( '/<mainwp>(.*)<\/mainwp>/', $data, $results ) > 0 ) {
			$result      = $results[1];
			$result_base = base64_decode( $result );
			$information = maybe_unserialize( $result_base );

			return $information;
		} else if ( '' === $data ) {
			throw new Exception( __( 'Something went wrong while contacting the child site. Please check if there is an error on the child site. This error could also be caused by trying to clone or restore a site to large for your server settings.', 'mainwp-child' ) );
		} else {
			throw new Exception( __( 'Child plugin is disabled or the security key is incorrect. Please resync with your main installation.', 'mainwp-child' ) );
		}
	}


	public static function randString( $length, $charset = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789' ) {
		$str   = '';
		$count = strlen( $charset );
		while ( $length -- ) {
			$str .= $charset[ mt_rand( 0, $count - 1 ) ];
		}

		return $str;
	}

	public static function return_bytes( $val ) {
		$val  = trim( $val );
		$last = $val[ strlen( $val ) - 1 ];
		$val = rtrim($val, $last);
		$last = strtolower( $last );
		switch ( $last ) {
			// The 'G' modifier is available since PHP 5.1.0
			case 'g':
				$val *= 1024;
			case 'm':
				$val *= 1024;
			case 'k':
				$val *= 1024;
		}

		return $val;
	}

	public static function human_filesize( $bytes, $decimals = 2 ) {
		$size   = array( 'B', 'kB', 'MB', 'GB', 'TB', 'PB', 'EB', 'ZB', 'YB' );
		$factor = floor( ( strlen( $bytes ) - 1 ) / 3 );

		return sprintf( "%.{$decimals}f", $bytes / pow( 1024, $factor ) ) . @$size[ $factor ];
	}

	public static function is_dir_empty( $dir ) {
		if ( ! is_readable( $dir ) ) {
			return null;
		}

		return ( 2 === count( scandir( $dir ) ) );
	}

	public static function delete_dir( $dir ) {
		$nodes = glob( $dir . '*' );

		if ( is_array( $nodes ) ) {
			foreach ( $nodes as $node ) {
				if ( is_dir( $node ) ) {
					self::delete_dir( $node . DIRECTORY_SEPARATOR );
				} else {
					@unlink( $node );
				}
			}
		}
		@rmdir( $dir );
	}

	public static function function_exists( $func ) {
		if ( ! function_exists( $func ) ) {
			return false;
		}

		if ( extension_loaded( 'suhosin' ) ) {
			$suhosin = @ini_get( 'suhosin.executor.func.blacklist' );
			if ( ! empty( $suhosin ) ) {
				$suhosin = explode( ',', $suhosin );
				$suhosin = array_map( 'trim', $suhosin );
				$suhosin = array_map( 'strtolower', $suhosin );

				return ( function_exists( $func ) && ! array_search( $func, $suhosin ) );
			}
		}

		return true;
	}

	public static function getTimestamp( $timestamp ) {
		$gmtOffset = get_option( 'gmt_offset' );

		return ( $gmtOffset ? ( $gmtOffset * HOUR_IN_SECONDS ) + $timestamp : $timestamp );
	}

	public static function formatDate( $timestamp ) {
		return date_i18n( get_option( 'date_format' ), $timestamp );
	}

	public static function formatTime( $timestamp ) {
		return date_i18n( get_option( 'time_format' ), $timestamp );
	}

	public static function formatTimestamp( $timestamp ) {
		return date_i18n( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $timestamp );
	}

	public static function formatEmail( $to, $body ) {
		return '<br>
<div>
            <br>
            <div style="background:#ffffff;padding:0 1.618em;font:13px/20px Helvetica,Arial,Sans-serif;padding-bottom:50px!important">
                <div style="width:600px;background:#fff;margin-left:auto;margin-right:auto;margin-top:10px;margin-bottom:25px;padding:0!important;border:10px Solid #fff;border-radius:10px;overflow:hidden">
                    <div style="display: block; width: 100% ; background-image: url(https://mainwp.com/wp-content/uploads/2013/02/debut_light.png) ; background-repeat: repeat; border-bottom: 2px Solid #7fb100 ; overflow: hidden;">
                      <div style="display: block; width: 95% ; margin-left: auto ; margin-right: auto ; padding: .5em 0 ;">
                         <div style="float: left;"><a href="https://mainwp.com"><img src="https://mainwp.com/wp-content/uploads/2013/07/MainWP-Logo-1000-300x62.png" alt="MainWP" height="30"/></a></div>
                         <div style="float: right; margin-top: .6em ;">
                            <span style="display: inline-block; margin-right: .8em;"><a href="https://mainwp.com/mainwp-extensions/" style="font-family: Helvetica, Sans; color: #7fb100; text-transform: uppercase; font-size: 14px;">Extensions</a></span>
                            <span style="display: inline-block; margin-right: .8em;"><a style="font-family: Helvetica, Sans; color: #7fb100; text-transform: uppercase; font-size: 14px;" href="https://mainwp.com/forum">Support</a></span>
                            <span style="display: inline-block; margin-right: .8em;"><a style="font-family: Helvetica, Sans; color: #7fb100; text-transform: uppercase; font-size: 14px;" href="https://docs.mainwp.com">Documentation</a></span>
                            <span style="display: inline-block; margin-right: .5em;" class="mainwp-memebers-area"><a href="https://mainwp.com/member/login/index" style="padding: .6em .5em ; border-radius: 50px ; -moz-border-radius: 50px ; -webkit-border-radius: 50px ; background: #1c1d1b; border: 1px Solid #000; color: #fff !important; font-size: .9em !important; font-weight: normal ; -webkit-box-shadow:  0px 0px 0px 5px rgba(0, 0, 0, .1); box-shadow:  0px 0px 0px 5px rgba(0, 0, 0, .1);">Members Area</a></span>
                         </div><div style="clear: both;"></div>
                      </div>
                    </div>
                    <div>
                        <p>Hello MainWP User!<br></p>
                        ' . $body . '
                        <div></div>
                        <br />
                        <div>MainWP</div>
                        <div><a href="https://www.MainWP.com" target="_blank">www.MainWP.com</a></div>
                        <p></p>
                    </div>

                    <div style="display: block; width: 100% ; background: #1c1d1b;">
                      <div style="display: block; width: 95% ; margin-left: auto ; margin-right: auto ; padding: .5em 0 ;">
                        <div style="padding: .5em 0 ; float: left;"><p style="color: #fff; font-family: Helvetica, Sans; font-size: 12px ;">© 2013 MainWP. All Rights Reserved.</p></div>
                        <div style="float: right;"><a href="https://mainwp.com"><img src="https://mainwp.com/wp-content/uploads/2013/07/MainWP-Icon-300.png" height="45"/></a></div><div style="clear: both;"></div>
                      </div>
                   </div>
                </div>
                <center>
                    <br><br><br><br><br><br>
                    <table border="0" cellpadding="0" cellspacing="0" width="100%" style="background-color:#ffffff;border-top:1px solid #e5e5e5">
                        <tbody><tr>
                            <td align="center" valign="top" style="padding-top:20px;padding-bottom:20px">
                                <table border="0" cellpadding="0" cellspacing="0">
                                    <tbody><tr>
                                        <td align="center" valign="top" style="color:#606060;font-family:Helvetica,Arial,sans-serif;font-size:11px;line-height:150%;padding-right:20px;padding-bottom:5px;padding-left:20px;text-align:center">
                                            This email is sent from your MainWP Dashboard.
                                            <br>
                                            If you do not wish to receive these notices please re-check your preferences in the MainWP Settings page.
                                            <br>
                                            <br>
                                        </td>
                                    </tr>
                                </tbody></table>
                            </td>
                        </tr>
                    </tbody></table>

                </center>
            </div>
</div>
<br>';
	}

	static function update_option( $option_name, $option_value, $autoload = 'no' ) {
		$success = add_option( $option_name, $option_value, '', $autoload );

		if ( ! $success ) {
			$success = update_option( $option_name, $option_value );
		}

		return $success;
	}

	static function fix_option( $option_name, $autoload = 'no' ) {
		global $wpdb;

		if ( $autoload != $wpdb->get_var( $wpdb->prepare( "SELECT autoload FROM $wpdb->options WHERE option_name = %s", $option_name ) ) ) {
			$option_value = get_option( $option_name );
			delete_option( $option_name );
			add_option( $option_name, $option_value, null, $autoload );
		}
	}

	static function update_lasttime_backup( $by, $time ) {
		$backup_by = array('backupbuddy', 'backupwordpress', 'backwpup', 'updraftplus', 'wptimecapsule');

		if (!in_array($by, $backup_by))
			return false;

		$lasttime = get_option('mainwp_lasttime_backup_' . $by);
		if ( $time > $lasttime ) {
			update_option('mainwp_lasttime_backup_' . $by, $time);
		}

		return true;
	}

	static function get_lasttime_backup( $by ) {
		if ($by == 'backupwp') // to compatible
			$by = 'backupwordpress';
		switch($by) {
			case 'backupbuddy':
				if ( !is_plugin_active( 'backupbuddy/backupbuddy.php' ) && !is_plugin_active( 'Backupbuddy/backupbuddy.php' )) {
					return 0;
				}
				break;
			case 'backupwordpress':
				if ( !is_plugin_active( 'backupwordpress/backupwordpress.php' )) {
					return 0;
				}
				break;
			case 'backwpup':
				if ( !is_plugin_active( 'backwpup/backwpup.php' ) && !is_plugin_active( 'backwpup-pro/backwpup.php' ) ) {
					return 0;
				}
				break;
			case 'updraftplus':
				if ( !is_plugin_active( 'updraftplus/updraftplus.php' )) {
					return 0;
				}
				break;
			case 'wptimecapsule':
				if ( !is_plugin_active( 'wp-time-capsule/wp-time-capsule.php'  )) {
					return 0;
				}
				break;
			default:
				return 0;
				break;
		}
		return get_option('mainwp_lasttime_backup_' . $by, 0);
	}


	static function containsAll( $haystack, $needle ) {
		if ( ! is_array( $haystack ) || ! is_array( $needle ) ) {
			return false;
		}

		foreach ( $needle as $item ) {
			if ( ! in_array( $item, $haystack ) ) {
				return false;
			}
		}

		return true;
	}

	public static function getRevisions( $max_revisions ) {
		global $wpdb;
		$sql = " SELECT	`post_parent`, COUNT(*) cnt
                FROM $wpdb->posts
                WHERE `post_type` = 'revision'
                GROUP BY `post_parent`
                HAVING COUNT(*) > " . $max_revisions;

		return $wpdb->get_results( $sql );
	}

	public static function deleteRevisions( $results, $max_revisions ) {
		global $wpdb;

		if ( ! is_array( $results ) || 0 === count( $results ) ) {
			return;
		}
		$count_deleted = 0;
		$results_length = count( $results );
		for ( $i = 0; $i < $results_length; $i ++ ) {
			$number_to_delete = $results[ $i ]->cnt - $max_revisions;
			$count_deleted += $number_to_delete;
			$sql_get       = "
                    SELECT `ID`, `post_modified`
                    FROM  $wpdb->posts
                    WHERE `post_parent`=" . $results[ $i ]->post_parent . "
                    AND `post_type`='revision'
                    ORDER BY `post_modified` ASC
                ";
			$results_posts = $wpdb->get_results( $sql_get );

			$delete_ids = array();
			if ( is_array( $results_posts ) && count( $results_posts ) > 0 ) {
				for ( $j = 0; $j < $number_to_delete; $j ++ ) {
					$delete_ids[] = $results_posts[ $j ]->ID;
				}
			}

			if ( count( $delete_ids ) > 0 ) {
				$sql_delete = " DELETE FROM $wpdb->posts
                                WHERE `ID` IN (" . implode( ',', $delete_ids ) . ')
                            ';
				$wpdb->get_results( $sql_delete );
			}
		}

		return $count_deleted;
	}

	public static function inExcludes( $excludes, $value ) {
		if ( empty( $value ) ) {
			return false;
		}

		if ( null != $excludes ) {
			foreach ( $excludes as $exclude ) {
				if ( MainWP_Helper::endsWith( $exclude, '*' ) ) {
					if ( MainWP_Helper::startsWith( $value, substr( $exclude, 0, strlen( $exclude ) - 1 ) ) ) {
						return true;
					}
				} else if ( $value == $exclude ) {
					return true;
				} else if ( MainWP_Helper::startsWith( $value, $exclude . '/' ) ) {
					return true;
				}
			}
		}

		return false;
	}

	public static function isArchive( $pFileName, $pPrefix = '', $pSuffix = '' ) {
		return preg_match( '/' . $pPrefix . '(.*).(zip|tar|tar.gz|tar.bz2)' . $pSuffix . '$/', $pFileName );
	}

	public static function parse_query( $var ) {

		$var = parse_url( $var, PHP_URL_QUERY );
		$var = html_entity_decode( $var );
		$var = explode( '&', $var );
		$arr = array();

		foreach ( $var as $val ) {
			$x            = explode( '=', $val );
			$arr[ $x[0] ] = $x[1];
		}
		unset( $val, $x, $var );

		return $arr;
	}

	/**
	 * Allow to remove method for an hook when, it's a class method used and class don't have variable, but you know the class name :)
	 * Credit to the : wp-filters-extras
	 */

	public static function remove_filters_for_anonymous_class( $hook_name = '', $class_name = '', $method_name = '', $priority = 0 ) {
		global $wp_filter;

		// Take only filters on right hook name and priority
		if ( ! isset( $wp_filter[ $hook_name ] ) || ! isset( $wp_filter[ $hook_name ][ $priority ] ) || ! is_array( $wp_filter[ $hook_name ][ $priority ] ) ) {
			return false;
		}

		// Loop on filters registered
		foreach ( (array) $wp_filter[ $hook_name ][ $priority ] as $unique_id => $filter_array ) {
			// Test if filter is an array ! (always for class/method)
			if ( isset( $filter_array['function'] ) && is_array( $filter_array['function'] ) ) {
				// Test if object is a class, class and method is equal to param !
				if ( is_object( $filter_array['function'][0] ) && get_class( $filter_array['function'][0] ) && get_class( $filter_array['function'][0] ) === $class_name && $filter_array['function'][1] === $method_name ) {
					unset( $wp_filter[ $hook_name ][ $priority ][ $unique_id ] );
				}
			}
		}

		return false;
	}

	/**
	 * Credit to the : wp-filters-extras
	 */

static function remove_filters_with_method_name( $hook_name = '', $method_name = '', $priority = 0 ) {

    global $wp_filter;
	// Take only filters on right hook name and priority
	if ( ! isset( $wp_filter[ $hook_name ][ $priority ] ) || ! is_array( $wp_filter[ $hook_name ][ $priority ] ) ) {
		return false;
	}
	// Loop on filters registered
	foreach ( (array) $wp_filter[ $hook_name ][ $priority ] as $unique_id => $filter_array ) {
		// Test if filter is an array ! (always for class/method)
		if ( isset( $filter_array['function'] ) && is_array( $filter_array['function'] ) ) {
			// Test if object is a class and method is equal to param !
			if ( is_object( $filter_array['function'][0] ) && get_class( $filter_array['function'][0] ) && $filter_array['function'][1] == $method_name ) {
				// Test for WordPress >= 4.7 WP_Hook class
				if ( is_a( $wp_filter[ $hook_name ], 'WP_Hook' ) ) {
					unset( $wp_filter[ $hook_name ]->callbacks[ $priority ][ $unique_id ] );
				} else {
					unset( $wp_filter[ $hook_name ][ $priority ][ $unique_id ] );
				}
			}
		}
	}
	return false;
}

	public static function sanitize_filename( $filename ) {
		if (!function_exists('mb_ereg_replace')) return sanitize_file_name($filename);

		// Remove anything which isn't a word, whitespace, number
		// or any of the following caracters -_~,;:[]().
		// If you don't need to handle multi-byte characters
		// you can use preg_replace rather than mb_ereg_replace
		// Thanks @Łukasz Rysiak!
		$filename = mb_ereg_replace( "([^\w\s\d\-_~,;:\[\]\(\).])", '', $filename );
		// Remove any runs of periods (thanks falstro!)
		$filename = mb_ereg_replace( "([\.]{2,})", '', $filename );

		return $filename;
	}

	static function ctype_digit( $str ) {
		return ( is_string( $str ) || is_int( $str ) || is_float( $str ) ) && preg_match( '/^\d+\z/', $str );
	}

	public static function create_nonce_without_session( $action = - 1 ) {
		$user = wp_get_current_user();
		$uid  = (int) $user->ID;
		if ( ! $uid ) {
			$uid = apply_filters( 'nonce_user_logged_out', $uid, $action );
		}

		$i = wp_nonce_tick();

		return substr( wp_hash( $i . '|' . $action . '|' . $uid, 'nonce' ), - 12, 10 );
	}

	public static function verify_nonce_without_session( $nonce, $action = - 1 ) {
		$nonce = (string) $nonce;
		$user  = wp_get_current_user();
		$uid   = (int) $user->ID;
		if ( ! $uid ) {
			$uid = apply_filters( 'nonce_user_logged_out', $uid, $action );
		}

		if ( empty( $nonce ) ) {
			return false;
		}

		$i = wp_nonce_tick();

		$expected = substr( wp_hash( $i . '|' . $action . '|' . $uid, 'nonce' ), - 12, 10 );
		if ( hash_equals( $expected, $nonce ) ) {
			return 1;
		}

		$expected = substr( wp_hash( ( $i - 1 ) . '|' . $action . '|' . $uid, 'nonce' ), - 12, 10 );
		if ( hash_equals( $expected, $nonce ) ) {
			return 2;
		}

		return false;
	}

	public static function isAdmin() {
		global $current_user;
		if ( $current_user->ID == 0 ) {
			return false;
		}

		if ( $current_user->wp_user_level == 10 || ( isset( $current_user->user_level ) && $current_user->user_level == 10 ) || current_user_can( 'level_10' ) ) {
			return true;
		}

		return false;
	}

	public static function isSSLEnabled()
	{
		if ( defined( 'MAINWP_NOSSL' ) ) return !MAINWP_NOSSL;
		return function_exists( 'openssl_verify' );
	}

    public static function is_screen_with_update() {

        if ( ( defined('DOING_AJAX') && DOING_AJAX )  || ( defined('DOING_CRON') && DOING_CRON ) )
            return false;

        if (function_exists('get_current_screen')) {
            $screen = get_current_screen();
            if ( $screen ) {
                if ( $screen->base == 'update-core' && $screen->parent_file == 'index.php'  ) {
                    return true;
                }
            }
        }
        return false;
    }

    public static function is_wp_engine() {
        return function_exists( 'is_wpe' ) && is_wpe();
    }

    public static function check_files_exists( $files = array(), $return = false ) {
            $missing = array();
            if (is_array($files)) {
                    foreach($files as $name) {
                            if (!file_exists( $name )) {
                                    $missing[] = $name;
                            }
                    }
            } else {
                if (!file_exists( $files )) {
                        $missing[] = $files;
                }
            }

            if (!empty($missing)) {
                $message = 'Missing file(s): ' . implode(',', $missing);
                if ($return)
                    return $message;
                else
                    throw new Exception( $message );
            }
            return true;
	}

	public static function check_classes_exists($classes = array(), $return = false) {
            $missing = array();
            if (is_array($classes)) {
                    foreach($classes as $name) {
                            if (!class_exists( $name )) {
                                    $missing[] = $name;
                            }
                    }
            } else {
                if ( !class_exists($classes) )
                    $missing[] = $classes;
            }

            if ( !empty($missing) ) {
                $message = 'Missing classes: ' . implode(',', $missing);
                if ($return) {
                    return $message;
                } else {
                    throw new Exception( $message );
                }
            }
            return true;
	}

    public static function check_methods($object, $methods = array(), $return = false) {
            $missing = array();
            if (is_array($methods)) {
                    $missing = array();
                    foreach($methods as $name) {
                            if ( !method_exists($object, $name) ) {
                                $missing[] = $name;
                            }
                    }
            } else if (!empty($methods)) {
                if ( !method_exists($object, $methods) )
                    $missing[] = $methods;

            }

            if ( !empty($missing) ) {
                $message = 'Missing method: ' . implode(',', $missing);
                if ($return) {
                    return $message;
                } else {
                    throw new Exception( $message );
                }
            }

            return true;
	}

    public static function check_properties($object, $properties = array(), $return = false) {
             $missing = array();
            if (is_array($properties)) {
                    foreach($properties as $name) {
                            if ( !property_exists($object, $name) ) {
                                $missing[] = $name;
                            }
                    }
            } else if (!empty($properties)) {
                if ( !property_exists($object, $properties) )
                    $missing[] = $properties;

            }

            if ( !empty($missing) ) {
                $message = 'Missing properties: ' . implode(',', $missing);
                if ($return) {
                    return $message;
                } else {
                    throw new Exception( $message );
                }
            }

            return true;
	}

    public static function check_functions($funcs = array(), $return = false) {
            $missing = array();
            if (is_array($funcs)) {
                    foreach($funcs as $name) {
                            if ( !function_exists( $name) ) {
                                $missing[] = $name;
                        }
                    }
            } else if (!empty($funcs)) {
                if ( !function_exists($funcs) )
                    $missing[] = $funcs;

            }

            if ( !empty($missing) ) {
                $message = 'Missing functions: ' . implode(',', $missing);
                if ($return) {
                    return $message;
                } else {
                    throw new Exception( $message );
                }
            }

            return true;
    }


    /**
	 * Handle fatal error for requests from the dashboard
     * mwp_action requests
     * wordpress_seo requests
     * This will do not handle fatal error for sync request from the dashboard
	 */
    public static function handle_fatal_error() {

        function handle_shutdown() {
            // handle fatal errors and compile errors
            $error = error_get_last();
            if ( isset( $error['type'] )  && isset( $error['message'] )  &&
                    ( E_ERROR === $error['type'] || E_COMPILE_ERROR === $error['type'] )
                )
            {
               MainWP_Helper::write( array( 'error' => 'MainWP_Child fatal error : ' . $error['message'] . ' Line: ' . $error['line'] . ' File: ' . $error['file'] ) );
            }

        }

        if (isset($_POST['function']) && isset($_POST['mainwpsignature']) &&
                (isset($_POST['mwp_action']) || 'wordpress_seo' == $_POST['function']) // wordpress_seo for Wordpress SEO
            ) {
            register_shutdown_function( 'handle_shutdown' );
        }
    }

}