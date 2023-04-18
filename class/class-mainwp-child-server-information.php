<?php
/**
 * MainWP Child Server Information
 *
 * MainWP Child server information handler.
 *
 * @package MainWP\Child
 */

namespace MainWP\Child;

/**
 * Class MainWP_Child_Server_Information
 *
 * MainWP Child server information handler.
 *
 * @uses \MainWP\Child\MainWP_Child_Server_Information_Base
 */
class MainWP_Child_Server_Information extends MainWP_Child_Server_Information_Base {
	const WARNING = 1;
	const ERROR   = 2;

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
	 * Add hooks after WordPress has finished loading but before any headers are sent.
	 *
	 * @uses MainWP_Child_Server_Information::dismiss_warnings() Dismiss warnings.
	 */
	public static function init() {
		add_action(
			'wp_ajax_mainwp-child_dismiss_warnings',
			array(
				self::get_class_name(),
				'dismiss_warnings',
			)
		);
	}

	/**
	 * Dismiss warnings.
	 *
	 * @uses \MainWP\Child\MainWP_Helper::update_option() Update option by option name.
	 * @uses \MainWP\Child\MainWP_Child_Server_Information_Base::get_warnings() Initiate check on important System Variables and compare them to required defaults.
	 * @uses get_option() Retrieves an option value based on an option name.
	 * @see https://developer.wordpress.org/reference/functions/get_option/
	 *
	 * @used-by MainWP_Child_Server_Information::init() Add hooks after WordPress has finished loading but before any headers are sent.
	 */
	public static function dismiss_warnings() {
		if ( isset( $_POST['what'] ) ) {
			$dismissWarnings = get_option( 'mainwp_child_dismiss_warnings' );
			if ( ! is_array( $dismissWarnings ) ) {
				$dismissWarnings = array();
			}
			if ( 'warning' == $_POST['what'] ) {
				if ( isset( $_POST['warnings'] ) ) {
					$warnings = intval( $_POST['warnings'] );
				} else {
					$warnings = self::get_warnings();
				}
				$dismissWarnings['warnings'] = $warnings;
			}
			MainWP_Helper::update_option( 'mainwp_child_dismiss_warnings', $dismissWarnings );
		}
	}

	/**
	 * Render warnings.
	 *
	 * @uses \MainWP\Child\MainWP_Helper::update_option() Update option by option name.
	 * @uses \MainWP\Child\MainWP_Child_Server_Information_Base::get_warnings() Initiate check on important System Variables and compare them to required defaults.
	 * @uses get_option() Retrieves an option value based on an option name.
	 * @see https://developer.wordpress.org/reference/functions/get_option/
	 *
	 * @used-by MainWP_Child_Server_Information::init() Add hooks after WordPress has finished loading but before any headers are sent.
	 */
	public static function render_warnings() {
		$request_uri = isset( $_SERVER['REQUEST_URI'] ) ? wp_unslash( $_SERVER['REQUEST_URI'] ) : '';
		if ( isset( $_SERVER['REQUEST_URI'] ) && ( stristr( $request_uri, 'mainwp_child_tab' ) || stristr( $request_uri, 'mainwp-reports-page' ) || stristr( $request_uri, 'mainwp-reports-settings' ) ) ) {
			return;
		}

		// improved query.
		if ( self::is_mainwp_pages() ) {
			return;
		}

		$warnings = self::get_warnings();

		$dismissWarnings = get_option( 'mainwp_child_dismiss_warnings' );
		if ( ! is_array( $dismissWarnings ) ) {
			$dismissWarnings = array();
		}

		if ( isset( $dismissWarnings['warnings'] ) && $dismissWarnings['warnings'] >= $warnings ) {
			$warnings = 0;
		}

		if ( 0 === $warnings ) {
			return;
		}

		if ( $warnings > 0 ) {
			$dismissWarnings['warnings'] = 0;
		}

		MainWP_Helper::update_option( 'mainwp_child_dismiss_warnings', $dismissWarnings );
		?>
		<script language="javascript">
			dismiss_warnings = function ( pElement, pAction ) {
				var table = jQuery( pElement.parents( 'table' )[0] );
				pElement.parents( 'tr' )[0].remove();
				if ( table.find( 'tr' ).length == 0 ) {
					jQuery( '#mainwp-child_server_warnings' ).hide();
				}

				var data = {
					action: 'mainwp-child_dismiss_warnings',
					what: pAction,
					warnings: <?php echo intval( $warnings ); ?>
				};

				jQuery.ajax( {
					type: "POST",
					url: ajaxurl,
					data: data,
					success: function ( resp ) {},
					error: function () {},
					dataType: 'json'
				} );

				return false;
			};
			jQuery( document ).on( 'click', '#mainwp-child-connect-warning-dismiss', function () {
				return dismiss_warnings( jQuery( this ), 'warning' );
			} );
			jQuery( document ).on( 'click', '#mainwp-child-all-pages-warning-dismiss', function () {
				return dismiss_warnings( jQuery( this ), 'conflict' );
			} );
		</script>
		<style type="text/css">
			.mainwp-child_info-box-red-warning {
				background-color: rgba(187, 114, 57, 0.2) !important;
				border-bottom: 4px solid #bb7239 !important;
				border-top: 1px solid #bb7239 !important;
				border-left: 1px solid #bb7239 !important;
				border-right: 1px solid #bb7239 !important;
				-webkit-border-radius: 3px;
				-moz-border-radius: 3px;
				border-radius: 3px;
				margin: 1em 0 !important;

				background-image: url( '<?php echo esc_url( plugins_url( 'images/mainwp-icon-orange.png', dirname( __FILE__ ) ) ); ?>' ) !important;
				background-position: 1.5em 50% !important;
				background-repeat: no-repeat !important;
				background-size: 30px !important;
			}

			.mainwp-child_info-box-red-warning table {
				background-color: rgba(187, 114, 57, 0) !important;
				border: 0px;
				padding-left: 4.5em;
				background-position: 1.5em 50% !important;
				background-repeat: no-repeat !important;
				background-size: 30px !important;
			}
		</style>

		<div class="updated mainwp-child_info-box-red-warning" id="mainwp-child_server_warnings">
			<table id="mainwp-table" class="wp-list-table widefat" cellspacing="0">
				<tbody id="the-sites-list" class="list:sites">
				<?php
				$warning = '';
				if ( $warnings > 0 ) {
					$warning .= '<tr><td colspan="2">This site may not connect to your dashboard or may have other issues. Check your <a href="options-general.php?page=mainwp_child_tab">MainWP server information page</a>.</td><td style="text-align: right;"><a href="#" id="mainwp-child-connect-warning-dismiss">Dismiss</a></td></tr>';
				}
				echo $warning;
				?>
				</tbody>
			</table>
		</div>
		<?php
	}

	/**
	 * Method is_mainwp_pages()
	 *
	 * Get the current page and check it for "mainwp_".
	 *
	 * @return boolean ture|false.
	 */
	public static function is_mainwp_pages() {
		$screen = get_current_screen();
		if ( $screen && strpos( $screen->base, 'mainwp_' ) !== false && strpos( $screen->base, 'mainwp_child_tab' ) === false ) {
			return true;
		}

		return false;
	}

	/**
	 * Render JavaScrip code for the Server Information page.
	 *
	 * @used-by MainWP_Child_Server_Information::render_page() Render the Server Information page.
	 */
	private static function render_page_js() {
		?>
		<script language="javascript">

			/* FileSaver.js
			 * A saveAs() FileSaver implementation.
			 * 2013-01-23
			 *
			 * By Eli Grey, http://eligrey.com
			 * License: X11/MIT
			 * See LICENSE.md
			 */

			/*global self */
			/*jslint bitwise: true, regexp: true, confusion: true, es5: true, vars: true, white: true, plusplus: true */

			/*! @source http://purl.eligrey.com/github/FileSaver.js/blob/master/FileSaver.js */

			var childSaveAs = childSaveAs
				|| ( navigator.msSaveBlob && navigator.msSaveBlob.bind( navigator ) )
				|| ( function( view ) {
					"use strict";
					var doc = view.document
						, get_URL = function() {
							return view.URL || view.webkitURL || view;
						}
						, URL = view.URL || view.webkitURL || view
						, save_link = doc.createElementNS("http://www.w3.org/1999/xhtml", "a")
						, can_use_save_link = "download" in save_link
						, click = function(node) {
							var event = doc.createEvent("MouseEvents");
							event.initMouseEvent(
								"click", true, false, view, 0, 0, 0, 0, 0
								, false, false, false, false, 0, null
							);
							return node.dispatchEvent(event); // false if event was cancelled
						}
						, webkit_req_fs = view.webkitRequestFileSystem
						, req_fs = view.requestFileSystem || webkit_req_fs || view.mozRequestFileSystem
						, throw_outside = function (ex) {
							(view.setImmediate || view.setTimeout)(function() {
								throw ex;
							}, 0);
						}
						, force_saveable_type = "application/octet-stream"
						, fs_min_size = 0
						, deletion_queue = []
						, process_deletion_queue = function() {
							var i = deletion_queue.length;
							while (i--) {
								var file = deletion_queue[i];
								if (typeof file === "string") {
									URL.revokeObjectURL(file);
								} else {
									file.remove();
								}
							}
							deletion_queue.length = 0;
						}
						, dispatch = function(filesaver, event_types, event) {
							event_types = [].concat(event_types);
							var i = event_types.length;
							while (i--) {
								var listener = filesaver["on" + event_types[i]];
								if (typeof listener === "function") {
									try {
										listener.call(filesaver, event || filesaver);
									} catch (ex) {
										throw_outside(ex);
									}
								}
							}
						}
						, FileSaver = function( blob, name ) {
							var
								filesaver = this
								, type = blob.type
								, blob_changed = false
								, object_url
								, target_view
								, get_object_url = function() {
									var object_url = get_URL().createObjectURL(blob);
									deletion_queue.push(object_url);
									return object_url;
								}
								, dispatch_all = function() {
									dispatch( filesaver, "writestart progress write writeend".split( " " ) );
								}
								, fs_error = function() {
									if ( blob_changed || !object_url ) {
										object_url = get_object_url( blob );
									}
									if (target_view) {
										target_view.location.href = object_url;
									}
									filesaver.readyState = filesaver.DONE;
									dispatch_all();
								}
								, abortable = function(func) {
									return function() {
										if (filesaver.readyState !== filesaver.DONE) {
											return func.apply(this, arguments);
										}
									};
								}
								, create_if_not_found = {create: true, exclusive: false}
								, slice
								;
							filesaver.readyState = filesaver.INIT;
							if ( !name ) {
								name = "download";
							}
							if ( can_use_save_link ) {
								object_url = get_object_url( blob );
								save_link.href = object_url;
								save_link.download = name;
								if ( click( save_link ) ) {
									filesaver.readyState = filesaver.DONE;
									dispatch_all();
									return;
								}
							}
							if ( view.chrome && type && type !== force_saveable_type ) {
								slice = blob.slice || blob.webkitSlice;
								blob = slice.call( blob, 0, blob.size, force_saveable_type );
								blob_changed = true;
							}
							if (webkit_req_fs && name !== "download") {
								name += ".download";
							}
							if ( type === force_saveable_type || webkit_req_fs ) {
								target_view = view;
							} else {
								target_view = view.open();
							}
							if (!req_fs) {
								fs_error();
								return;
							}
							fs_min_size += blob.size;
							req_fs(view.TEMPORARY, fs_min_size, abortable(function(fs) {
								fs.root.getDirectory("saved", create_if_not_found, abortable(function(dir) {
									var save = function() {
										dir.getFile(name, create_if_not_found, abortable(function(file) {
											file.createWriter(abortable(function(writer) {
												writer.onwriteend = function(event) {
													target_view.location.href = file.toURL();
													deletion_queue.push(file);
													filesaver.readyState = filesaver.DONE;
													dispatch(filesaver, "writeend", event);
												};
												writer.onerror = function() {
													var error = writer.error;
													if (error.code !== error.ABORT_ERR) {
														fs_error();
													}
												};
												"writestart progress write abort".split(" ").forEach(function(event) {
													writer["on" + event] = filesaver["on" + event];
												});
												writer.write(blob);
												filesaver.abort = function() {
													writer.abort();
													filesaver.readyState = filesaver.DONE;
												};
												filesaver.readyState = filesaver.WRITING;
											}), fs_error);
										}), fs_error);
									};
									dir.getFile(name, {create: false}, abortable(function(file) {
										file.remove();
										save();
									}), abortable(function(ex) {
										if (ex.code === ex.NOT_FOUND_ERR) {
											save();
										} else {
											fs_error();
										}
									}));
								}), fs_error);
							}), fs_error);
						}
						, FS_proto = FileSaver.prototype
						, childSaveAs = function(blob, name) {
							return new FileSaver(blob, name);
						}
						;
					FS_proto.abort = function() {
						var filesaver = this;
						filesaver.readyState = filesaver.DONE;
						dispatch(filesaver, "abort");
					};
					FS_proto.readyState = FS_proto.INIT = 0;
					FS_proto.WRITING = 1;
					FS_proto.DONE = 2;

					FS_proto.error =
						FS_proto.onwritestart =
							FS_proto.onprogress =
								FS_proto.onwrite =
									FS_proto.onabort =
										FS_proto.onerror =
											FS_proto.onwriteend =
												null;

					view.addEventListener("unload", process_deletion_queue, false);
					return childSaveAs;
				}(self));


			mwp_child_strCut = function(i,l,s,w) {
				var o = i.toString();
				if (!s) { s = '0'; }
				while (o.length < parseInt(l)) {
					if( w == 'undefined' ){
						o = s + o;
					}else{
						o = o + s;
					}
				}
				return o;
			};
			jQuery( document ).on( 'click', 'a.mwp-child-get-system-report-btn', function() {
				var report = "";
				jQuery( '.mwp_server_info_box thead, .mwp_server_info_box tbody' ).each( function() {
					var td_len = [35, 55, 45, 12, 12];
					var th_count = 0;
					var i;
					if ( jQuery( this ).is( 'thead' ) ) {
						i = 0;
						report = report + "\n### ";
						th_count = jQuery( this ).find( 'th:not(".mwp-not-generate-row")' ).length;
						jQuery( this ).find( 'th:not(".mwp-not-generate-row")' ).each( function() {
							var len = td_len[i];
							if ( i == 0 || i == th_count -1 )
								len = len - 4;
								report =  report + mwp_child_strCut( jQuery.trim( jQuery( this ).text() ), len, ' ' );
							i++;
						} );
						report = report + " ###\n\n";
					} else {
						jQuery( 'tr', jQuery( this ) ).each( function() {
							if ( jQuery( this ).hasClass( 'mwp-not-generate-row' ) )
								return;
							i = 0;
							jQuery( this ).find( 'td:not(".mwp-not-generate-row")' ).each( function() {
								if (jQuery( this ).hasClass( 'mwp-hide-generate-row' ) ) {
									report =  report + mwp_child_strCut( ' ', td_len[i], ' ' );
									i++;
									return;
								}
								report =  report + mwp_child_strCut( jQuery.trim( jQuery( this ).text() ), td_len[i], ' ' );
								i++;
							} );
							report = report + "\n";
						} );
					}
				} );
				try {
					jQuery( "#mwp-server-information" ).slideDown();
					jQuery( "#mwp-server-information textarea" ).val( report ).focus().select();
					jQuery( this ).fadeOut();
					jQuery( '.mwp_child_close_srv_info' ).show();
					return false;
				} catch(e){ }
			} );

			jQuery( document ).on( 'click', 'a#mwp_child_close_srv_info', function() {
				jQuery( '#mwp-server-information' ).hide();
				jQuery( '.mwp_child_close_srv_info' ).hide();
				jQuery( 'a.mwp-child-get-system-report-btn' ).show();
				return false;
			} );
			jQuery( document ).on( 'click', '#mwp_child_download_srv_info', function() {
				var server_info = jQuery( '#mwp-server-information textarea' ).val();
				var blob = new Blob( [server_info], {type: "text/plain;charset=utf-8"} );
				childSaveAs( blob, "server_child_information.txt" );
			} );
		</script>
		<?php
	}

	/**
	 * Render the Server Information page.
	 *
	 * @uses MainWP_Child_Server_Information::render_page_js() Render JavaScrip code for the Server Information page.
	 * @uses MainWP_Child_Server_Information::render_server_infor() Render server information.
	 * @uses MainWP_Child_Server_Information::render_cron() Render cron schedules.
	 * @uses MainWP_Child_Server_Information::render_error_page() Render error log.
	 */
	public static function render_page() {
		self::render_page_js();
		?>
		<style type="text/css">
			#mwp-server-information {
				display: none;
				margin: 10px 0;
				padding: 0;
				position: relative;
			}

			#mwp-server-information textarea {
				border-radius: 0;
				font-family: monospace;
				font-size: 12px;
				height: 300px;
				line-height: 20px;
				margin: 0;
				outline: 0 none;
				padding: 20px;
				resize: none;
				width: 100%;
				-moz-border-radius:0;
				-webkit-border-radius:0;
			}

			.mwp_child_close_srv_info {
				display: none;
				float: right;
				margin:  5px 0 5px;
			}
		</style>
		<div class="wrap">
			<div class="updated below-h2">
				<p><?php esc_html_e( 'Please include this information when requesting support:', 'mainwp-child' ); ?></p>
				<span class="mwp_child_close_srv_info">
					<a href="#" id="mwp_child_download_srv_info"><?php esc_html_e( 'Download', 'mainwp-child' ); ?></a> | <a href="#" id="mwp_child_close_srv_info"><i class="fa fa-eye-slash"></i> <?php esc_html_e( 'Hide', 'mainwp-child' ); ?></a>
				</span>
				<p class="submit">
					<a class="button-primary mwp-child-get-system-report-btn" href="#"><?php esc_html_e( 'Get system report', 'mainwp-child' ); ?></a>
				</p>
				<div id="mwp-server-information"><textarea readonly="readonly" wrap="off"></textarea></div>
			</div>
			<br/>
			<div class="mwp_server_info_box">
				<h2><?php esc_html_e( 'Server Information', 'mainwp-child' ); ?></h2>
				<?php self::render_server_infor(); ?>
				<h2><?php esc_html_e( 'Cron Schedules', 'mainwp-child' ); ?></h2>
				<?php self::render_cron(); ?>
				<h2><?php esc_html_e( 'Error Log', 'mainwp-child' ); ?></h2>
				<?php self::render_error_page(); ?>
			</div>
		</div>
		<?php
	}

	/**
	 * Render server information content.
	 *
	 * @uses MainWP_Child_Server_Information::render_system_infor_rows() Render system information rows.
	 * @uses MainWP_Child_Server_Information::render_php_settings_rows() Render PHP settings information rows.
	 * @uses MainWP_Child_Server_Information::render_mysql_infor_rows() Render MySQL settings information rows.
	 * @uses MainWP_Child_Server_Information::render_server_infor_rows() Render server settings information rows.
	 * @uses MainWP_Child_Server_Information::render_php_infor_rows() Render PHP information rows.
	 * @uses MainWP_Child_Server_Information::render_plugins_infor_rows() Render plugins information rows.
	 *
	 * @used-by MainWP_Child_Server_Information::render_page() Render the Server Information page.
	 *
	 * @uses \MainWP\Child\MainWP_Child_Branding::get_branding_title()
	 */
	private static function render_server_infor() {
		$branding_title = MainWP_Child_Branding::instance()->get_branding_title();
		if ( '' == $branding_title ) {
			$branding_title = 'MainWP Child';
		}
		?>
		<table id="mainwp-table" class="wp-list-table widefat" cellspacing="0">
			<thead>
				<tr>
					<th scope="col" class="manage-column column-posts mwp-not-generate-row" style="width: 1px;"></th>
					<th scope="col" class="manage-column column-posts"><span><?php esc_html_e( 'Server configuration', 'mainwp-child' ); ?></span></th>
					<th scope="col" class="manage-column column-posts"><?php esc_html_e( 'Required value', 'mainwp-child' ); ?></th>
					<th scope="col" class="manage-column column-posts"><?php esc_html_e( 'Value', 'mainwp-child' ); ?></th>
					<th scope="col" class="manage-column column-posts"><?php esc_html_e( 'Status', 'mainwp-child' ); ?></th>
				</tr>
			</thead>
			<tbody id="the-sites-list" class="list:sites">
				<?php self::render_system_infor_rows( $branding_title ); ?>
				<?php self::render_php_settings_rows(); ?>
				<?php self::render_mysql_infor_rows(); ?>
				<?php self::render_server_infor_rows(); ?>
				<?php self::render_php_infor_rows(); ?>
				<?php self::render_plugins_infor_rows( $branding_title ); ?>
			</tbody>
		</table>
		<br/>
		<?php
	}

	/**
	 * Render system information rows.
	 *
	 * @param string $branding_title Custom branding plgin title.
	 *
	 * @uses MainWP_Child_Server_Information_Base::get_mainwp_version() Get the MainWP Child plugin version number.
	 * @uses MainWP_Child_Server_Information_Base::get_current_version() Get the current MainWP Child plugin version.
	 * @uses MainWP_Child_Server_Information_Base::get_file_system_method() Get file system method.
	 * @uses MainWP_Child_Server_Information::render_file_system_method_check() Render the file system method check.
	 * @uses MainWP_Child_Server_Information::render_mainwp_version_check() Render the MainWP version check row.
	 * @uses MainWP_Child_Server_Information::render_row() Render the server information row.
	 * @uses get_option() Retrieves an option value based on an option name.
	 * @see https://developer.wordpress.org/reference/functions/get_option/
	 *
	 * @used-by MainWP_Child_Server_Information::render_server_infor() Render server information content.
	 */
	private static function render_system_infor_rows( $branding_title ) {
		?>
		<tr>
			<td style="background: #333; color: #fff;" colspan="5"><?php echo esc_html( strtoupper( stripslashes( $branding_title ) ) ); ?></td>
		</tr>
		<tr>
			<td></td>
			<td><?php echo esc_html( stripslashes( $branding_title ) ) . ' ' . esc_html__( 'Version', 'mainwp-chil' ); ?></td>
			<td><?php echo esc_html( self::get_mainwp_version() ); ?></td>
			<td><?php echo esc_html( self::get_current_version() ); ?></td>
			<td><?php echo esc_html( self::render_mainwp_version_check() ); ?></td>
		</tr>
		<?php self::render_mainwp_directory(); ?>	
		<tr>
			<td style="background: #333; color: #fff;" colspan="5"><?php esc_html_e( 'WordPress', 'mainwp-child' ); ?></td>
		</tr>
		<?php self::render_row( 'WordPress Version', '>=', '3.4', 'get_wordpress_version' ); ?>
		<?php self::render_row( 'WordPress Memory Limit', '>=', '64M', 'get_wordpress_memory_limit' ); ?>
		<?php self::render_row( 'MultiSite Disabled', '=', true, 'check_if_multisite' ); ?>
		<tr>
			<td></td>
			<td><?php esc_html_e( 'FileSystem Method', 'mainwp-child' ); ?></td>
			<td><?php echo esc_html( '= direct' ); ?></td>
			<td><?php echo esc_html( self::get_file_system_method() ); ?></td>
			<td><?php echo esc_html( self::render_file_system_method_check() ); ?></td>
		</tr>
		<?php
	}

	/**
	 * Render the file system method check.
	 *
	 * @uses MainWP_Child_Server_Information_Base::get_file_system_method() Get file system method.
	 *
	 * @used-by MainWP_Child_Server_Information::render_system_infor_rows() Render system information rows.
	 */
	protected static function render_file_system_method_check() {
		$fsmethod = self::get_file_system_method();
		if ( 'direct' === $fsmethod ) {
			echo '<span class="mainwp-pass"><i class="fa fa-check-circle"></i> Pass</span>';
		} else {
			echo '<span class="mainwp-warning"><i class="fa fa-exclamation-circle"></i> Warning</span>';
		}
	}

	/**
	 * Render PHP settings information rows.
	 *
	 * @uses MainWP_Child_Server_Information::render_row() Render the server information row.
	 * @uses MainWP_Child_Server_Information::render_row_sec() Render the server information secondary row.
	 * @uses MainWP_Child_Server_Information_Base::get_php_safe_mode() Check if PHP is in Safe Mode.
	 *
	 * @used-by MainWP_Child_Server_Information::render_system_infor_rows() Render system information rows.
	 */
	private static function render_php_settings_rows() {
		?>
		<tr>
			<td style="background: #333; color: #fff;"
				colspan="5"><?php esc_html_e( 'PHP SETTINGS', 'mainwp-child' ); ?></td>
		</tr>
		<?php self::render_row( 'PHP Version', '>=', '7.4', 'get_php_version' ); ?>
		<tr>
			<td></td>
			<td><?php esc_html_e( 'PHP Safe Mode Disabled', 'mainwp-child' ); ?></td>
			<td colspan="3"><?php self::get_php_safe_mode(); ?></td>
		</tr>
		<?php
		self::render_row_sec( 'PHP Max Execution Time', '>=', '30', 'get_max_execution_time', 'seconds', '=', '0' );
		self::render_row_sec( 'PHP Max Input Time', '>=', '30', 'get_max_input_time', 'seconds', '=', '0' );
		self::render_row( 'PHP Memory Limit', '>=', '128M', 'get_php_memory_limit', '(256M+ best for big backups)', null, null, true );
		self::render_row( 'PCRE Backtracking Limit', '>=', '10000', 'get_output_buffer_size' );
		self::render_row( 'PHP Upload Max Filesize', '>=', '2M', 'get_upload_max_filesize', '(2MB+ best for upload of big plugins)', null, null, true );
		self::render_row( 'PHP Post Max Size', '>=', '2M', 'get_post_max_size', '(2MB+ best for upload of big plugins)', null, null, true );
		self::render_row( 'SSL Extension Enabled', '=', true, 'get_ssl_support' );
		self::render_row_sec( 'SSL Warnings', '=', '', 'get_ssl_warning', 'empty', '' );
		self::render_row_sec( 'cURL Extension Enabled', '=', true, 'get_curl_support', '', '', null, '', null, self::ERROR );
		self::render_row_sec( 'cURL Timeout', '>=', '300', 'get_curl_timeout', 'seconds', '=', '0' );
		if ( function_exists( 'curl_version' ) ) {
			self::render_row_sec( 'cURL Version', '>=', '7.18.1', 'get_curl_version', '', '', null );
			$openssl_version = 'OpenSSL/1.1.0';
			self::render_row_sec(
				'cURL SSL Version',
				'>=',
				$openssl_version,
				'get_curl_ssl_version',
				'',
				'',
				null,
				'',
				'curlssl'
			);
			if ( ! self::curlssl_compare( $openssl_version, '>=' ) ) {
				echo "<tr style=\"background:#fffaf3\"><td colspan='5'><span class=\"mainwp-warning\"><i class='fa fa-exclamation-circle'>" . sprintf( esc_html__( 'Your host needs to update OpenSSL to at least version 1.1.0 which is already over 4 years old and contains patches for over 60 vulnerabilities.%1$sThese range from Denial of Service to Remote Code Execution. %2$sClick here for more information.%3$s', 'mainwp' ), '<br/>', '<a href="https://community.letsencrypt.org/t/openssl-client-compatibility-changes-for-let-s-encrypt-certificates/143816" target="_blank">', '</a>' ) . '</span></td></tr>';
			}
		}
	}

	/**
	 * Render MySQL settings information rows.
	 *
	 * @uses MainWP_Child_Server_Information::render_row() Render the server information row.
	 *
	 * @used-by MainWP_Child_Server_Information::render_server_infor() Render server information content.
	 */
	private static function render_mysql_infor_rows() {
		?>
		<tr>
			<td style="background: #333; color: #fff;" colspan="5"><?php esc_html_e( 'MySQL SETTINGS', 'mainwp-child' ); ?></td>
		</tr>
		<?php self::render_row( 'MySQL Version', '>=', '5.0', 'get_my_sql_version' ); ?>
		<tr>
			<td style="background: #333; color: #fff;" colspan="5"><?php esc_html_e( 'BACKUP ARCHIVE INFORMATION', 'mainwp-child' ); ?></td>
		</tr>
		<?php self::render_row( 'ZipArchive enabled in PHP', '=', true, 'get_zip_archive_enabled' ); ?>
		<?php self::render_row( 'Tar GZip supported', '=', true, 'get_gzip_enabled' ); ?>
		<?php self::render_row( 'Tar BZip2 supported', '=', true, 'get_bzip_enabled' ); ?>
		<?php
	}

	/**
	 * Render plugins information rows.
	 *
	 * @param string $branding_title Custom branding plugin title.
	 *
	 * @uses get_plugins() Check the plugins directory and retrieve all plugin files with plugin data.
	 * @see https://developer.wordpress.org/reference/functions/get_plugins/
	 *
	 * @uses is_plugin_active() Determines whether a plugin is active.
	 * @see https://developer.wordpress.org/reference/functions/get_plugins/
	 *
	 * @used-by MainWP_Child_Server_Information::render_server_infor() Render server information content.
	 */
	private static function render_plugins_infor_rows( $branding_title ) {
		?>
		<tr>
			<td style="background: #333; color: #fff;" colspan="5"><?php esc_html_e( 'WordPress PLUGINS', 'mainwp-child' ); ?></td>
		</tr>
		<?php
		$all_plugins = get_plugins();
		foreach ( $all_plugins as $slug => $plugin ) {
			if ( ! empty( $branding_title ) && ( 'mainwp-child/mainwp-child.php' == $slug || 'mainwp-child-reports/mainwp-child-reports.php' == $slug ) ) {
				if ( 'mainwp-child/mainwp-child.php' == $slug ) {
					$plugin['Name'] = esc_html( stripslashes( $branding_title ) );
				} elseif ( 'mainwp-child-reports/mainwp-child-reports.php' == $slug ) {
					$plugin['Name'] = esc_html( stripslashes( $branding_title ) ) . ' reports';
				}
			}
			?>
			<tr>
				<td></td>
				<td><?php echo esc_html( $plugin['Name'] ); ?></td>
				<td><?php echo esc_html( $plugin['Version'] ); ?></td>
				<td><?php echo is_plugin_active( $slug ) ? esc_html__( 'Active', 'mainwp-child' ) : esc_html__( 'Inactive', 'mainwp-child' ); ?></td>
				<td></td>
			</tr>
			<?php
		}
	}

	/**
	 * Render PHP information rows.
	 *
	 * @uses MainWP_Child_Server_Information_Base::get_php_allow_url_fopen() Check if PHP Allow URL fopen is enabled.
	 * @uses MainWP_Child_Server_Information_Base::get_php_exif() Check if PHP exif is enabled.
	 * @uses MainWP_Child_Server_Information_Base::get_php_ip_tc() Check if PHP IP TC is enabled.
	 * @uses MainWP_Child_Server_Information_Base::get_php_xml() Check if PHP XML is enabled.
	 * @uses MainWP_Child_Server_Information_Base::mainwp_required_functions() Check for disabled PHP functions.
	 * @uses MainWP_Child_Server_Information_Base::get_loaded_php_extensions() Get loaded PHP extensions.
	 * @uses MainWP_Child_Server_Information_Base::get_sql_mode() Get current SQL mode.
	 *
	 * @used-by MainWP_Child_Server_Information::render_server_infor() Render server information content.
	 */
	private static function render_php_infor_rows() {
		?>
		<tr>
			<td style="background: #333; color: #fff;" colspan="5"><?php esc_html_e( 'PHP INFORMATION', 'mainwp-child' ); ?></td>
		</tr>
		<tr>
			<td></td>
			<td><?php esc_html_e( 'PHP Allow URL fopen', 'mainwp-child' ); ?></td>
			<td colspan="3"><?php self::get_php_allow_url_fopen(); ?></td>
		</tr>
		<tr>
			<td></td>
			<td><?php esc_html_e( 'PHP Exif Support', 'mainwp-child' ); ?></td>
			<td colspan="3"><?php self::get_php_exif(); ?></td>
		</tr>
		<tr>
			<td></td>
			<td><?php esc_html_e( 'PHP IPTC Support', 'mainwp-child' ); ?></td>
			<td colspan="3"><?php self::get_php_ip_tc(); ?></td>
		</tr>
		<tr>
			<td></td>
			<td><?php esc_html_e( 'PHP XML Support', 'mainwp-child' ); ?></td>
			<td colspan="3"><?php self::get_php_xml(); ?></td>
		</tr>
		<tr>
			<td></td>
			<td><?php esc_html_e( 'PHP Disabled Functions', 'mainwp-child' ); ?></td>
			<td colspan="3"><?php self::mainwp_required_functions(); ?></td>
		</tr>
		<tr>
			<td></td>
			<td><?php esc_html_e( 'PHP Loaded Extensions', 'mainwp-child' ); ?></td>
			<td colspan="3" style="width: 73% !important;"><?php self::get_loaded_php_extensions(); ?></td>
		</tr>
		<tr>
			<td style="background: #333; color: #fff;"
				colspan="5"><?php esc_html_e( 'MySQL INFORMATION', 'mainwp-child' ); ?></td>
		</tr>
		<tr>
			<td></td>
			<td><?php esc_html_e( 'MySQL Mode', 'mainwp-child' ); ?></td>
			<td colspan="3"><?php self::get_sql_mode(); ?></td>
		</tr>
		<tr>
			<td></td>
			<td><?php esc_html_e( 'MySQL Client Encoding', 'mainwp-child' ); ?></td>
			<td colspan="3"><?php echo esc_html( defined( 'DB_CHARSET' ) ? DB_CHARSET : '' ); ?></td>
		</tr>
		<?php
	}

	/**
	 * Render server settings information rows.
	 *
	 * @uses MainWP_Child_Server_Information_Base::get_wp_root() Get WordPress root directory.
	 * @uses MainWP_Child_Server_Information_Base::get_server_name()Get server name.
	 * @uses MainWP_Child_Server_Information_Base::get_server_software() Get server software.
	 * @uses MainWP_Child_Server_Information_Base::get_os() Get operating system.
	 * @uses MainWP_Child_Server_Information_Base::get_architecture() Get System architecture.
	 * @uses MainWP_Child_Server_Information_Base::get_server_ip() Get server IP.
	 * @uses MainWP_Child_Server_Information_Base::get_server_protocol() Get server protocol.
	 * @uses MainWP_Child_Server_Information_Base::get_http_host() Get server HTTP host.
	 * @uses MainWP_Child_Server_Information_Base::get_https() Check if HTTPS is on.
	 * @uses MainWP_Child_Server_Information_Base::server_self_connect() Server self-connection test.
	 * @uses MainWP_Child_Server_Information_Base::get_user_agent() Get server user agent.
	 * @uses MainWP_Child_Server_Information_Base::get_server_port() Get server port.
	 * @uses MainWP_Child_Server_Information_Base::get_server_getaway_interface() Get current server gateway interface.
	 * @uses MainWP_Child_Server_Information_Base::memory_usage() Get the current Memory usage.
	 * @uses MainWP_Child_Server_Information_Base::get_complete_url() Get server complete URL.
	 * @uses MainWP_Child_Server_Information_Base::get_server_request_time() Get server request time.
	 * @uses MainWP_Child_Server_Information_Base::get_server_http_accept() Get server HTTP accept.
	 * @uses MainWP_Child_Server_Information_Base::get_server_accept_charset() Get server accepted charset.
	 * @uses MainWP_Child_Server_Information_Base::get_script_file_name() Get server script filename.
	 * @uses MainWP_Child_Server_Information_Base::get_current_page_uri() Get current page URL.
	 * @uses MainWP_Child_Server_Information_Base::get_remote_address() Get server remote address.
	 * @uses MainWP_Child_Server_Information_Base::get_remote_host() Get server remote host.
	 * @uses MainWP_Child_Server_Information_Base::get_remote_port() Get server remote port.
	 *
	 * @used-by MainWP_Child_Server_Information::render_server_infor() Render server information content.
	 */
	private static function render_server_infor_rows() {
		?>
		<tr>
			<td style="background: #333; color: #fff;" colspan="5"><?php esc_html_e( 'SERVER INFORMATION', 'mainwp-child' ); ?></td>
		</tr>
		<tr>
			<td></td>
			<td><?php esc_html_e( 'WordPress Root Directory', 'mainwp-child' ); ?></td>
			<td colspan="3"><?php self::get_wp_root(); ?></td>
		</tr>
		<tr>
			<td></td>
			<td><?php esc_html_e( 'Server Name', 'mainwp-child' ); ?></td>
			<td colspan="3"><?php self::get_server_name(); ?></td>
		</tr>
		<tr>
			<td></td>
			<td><?php esc_html_e( 'Server Software', 'mainwp-child' ); ?></td>
			<td colspan="3"><?php self::get_server_software(); ?></td>
		</tr>
		<tr>
			<td></td>
			<td><?php esc_html_e( 'Operating System', 'mainwp-child' ); ?></td>
			<td colspan="3"><?php self::get_os(); ?></td>
		</tr>
		<tr>
			<td></td>
			<td><?php esc_html_e( 'Architecture', 'mainwp-child' ); ?></td>
			<td colspan="3"><?php self::get_architecture(); ?></td>
		</tr>
		<tr>
			<td></td>
			<td><?php esc_html_e( 'Server IP', 'mainwp-child' ); ?></td>
			<td colspan="3"><?php self::get_server_ip(); ?></td>
		</tr>
		<tr>
			<td></td>
			<td><?php esc_html_e( 'Server Protocol', 'mainwp-child' ); ?></td>
			<td colspan="3"><?php self::get_server_protocol(); ?></td>
		</tr>
		<tr>
			<td></td>
			<td><?php esc_html_e( 'HTTP Host', 'mainwp-child' ); ?></td>
			<td colspan="3"><?php self::get_http_host(); ?></td>
		</tr>
		<tr>
			<td></td>
			<td><?php esc_html_e( 'HTTPS', 'mainwp-child' ); ?></td>
			<td colspan="3"><?php self::get_https(); ?></td>
		</tr>
		<tr>
			<td></td>
			<td><?php esc_html_e( 'Server self connect', 'mainwp-child' ); ?></td>
			<td colspan="3"><?php self::server_self_connect(); ?></td>
		</tr>
		<tr>
			<td></td>
			<td><?php esc_html_e( 'User Agent', 'mainwp-child' ); ?></td>
			<td colspan="3"><?php self::get_user_agent(); ?></td>
		</tr>
		<tr>
			<td></td>
			<td><?php esc_html_e( 'Server Port', 'mainwp-child' ); ?></td>
			<td colspan="3"><?php self::get_server_port(); ?></td>
		</tr>
		<tr>
			<td></td>
			<td><?php esc_html_e( 'Gateway Interface', 'mainwp-child' ); ?></td>
			<td colspan="3"><?php self::get_server_getaway_interface(); ?></td>
		</tr>
		<tr>
			<td></td>
			<td><?php esc_html_e( 'Memory Usage', 'mainwp-child' ); ?></td>
			<td colspan="3"><?php self::memory_usage(); ?></td>
		</tr>
		<tr>
			<td></td>
			<td><?php esc_html_e( 'Complete URL', 'mainwp-child' ); ?></td>
			<td colspan="3"><?php self::get_complete_url(); ?></td>
		</tr>
		<tr>
			<td></td>
			<td><?php esc_html_e( 'Request Time', 'mainwp-child' ); ?></td>
			<td colspan="3"><?php self::get_server_request_time(); ?></td>
		</tr>
		<tr>
			<td></td>
			<td><?php esc_html_e( 'Accept Content', 'mainwp-child' ); ?></td>
			<td colspan="3"><?php self::get_server_http_accept(); ?></td>
		</tr>
		<tr>
			<td></td>
			<td><?php esc_html_e( 'Accept-Charset Content', 'mainwp-child' ); ?></td>
			<td colspan="3"><?php self::get_server_accept_charset(); ?></td>
		</tr>
		<tr>
			<td></td>
			<td><?php esc_html_e( 'Currently Executing Script Pathname', 'mainwp-child' ); ?></td>
			<td colspan="3"><?php self::get_script_file_name(); ?></td>
		</tr>
		<tr>
			<td></td>
			<td><?php esc_html_e( 'Current Page URI', 'mainwp-child' ); ?></td>
			<td colspan="3"><?php self::get_current_page_uri(); ?></td>
		</tr>
		<tr>
			<td></td>
			<td><?php esc_html_e( 'Remote Address', 'mainwp-child' ); ?></td>
			<td colspan="3"><?php self::get_remote_address(); ?></td>
		</tr>
		<tr>
			<td></td>
			<td><?php esc_html_e( 'Remote Host', 'mainwp-child' ); ?></td>
			<td colspan="3"><?php self::get_remote_host(); ?></td>
		</tr>
		<tr>
			<td></td>
			<td><?php esc_html_e( 'Remote Port', 'mainwp-child' ); ?></td>
			<td colspan="3"><?php self::get_remote_port(); ?></td>
		</tr>
		<?php
	}

	/**
	 * Render the MainWP version check.
	 *
	 * @uses MainWP_Child_Server_Information_Base::get_mainwp_version() Get the current MainWP Child plugin version.
	 *
	 * @uses get_option() Retrieves an option value based on an option name.
	 * @see https://developer.wordpress.org/reference/functions/get_option/
	 *
	 * @used-by MainWP_Child_Server_Information::render_system_infor_rows() Render system information rows.
	 */
	protected static function render_mainwp_version_check() {
		$current = get_option( 'mainwp_child_plugin_version' );
		$latest  = self::get_mainwp_version();
		if ( $current === $latest ) {
			echo '<span class="mainwp-pass"><i class="fa fa-check-circle"></i> Pass</span>';
		} else {
			echo '<span class="mainwp-warning"><i class="fa fa-exclamation-circle"></i> Warning</span>';
		}
	}

	/**
	 * Render cron schedules.
	 *
	 * @uses _get_cron_array() Retrieve cron info array option.
	 * @see https://developer.wordpress.org/reference/functions/_get_cron_array/
	 *
	 * @uses wp_get_schedules() Retrieve supported event recurrence schedules.
	 * @see https://developer.wordpress.org/reference/functions/wp_get_schedules/
	 *
	 * @uses \MainWP\Child\MainWP_Helper::format_timestamp() Format timestamp as per the WordPress general settings.
	 *
	 * @used-by \MainWP\Child\MainWP_Child_Server_Information::render_page() Render the Server Information page.
	 */
	private static function render_cron() {
		$cron_array = _get_cron_array();
		$schedules  = wp_get_schedules();
		?>
		<table id="mainwp-table" class="wp-list-table widefat" cellspacing="0">
			<thead>
				<tr>
					<th scope="col" class="manage-column column-posts"><span><?php esc_html_e( 'Next due', 'mainwp-child' ); ?></span></th>
					<th scope="col" class="manage-column column-posts"><span><?php esc_html_e( 'Schedule', 'mainwp-child' ); ?></span></th>
					<th scope="col" class="manage-column column-posts"><span><?php esc_html_e( 'Hook', 'mainwp-child' ); ?></span></th>
				</tr>
			</thead>
			<tbody id="the-sites-list" class="list:sites">
			<?php
			foreach ( $cron_array as $time => $cron ) {
				foreach ( $cron as $hook => $cron_info ) {
					foreach ( $cron_info as $key => $schedule ) {
						?>
						<tr>
							<td><?php echo esc_html( MainWP_Helper::format_timestamp( MainWP_Helper::get_timestamp( $time ) ) ); ?></td>
							<td><?php echo esc_html( ( isset( $schedule['schedule'] ) && isset( $schedules[ $schedule['schedule'] ] ) && isset( $schedules[ $schedule['schedule'] ]['display'] ) ) ? $schedules[ $schedule['schedule'] ]['display'] : '' ); ?> </td>
							<td><?php echo esc_html( $hook ); ?></td>
						</tr>
						<?php
					}
				}
			}
			?>
			</tbody>
		</table>
		<?php
	}

	/**
	 * Render the MainWP directory check.
	 *
	 * @uses MainWP_Child_Server_Information_Base::check_mainwp_directory() Check if MainWP Directory is writeable.
	 * @uses MainWP_Child_Server_Information::render_directory_row() Render the directroy check row.
	 *
	 * @used-by MainWP_Child_Server_Information::render_system_infor_rows() Render system information rows.
	 *
	 * @uses \MainWP\Child\MainWP_Child_Branding::get_branding_title()
	 */
	protected static function render_mainwp_directory() {
		$branding_title = MainWP_Child_Branding::instance()->get_branding_title();
		if ( '' == $branding_title ) {
			$branding_title = 'MainWP';
		}
		$branding_title .= ' Upload Directory';

		$path    = '';
		$message = 'Writable';

		self::check_mainwp_directory( $message, $path );

		self::render_directory_row( $branding_title, $path, 'Writable', $message, true );
	}

	/**
	 * Render the directroy check row.
	 *
	 * @param string $name      Check name.
	 * @param string $directory Directory to check.
	 * @param string $check     Check condition.
	 * @param string $result    Check result.
	 * @param string $passed    Show correct label depending on passed status.
	 *
	 * @used-by \MainWP\Child\MainWP_Child_Server_Information::render_mainwp_directory() Render the MainWP directory check.
	 *
	 * @uses \MainWP\Child\MainWP_Child_Branding::is_branding()
	 */
	protected static function render_directory_row( $name, $directory, $check, $result, $passed ) {
		?>
		<tr class="mwp-not-generate-row">
			<td></td>
			<td><?php echo esc_html( stripslashes( $name ) ); ?><br/><?php echo esc_html( ( MainWP_Child_Branding::instance()->is_branding() ) ? '' : $directory ); ?></td>
			<td><?php echo esc_html( $check ); ?></td>
			<td><?php echo esc_html( $result ); ?></td>
			<td><?php echo ( $passed ? '<span class="mainwp-pass"><i class="fa fa-check-circle"></i> Pass</span>' : '<span class="mainwp-warning"><i class="fa fa-exclamation-circle"></i> Warning</span>' ); ?></td>
		</tr>
		<?php
	}

	/**
	 * Render the server information check row.
	 *
	 * @param string $config        Check name.
	 * @param string $compare       Comparison operator.
	 * @param string $version       Version for comparison.
	 * @param string $getter        Function to call to handle comparison.
	 * @param string $extra_text    Extra text to display in the row.
	 * @param string $extra_compare Additional comparison operator.
	 * @param string $extra_version Additional version to compare.
	 * @param bool   $size_compare  Determies if size should be compared.
	 *
	 * @uses MainWP_Child_Server_Information_Base::check() Check Child Site system variables for any issues.
	 *
	 * @used-by MainWP_Child_Server_Information::render_system_infor_rows() Render system information rows.
	 * @used-by MainWP_Child_Server_Information::render_php_settings_rows() Render PHP settings information rows.
	 * @used-by MainWP_Child_Server_Information::render_mysql_infor_rows() Render MySQL settings information rows.
	 */
	protected static function render_row( $config, $compare, $version, $getter, $extra_text = '', $extra_compare = null, $extra_version = null, $size_compare = false ) {
		$currentVersion = call_user_func( array( self::get_class_name(), $getter ) );
		?>
		<tr>
			<td></td>
			<td><?php echo esc_html( esc_html( $config ) ); ?></td>
			<td><?php echo esc_html( esc_html( $compare ) ); ?><?php echo esc_html( ( true === $version ? 'true' : $version ) . ' ' . $extra_text ); ?></td>
			<td><?php echo esc_html( true === $currentVersion ? 'true' : $currentVersion ); ?></td>
			<td><?php echo ( self::check( $compare, $version, $getter, $extra_compare, $extra_version, $size_compare ) ? '<span class="mainwp-pass"><i class="fa fa-check-circle"></i> Pass</span>' : '<span class="mainwp-warning"><i class="fa fa-exclamation-circle"></i> Warning</span>' ); ?></td>
		</tr>
		<?php
	}

	/**
	 * Render the server information check secondary row.
	 *
	 * @param string $config        Check name.
	 * @param string $compare       Comparison operator.
	 * @param string $version       Version for comparison.
	 * @param string $getter        Function to call to handle comparison.
	 * @param string $extra_text    Extra text to display in the row.
	 * @param string $extra_compare Additional comparison operator.
	 * @param string $extra_version Additional version to compare.
	 * @param string $toolTip       Tooltip to show.
	 * @param string $whatType      What type.
	 * @param string $errorType     Error type.
	 *
	 * @used-by MainWP_Child_Server_Information::render_php_settings_rows() Render PHP settings information rows.
	 */
	protected static function render_row_sec( $config, $compare, $version, $getter, $extra_text = '', $extra_compare = null, $extra_version = null, $toolTip = null, $whatType = null, $errorType = self::WARNING ) {
		$currentVersion = call_user_func( array( self::get_class_name(), $getter ) );
		?>
		<tr>
			<td></td>
			<td><?php echo $config; ?></td>
			<td><?php echo $compare; ?><?php echo ( true === $version ? 'true' : ( is_array( $version ) && isset( $version['version'] ) ? $version['version'] : $version ) ) . ' ' . $extra_text; ?></td>
			<td><?php echo( true === $currentVersion ? 'true' : $currentVersion ); ?></td>
			<?php if ( 'filesize' === $whatType ) { ?>
				<td><?php echo( self::filesize_compare( $currentVersion, $version, $compare ) ? '<span class="mainwp-pass"><i class="fa fa-check-circle"></i> Pass</span>' : self::render_warning_text( $errorType ) ); ?></td>
			<?php } elseif ( 'get_curl_ssl_version' === $getter ) { ?>
				<td><?php echo( self::curlssl_compare( $version, $compare ) ? '<span class="mainwp-pass"><i class="fa fa-check-circle"></i> Pass</span>' : self::render_warning_text( $errorType ) ); ?></td>
			<?php } elseif ( ( 'get_max_input_time' === $getter || 'get_max_execution_time' === $getter ) && -1 == $currentVersion ) { ?>
				<td><?php echo '<span class="mainwp-pass"><i class="fa fa-check-circle"></i> Pass</span>'; ?></td>
			<?php } else { ?>
				<td><?php echo ( version_compare( $currentVersion, $version, $compare ) || ( ( null != $extra_compare ) && version_compare( $currentVersion, $extra_version, $extra_compare ) ) ? '<span class="mainwp-pass"><i class="fa fa-check-circle"></i> Pass</span>' : self::render_warning_text( $errorType ) ); ?></td>
			<?php } ?>
		</tr>
		<?php
	}

	/**
	 * Render warning text.
	 *
	 * @param string $errorType Error type.
	 *
	 * @used-by MainWP_Child_Server_Information::render_row_sec() Render the server information check secondary row.
	 *
	 * @return string Warning message HTML.
	 */
	public static function render_warning_text( $errorType = self::WARNING ) {
		if ( self::WARNING == $errorType ) {
			return '<span class="mainwp-warning"><i class="fa fa-exclamation-circle"></i> Warning</span>';
		}
		return '<span class="mainwp-fail"><i class="fa fa-exclamation-circle"></i> Fail</span>';
	}

	/**
	 * Render the Error log page.
	 *
	 * @uses MainWP_Child_Server_Information::render_error_log() Render the error log content.
	 *
	 * @used-by MainWP_Child_Server_Information::render_page() Rener the Server Information page.
	 *
	 * Credits
	 *
	 * Plugin-Name: Error Log Dashboard Widget
	 * Plugin URI: http://wordpress.org/extend/plugins/error-log-dashboard-widget/
	 * Description: Robust zero-configuration and low-memory way to keep an eye on error log.
	 * Author: Andrey "Rarst" Savchenko
	 * Author URI: http://www.rarst.net/
	 * Version: 1.0.2
	 * License: GPLv2 or later
	 * Includes last_lines() function by phant0m, licensed under cc-wiki and GPLv2+
	 */
	private static function render_error_page() {
		?>
		<table id="mainwp-table" class="wp-list-table widefat" cellspacing="0">
			<thead title="Click to Toggle" style="cursor: pointer;">
				<tr>
					<th scope="col" class="manage-column column-posts" style="width: 10%"><?php esc_html_e( 'Time', 'mainwp-child' ); ?></th>
					<th scope="col" class="manage-column column-posts"><?php esc_html_e( 'Error', 'mainwp-child' ); ?></th>
				</tr>
			</thead>
			<tbody class="list:sites" id="mainwp-error-log-table">
				<?php self::render_error_log(); ?>
			</tbody>
		</table>
		<?php
	}

	/**
	 * Render the error log content.
	 *
	 * @uses MainWP_Child_Server_Information::render_last_lines() Render the error log last lines.
	 * @uses wp_kses_post() Sanitizes content for allowed HTML tags for post content.
	 * @see https://developer.wordpress.org/reference/functions/wp_kses_post/
	 *
	 * @used-by MainWP_Child_Server_Information::render_error_page() Render the Error log page.
	 *
	 * @uses \MainWP\Child\MainWP_Child_Branding::get_branding_title()
	 */
	public static function render_error_log() {
		$log_errors = ini_get( 'log_errors' );
		if ( ! $log_errors ) {
			echo '<tr><td colspan="2">' . esc_html__( 'Error logging disabled.', 'mainwp-child' ) . '</td></tr>';
		}

		$error_log = ini_get( 'error_log' );
		$logs      = apply_filters( 'error_log_mainwp_logs', array( $error_log ) );
		$count     = apply_filters( 'error_log_mainwp_lines', 10 );
		$lines     = array();

		foreach ( $logs as $log ) {
			if ( is_readable( $log ) ) {
				$lines = array_merge( $lines, self::render_last_lines( $log, $count ) );
			}
		}

		$lines = array_map( 'trim', $lines );
		$lines = array_filter( $lines );

		if ( empty( $lines ) ) {
			$branding_title = MainWP_Child_Branding::instance()->get_branding_title();
			if ( '' == $branding_title ) {
				$branding_title = 'MainWP';
			}
			$msg = esc_html( stripslashes( $branding_title ) ) . ' is unable to find your error logs, please contact your host for server error logs.';
			echo '<tr><td colspan="2">' . $msg . '</td></tr>';
			return;
		}

		foreach ( $lines as $key => $line ) {

			if ( false !== strpos( $line, ']' ) ) {
				list( $time, $error ) = explode( ']', $line, 2 );
			} else {
				list( $time, $error ) = array( '', $line );
			}

			$time          = trim( $time, '[]' );
			$error         = trim( $error );
			$lines[ $key ] = compact( 'time', 'error' );
		}

		if ( is_array( $error_log ) && count( $error_log ) > 1 ) {

			uasort( $lines, array( __CLASS__, 'time_compare' ) );
			$lines = array_slice( $lines, 0, $count );
		}

		foreach ( $lines as $line ) {

			$error = esc_html( $line['error'] );
			$time  = esc_html( $line['time'] );

			if ( ! empty( $error ) ) {
				echo wp_kses_post( "<tr><td>{$time}</td><td>{$error}</td></tr>" );
			}
		}
	}

	/**
	 * Render the error log last lines.
	 *
	 * @param string $path       Error log path.
	 * @param int    $line_count Line count.
	 * @param int    $block_size Block size.
	 *
	 * @return array Selected number of error log records to show.
	 */
	protected static function render_last_lines( $path, $line_count, $block_size = 512 ) {
		$lines = array();

		// we will always have a fragment of a non-complete line.
		// keep this in here till we have our next entire line.
		$leftover = '';

		// phpcs:disable WordPress.WP.AlternativeFunctions -- to custom read file.
		$fh = fopen( $path, 'r' );
		if ( false === $fh || ! is_resource( $fh ) ) {
			return $lines;
		}
		// go to the end of the file.
		fseek( $fh, 0, SEEK_END );

		$count_lines = 0;
		do {
			// need to know whether we can actually go back.
			$can_read = $block_size;

			if ( ftell( $fh ) <= $block_size ) {
				$can_read = ftell( $fh );
			}

			if ( empty( $can_read ) ) {
				break;
			}

			// go back as many bytes as we can.
			// read them to $data and then move the file pointer.
			// back to where we were.
			fseek( $fh, - $can_read, SEEK_CUR );
			$data  = fread( $fh, $can_read );
			$data .= $leftover;
			fseek( $fh, - $can_read, SEEK_CUR );

			// split lines by \n. Then reverse them, now the last line is most likely not a complete line which is why we do not directly add it, but append it to the data read the next time.
			$split_data  = array_reverse( explode( "\n", $data ) );
			$new_lines   = array_slice( $split_data, 0, - 1 );
			$lines       = array_merge( $lines, $new_lines );
			$leftover    = $split_data[ count( $split_data ) - 1 ];
			$count_lines = count( $lines );
		} while ( $count_lines < $line_count && 0 !== ftell( $fh ) );

		if ( 0 === ftell( $fh ) ) {
			$lines[] = $leftover;
		}

		fclose( $fh );

		// phpcs:enable

		// Usually, we will read too many lines, correct that here.
		return array_slice( $lines, 0, $line_count );
	}

	/**
	 * Render the connection details page content.
	 *
	 * @uses \MainWP\Child\MainWP_Child_Branding::get_branding_title()
	 */
	public static function render_connection_details() {
		$branding_title = MainWP_Child_Branding::instance()->get_branding_title();
		if ( '' == $branding_title ) {
			$branding_title = 'MainWP';
		}

		/**
		 * Current user global.
		 *
		 * @global string
		 */
		global $current_user;

		$uniqueId = MainWP_Helper::get_site_unique_id();
		$details  = array(
			'siteurl'       => array(
				'title' => esc_html__( 'Site URL', 'mainwp-child' ),
				'value' => get_bloginfo( 'url' ),
				'desc'  => get_bloginfo( 'url' ),
			),
			'adminuser'     => array(
				'title' => esc_html__( 'Administrator name', 'mainwp-child' ),
				'value' => $current_user->user_login,
				'desc'  => esc_html__( 'This is your Administrator username, however, you can use any existing Administrator username.', 'mainwp-child' ),
			),
			'friendly_name' => array(
				'title' => esc_html__( 'Friendly site name', 'mainwp-child' ),
				'value' => get_bloginfo( 'name' ),
				'desc'  => esc_html__( 'For the friendly site name, you can use any name, this is just a suggestion.', 'mainwp-child' ),
			),
			'uniqueid'      => array(
				'title' => esc_html__( 'Child unique security id', 'mainwp-child' ),
				'value' => ! empty( $uniqueId ) ? $uniqueId : esc_html__( 'Leave the field blank', 'mainwp-child' ),
				'desc'  => sprintf( esc_html__( 'Child unique security id is not required, however, since you have enabled it, you need to add it to your %s dashboard.', 'mainwp-child' ), stripslashes( $branding_title ) ),
			),
			'verify_ssl'    => array(
				'title' => esc_html__( 'Verify certificate', 'mainwp-child' ),
				'value' => esc_html__( 'Yes', 'mainwp-child' ),
				'desc'  => esc_html__( 'If there is an issue with SSL certificate on this site, try to set this option to No.', 'mainwp-child' ),
			),
			'ssl_version'   => array(
				'title' => esc_html__( 'SSL version', 'mainwp-child' ),
				'value' => esc_html__( 'Auto Detect', 'mainwp-child' ),
				'desc'  => esc_html__( 'Auto Detect', 'mainwp-child' ),
			),

		);
		?>
		<div class="postbox" id="connection_detail">
			<h3 class="mainwp_box_title"><span><?php esc_html_e( 'Connection details', 'mainwp-child' ); ?></span></h3>
			<div class="inside">
				<div class="mainwp-postbox-actions-top mainwp-padding-5">
					<?php echo sprintf( esc_html__( 'If you are trying to connect this child site to your %s Dashboard, you can use following details to do that. Please note that these are only suggested values.', 'mainwp-child' ), stripslashes( $branding_title ) ); ?>
				</div>
				<table id="mainwp-table" class="wp-list-table widefat" cellspacing="0" style="border: 0">
					<tbody>
						<?php
						foreach ( $details as $row ) {
							?>
							<tr>
								<th style="width: 20%"><strong><?php echo esc_html( $row['title'] ); ?></strong></th>
								<td style="width: 20%"><strong><?php echo esc_html( $row['value'] ); ?></strong></td>
								<td><?php echo esc_html( $row['desc'] ); ?></td>
							</tr>
							<?php
						}
						?>
					</tbody>
				</table>
			</div>
		</div>
		<?php
	}

}
