<?php
namespace MainWP\Child;

class MainWP_Child_Server_Information {
	const WARNING = 1;
	const ERROR   = 2;

	/**
	 * Method get_class_name()
	 *
	 * Get Class Name.
	 *
	 * @return object
	 */
	public static function get_class_name() {
		return __CLASS__;
	}

	public static function init() {
		add_action(
			'wp_ajax_mainwp-child_dismiss_warnings',
			array(
				self::get_class_name(),
				'dismiss_warnings',
			)
		);
	}

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

	public static function show_warnings() {
		if ( stristr( $_SERVER['REQUEST_URI'], 'mainwp_child_tab' ) || stristr( $_SERVER['REQUEST_URI'], 'mainwp-reports-page' ) || stristr( $_SERVER['REQUEST_URI'], 'mainwp-reports-settings' ) ) {
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

	public static function render_page() {
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
			jQuery( 'a.mwp-child-get-system-report-btn' ).live( 'click', function() {
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

			jQuery( 'a#mwp_child_close_srv_info' ).live( 'click', function() {
				jQuery( '#mwp-server-information' ).hide();
				jQuery( '.mwp_child_close_srv_info' ).hide();
				jQuery( 'a.mwp-child-get-system-report-btn' ).show();
				return false;
			} );
			jQuery( '#mwp_child_download_srv_info' ).live( 'click', function() {
				var server_info = jQuery( '#mwp-server-information textarea' ).val();
				var blob = new Blob( [server_info], {type: "text/plain;charset=utf-8"} );
				childSaveAs( blob, "server_child_information.txt" );
			} );
		</script>
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
					<a href="#" id="mwp_child_download_srv_info"><?php esc_html_e( 'Download', 'mainwp-child' ); ?></a> | <a href="#" id="mwp_child_close_srv_info"><i class="fa fa-eye-slash"></i> <?php _e( 'Hide', 'mainwp-child' ); ?></a>
				</span>
				<p class="submit">
					<a class="button-primary mwp-child-get-system-report-btn" href="#"><?php esc_html_e( 'Get system report', 'mainwp-child' ); ?></a>
				</p>
				<div id="mwp-server-information"><textarea readonly="readonly" wrap="off"></textarea></div>
			</div>
			<br/>
			<div class="mwp_server_info_box">
				<h2><?php esc_html_e( 'Server Information', 'mainwp-child' ); ?></h2>
				<?php self::render(); ?>
				<h2><?php esc_html_e( 'Cron Schedules', 'mainwp-child' ); ?></h2>
				<?php self::render_cron(); ?>
				<h2><?php esc_html_e( 'Error Log', 'mainwp-child' ); ?></h2>
				<?php self::render_error_log_page(); ?>
			</div>
		</div>
		<?php
	}

	public static function get_warnings() {
		$i = 0;
		if ( ! self::check( '>=', '3.4', 'get_wordpress_version' ) ) {
			$i ++;
		}
		if ( ! self::check( '>=', '5.2.4', 'get_php_version' ) ) {
			$i ++;
		}
		if ( ! self::check( '>=', '5.0', 'get_my_sql_version' ) ) {
			$i ++;
		}
		if ( ! self::check( '>=', '30', 'get_max_execution_time', '=', '0' ) ) {
			$i ++;
		}
		if ( ! self::check( '>=', '2M', 'get_upload_max_filesize', null, null, true ) ) {
			$i ++;
		}
		if ( ! self::check( '>=', '2M', 'get_post_max_size', null, null, true ) ) {
			$i ++;
		}
		if ( ! self::check( '>=', '10000', 'get_output_buffer_size' ) ) {
			$i ++;
		}
		if ( ! self::check_directory_mainwp_directory( false ) ) {
			$i ++;
		}

		return $i;
	}

	protected static function get_file_system_method() {
		if ( defined( 'MAINWP_SAVE_FS_METHOD' ) ) {
			return MAINWP_SAVE_FS_METHOD;
		}
		$fs = get_filesystem_method();

		return $fs;
	}

	protected static function get_file_system_method_check() {
		$fsmethod = self::get_file_system_method();
		if ( 'direct' === $fsmethod ) {
			echo '<span class="mainwp-pass"><i class="fa fa-check-circle"></i> Pass</span>';
		} else {
			echo '<span class="mainwp-warning"><i class="fa fa-exclamation-circle"></i> Warning</span>';
		}
	}

	public static function render() {
		$branding_title = MainWP_Child_Branding::instance()->get_branding_title();
		$isBranding     = true;
		if ( '' == $branding_title ) {
			$branding_title = 'MainWP Child';
			$isBranding     = false;
		}
		?>

		<table id="mainwp-table" class="wp-list-table widefat" cellspacing="0">
			<thead>
				<tr>
					<th scope="col" class="manage-column column-posts mwp-not-generate-row" style="width: 1px;"></th>
					<th scope="col" class="manage-column column-posts" style=""><span><?php esc_html_e( 'Server configuration', 'mainwp-child' ); ?></span></th>
					<th scope="col" class="manage-column column-posts" style=""><?php esc_html_e( 'Required value', 'mainwp-child' ); ?></th>
					<th scope="col" class="manage-column column-posts" style=""><?php esc_html_e( 'Value', 'mainwp-child' ); ?></th>
					<th scope="col" class="manage-column column-posts" style=""><?php esc_html_e( 'Status', 'mainwp-child' ); ?></th>
				</tr>
			</thead>
			<tbody id="the-sites-list" class="list:sites">
				<tr>
					<td style="background: #333; color: #fff;" colspan="5"><?php echo esc_html( strtoupper( stripslashes( $branding_title ) ) ); ?></td>
				</tr>
				<tr>
					<td></td>
					<td><?php echo esc_html( stripslashes( $branding_title ) ) . ' ' . __( 'Version', 'mainwp-chil' ); ?></td>
					<td><?php echo esc_html( self::get_mainwp_version() ); ?></td>
					<td><?php echo esc_html( self::get_current_version() ); ?></td>
					<td><?php echo esc_html( self::get_mainwp_version_check() ); ?></td>
				</tr>
				<?php self::check_directory_mainwp_directory(); ?>
				<?php $server = get_option( 'mainwp_child_server' ); ?>
				<tr>
					<td></td>
					<td><?php esc_html_e( 'Currently connected to dashboard URL', 'mainwp-child' ); ?></td>
					<td><?php echo esc_html( $server ); ?></td>
					<td></td>
					<td></td>
				</tr>
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
					<td><?php echo esc_html( self::get_file_system_method_check() ); ?></td>
				</tr>
				<tr>
					<td style="background: #333; color: #fff;"
						colspan="5"><?php esc_html_e( 'PHP SETTINGS', 'mainwp-child' ); ?></td>
				</tr>
				<?php self::render_row( 'PHP Version', '>=', '5.6', 'get_php_version' ); ?>
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
					self::render_row_sec(
						'cURL SSL Version',
						'>=',
						array(
							'version_number' => 0x009080cf,
							'version'        => 'OpenSSL/0.9.8l',
						),
						'get_curl_ssl_version',
						'',
						'',
						null,
						'',
						'curlssl'
					);
				}
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
				<tr>
					<td style="background: #333; color: #fff;" colspan="5"><?php _e( 'WordPress PLUGINS', 'mainwp-child' ); ?></td>
				</tr>
				<?php
				$all_plugins = get_plugins();
				foreach ( $all_plugins as $slug => $plugin ) {
					if ( 'mainwp-child/mainwp-child.php' == $slug || 'mainwp-child-reports/mainwp-child-reports.php' == $slug ) {
						if ( $isBranding ) {
							if ( 'mainwp-child/mainwp-child.php' == $slug ) {
								$plugin['Name'] = esc_html( stripslashes( $branding_title ) );
							} elseif ( 'mainwp-child-reports/mainwp-child-reports.php' == $slug ) {
								$plugin['Name'] = esc_html( stripslashes( $branding_title ) ) . ' reports';
							}
						}
					}
					?>
					<tr>
						<td></td>
						<td><?php echo esc_html( $plugin['Name'] ); ?></td>
						<td><?php echo esc_html( $plugin['Version'] ); ?></td>
						<td><?php echo is_plugin_active( $slug ) ? __( 'Active', 'mainwp-child' ) : __( 'Inactive', 'mainwp-child' ); ?></td>
						<td></td>
					</tr>
					<?php
				}
				?>
			</tbody>
		</table>
		<br/>
		<?php
	}

	protected static function get_curl_support() {
		return function_exists( 'curl_version' );
	}

	protected static function get_curl_timeout() {
		return ini_get( 'default_socket_timeout' );
	}

	protected static function get_curl_version() {
		$curlversion = curl_version();

		return $curlversion['version'];
	}

	protected static function curlssl_compare( $value, $operator = null ) {
		if ( isset( $value['version_number'] ) && defined( 'OPENSSL_VERSION_NUMBER' ) ) {
			return version_compare( OPENSSL_VERSION_NUMBER, $value['version_number'], $operator );
		}

		return false;
	}

	protected static function get_curl_ssl_version() {
		$curlversion = curl_version();

		return $curlversion['ssl_version'];
	}

	public static function mainwp_required_functions() {
		$disabled_functions = ini_get( 'disable_functions' );
		if ( '' !== $disabled_functions ) {
			$arr = explode( ',', $disabled_functions );
			sort( $arr );
			$arr_length = count( $arr );
			for ( $i = 0; $i < $arr_length; $i ++ ) {
				echo esc_html( $arr[ $i ] . ', ' );
			}
		} else {
			echo esc_html__( 'No functions disabled', 'mainwp-child' );
		}
	}

	protected static function get_loaded_php_extensions() {
		$extensions = get_loaded_extensions();
		sort( $extensions );
		echo esc_html( implode( ', ', $extensions ) );
	}

	protected static function get_current_version() {
		$currentVersion = get_option( 'mainwp_child_plugin_version' );

		return $currentVersion;
	}

	protected static function get_mainwp_version() {
		include_once ABSPATH . '/wp-admin/includes/plugin-install.php';
		$api = plugins_api(
			'plugin_information',
			array(
				'slug'    => 'mainwp-child',
				'fields'  => array( 'sections' => false ),
				'timeout' => 60,
			)
		);
		if ( is_object( $api ) && isset( $api->version ) ) {
			return $api->version;
		}

		return false;
	}

	protected static function get_mainwp_version_check() {
		$current = get_option( 'mainwp_child_plugin_version' );
		$latest  = self::get_mainwp_version();
		if ( $current === $latest ) {
			echo '<span class="mainwp-pass"><i class="fa fa-check-circle"></i> Pass</span>';
		} else {
			echo '<span class="mainwp-warning"><i class="fa fa-exclamation-circle"></i> Warning</span>';
		}
	}

	public static function render_cron() {
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

	protected static function check_directory_mainwp_directory( $write = true ) {
		$branding_title = MainWP_Child_Branding::instance()->get_branding_title();
		if ( '' == $branding_title ) {
			$branding_title = 'MainWP';
		}

		$branding_title .= ' Upload Directory';

		try {
			$dirs = MainWP_Helper::get_mainwp_dir( null, false );
			$path = $dirs[0];
		} catch ( \Exception $e ) {
			return self::render_directory_row( $branding_title, '', 'Writable', $e->getMessage(), false );
		}

		if ( ! is_dir( dirname( $path ) ) ) {
			if ( $write ) {
				return self::render_directory_row( $branding_title, $path, 'Writable', 'Directory not found', false );
			} else {
				return false;
			}
		}

		$hasWPFileSystem = MainWP_Helper::get_wp_filesystem();

		global $wp_filesystem;

		if ( $hasWPFileSystem && ! empty( $wp_filesystem ) ) {
			if ( ! $wp_filesystem->is_writable( $path ) ) {
				if ( $write ) {
					return self::render_directory_row( $branding_title, $path, 'Writable', 'Directory not writable', false );
				} else {
					return false;
				}
			}
		} else {
			if ( ! is_writable( $path ) ) {
				if ( $write ) {
					return self::render_directory_row( $branding_title, $path, 'Writable', 'Directory not writable', false );
				} else {
					return false;
				}
			}
		}

		if ( $write ) {
			return self::render_directory_row( $branding_title, $path, 'Writable', 'Writable', true );
		} else {
			return true;
		}
	}

	protected static function render_directory_row( $pName, $pDirectory, $pCheck, $pResult, $pPassed ) {
		?>
		<tr class="mwp-not-generate-row">
			<td></td>
			<td><?php echo esc_html( stripslashes( $pName ) ); ?><br/><?php echo esc_html( ( MainWP_Child_Branding::instance()->is_branding() ) ? '' : $pDirectory ); ?></td>
			<td><?php echo esc_html( $pCheck ); ?></td>
			<td><?php echo esc_html( $pResult ); ?></td>
			<td><?php echo ( $pPassed ? '<span class="mainwp-pass"><i class="fa fa-check-circle"></i> Pass</span>' : '<span class="mainwp-warning"><i class="fa fa-exclamation-circle"></i> Warning</span>' ); ?></td>
		</tr>
		<?php
		return true;
	}

	protected static function render_row( $pConfig, $pCompare, $pVersion, $pGetter, $pExtraText = '', $pExtraCompare = null, $pExtraVersion = null, $sizeCompare = false ) {
		$currentVersion = call_user_func( array( self::get_class_name(), $pGetter ) );

		?>
		<tr>
			<td></td>
			<td><?php echo esc_html( esc_html( $pConfig ) ); ?></td>
			<td><?php echo esc_html( esc_html( $pCompare ) ); ?><?php echo esc_html( ( true === $pVersion ? 'true' : $pVersion ) . ' ' . $pExtraText ); ?></td>
			<td><?php echo esc_html( true === $currentVersion ? 'true' : $currentVersion ); ?></td>
			<td><?php echo ( self::check( $pCompare, $pVersion, $pGetter, $pExtraCompare, $pExtraVersion, $sizeCompare ) ? '<span class="mainwp-pass"><i class="fa fa-check-circle"></i> Pass</span>' : '<span class="mainwp-warning"><i class="fa fa-exclamation-circle"></i> Warning</span>' ); ?></td>
		</tr>
		<?php
	}

	protected static function render_row_sec( $pConfig, $pCompare, $pVersion, $pGetter, $pExtraText = '', $pExtraCompare = null, $pExtraVersion = null, $toolTip = null, $whatType = null, $errorType = self::WARNING ) {
		$currentVersion = call_user_func( array( self::get_class_name(), $pGetter ) );
		?>
		<tr>
			<td></td>
			<td><?php echo $pConfig; ?></td>
			<td><?php echo $pCompare; ?><?php echo ( true === $pVersion ? 'true' : ( is_array( $pVersion ) && isset( $pVersion['version'] ) ? $pVersion['version'] : $pVersion ) ) . ' ' . $pExtraText; ?></td>
			<td><?php echo( true === $currentVersion ? 'true' : $currentVersion ); ?></td>
			<?php if ( 'filesize' === $whatType ) { ?>
				<td><?php echo( self::filesize_compare( $currentVersion, $pVersion, $pCompare ) ? '<span class="mainwp-pass"><i class="fa fa-check-circle"></i> Pass</span>' : self::get_warning_html( $errorType ) ); ?></td>
			<?php } elseif ( 'curlssl' === $whatType ) { ?>
				<td><?php echo( self::curlssl_compare( $pVersion, $pCompare ) ? '<span class="mainwp-pass"><i class="fa fa-check-circle"></i> Pass</span>' : self::get_warning_html( $errorType ) ); ?></td>
			<?php } elseif ( ( 'get_max_input_time' === $pGetter || 'get_max_execution_time' === $pGetter ) && -1 == $currentVersion ) { ?>
				<td><?php echo '<span class="mainwp-pass"><i class="fa fa-check-circle"></i> Pass</span>'; ?></td>
			<?php } else { ?>
				<td><?php echo ( version_compare( $currentVersion, $pVersion, $pCompare ) || ( ( null != $pExtraCompare ) && version_compare( $currentVersion, $pExtraVersion, $pExtraCompare ) ) ? '<span class="mainwp-pass"><i class="fa fa-check-circle"></i> Pass</span>' : self::get_warning_html( $errorType ) ); ?></td>
			<?php } ?>
		</tr>
		<?php
	}

	private static function get_warning_html( $errorType = self::WARNING ) {
		if ( self::WARNING == $errorType ) {
			return '<span class="mainwp-warning"><i class="fa fa-exclamation-circle"></i> Warning</span>';
		}
		return '<span class="mainwp-fail"><i class="fa fa-exclamation-circle"></i> Fail</span>';
	}

	protected static function filesize_compare( $value1, $value2, $operator = null ) {
		if ( false !== strpos( $value1, 'G' ) ) {
			$value1 = preg_replace( '/[A-Za-z]/', '', $value1 );
			$value1 = intval( $value1 ) * 1024;
		} else {
			$value1 = preg_replace( '/[A-Za-z]/', '', $value1 );
		}

		if ( false !== strpos( $value2, 'G' ) ) {
			$value2 = preg_replace( '/[A-Za-z]/', '', $value2 );
			$value2 = intval( $value2 ) * 1024;
		} else {
			$value2 = preg_replace( '/[A-Za-z]/', '', $value2 );
		}

		return version_compare( $value1, $value2, $operator );
	}

	protected static function check( $pCompare, $pVersion, $pGetter, $pExtraCompare = null, $pExtraVersion = null, $sizeCompare = false ) {
		$currentVersion = call_user_func( array( self::get_class_name(), $pGetter ) );

		if ( $sizeCompare ) {
			return self::filesize_compare( $currentVersion, $pVersion, $pCompare );
		} else {
			return ( version_compare( $currentVersion, $pVersion, $pCompare ) || ( ( null !== $pExtraCompare ) && version_compare( $currentVersion, $pExtraVersion, $pExtraCompare ) ) );
		}
	}

	protected static function get_zip_archive_enabled() {
		return class_exists( 'ZipArchive' );
	}

	protected static function get_gzip_enabled() {
		return function_exists( 'gzopen' );
	}

	protected static function get_bzip_enabled() {
		return function_exists( 'bzopen' );
	}

	protected static function get_wordpress_version() {
		global $wp_version;

		return $wp_version;
	}

	protected static function get_wordpress_memory_limit() {
		return WP_MEMORY_LIMIT;
	}

	public static function check_if_multisite() {
		$isMultisite = ! is_multisite() ? true : false;

		return $isMultisite;
	}

	protected static function get_ssl_support() {
		return extension_loaded( 'openssl' );
	}

	protected static function get_ssl_warning() {
		$conf = array( 'private_key_bits' => 2048 );
		$str  = '';
		if ( function_exists( 'openssl_pkey_new' ) ) {
			$res = openssl_pkey_new( $conf );
			openssl_pkey_export( $res, $privkey );

			$str = openssl_error_string();
		}
		return ( stristr( $str, 'NCONF_get_string:no value' ) ? '' : $str );
	}

	public static function get_php_version() {
		return phpversion();
	}

	protected static function get_max_execution_time() {
		return ini_get( 'max_execution_time' );
	}

	protected static function get_upload_max_filesize() {
		return ini_get( 'upload_max_filesize' );
	}

	protected static function get_post_max_size() {
		return ini_get( 'post_max_size' );
	}

	public static function get_my_sql_version() {
		/** @var $wpdb wpdb */
		global $wpdb;

		return $wpdb->get_var( "SHOW VARIABLES LIKE 'version'", 1 );
	}

	protected static function get_max_input_time() {
		return ini_get( 'max_input_time' );
	}

	public static function get_php_memory_limit() {
		return ini_get( 'memory_limit' );
	}

	protected static function get_os() {
		echo esc_html( PHP_OS );
	}

	protected static function get_architecture() {
		echo esc_html( PHP_INT_SIZE * 8 ) . ' bit';
	}

	protected static function memory_usage() {
		if ( function_exists( 'memory_get_usage' ) ) {
			$memory_usage = round( memory_get_usage() / 1024 / 1024, 2 ) . ' MB';
		} else {
			$memory_usage = __( 'N/A', 'mainwp-child' );
		}
		echo esc_html( $memory_usage );
	}

	protected static function get_output_buffer_size() {
		return ini_get( 'pcre.backtrack_limit' );
	}

	protected static function get_php_safe_mode() {
		if ( version_compare( phpversion(), '5.3.0' ) < 0 && ini_get( 'safe_mode' ) ) {
			$safe_mode = __( 'ON', 'mainwp-child' );
		} else {
			$safe_mode = __( 'OFF', 'mainwp-child' );
		}
		echo esc_html( $safe_mode );
	}

	protected static function get_sql_mode() {
		global $wpdb;
		$mysqlinfo = $wpdb->get_results( "SHOW VARIABLES LIKE 'sql_mode'" );
		if ( is_array( $mysqlinfo ) ) {
			$sql_mode = $mysqlinfo[0]->Value;
		}
		if ( empty( $sql_mode ) ) {
			$sql_mode = __( 'NOT SET', 'mainwp-child' );
		}
		echo esc_html( $sql_mode );
	}

	protected static function get_php_allow_url_fopen() {
		if ( ini_get( 'allow_url_fopen' ) ) {
			$allow_url_fopen = __( 'ON', 'mainwp-child' );
		} else {
			$allow_url_fopen = __( 'OFF', 'mainwp-child' );
		}
		echo esc_html( $allow_url_fopen );
	}

	protected static function get_php_exif() {
		if ( is_callable( 'exif_read_data' ) ) {
			$exif = __( 'YES', 'mainwp-child' ) . ' ( V' . substr( phpversion( 'exif' ), 0, 4 ) . ')';
		} else {
			$exif = __( 'NO', 'mainwp-child' );
		}
		echo esc_html( $exif );
	}

	protected static function get_php_ip_tc() {
		if ( is_callable( 'iptcparse' ) ) {
			$iptc = __( 'YES', 'mainwp-child' );
		} else {
			$iptc = __( 'NO', 'mainwp-child' );
		}
		echo esc_html( $iptc );
	}

	protected static function get_php_xml() {
		if ( is_callable( 'xml_parser_create' ) ) {
			$xml = __( 'YES', 'mainwp-child' );
		} else {
			$xml = __( 'NO', 'mainwp-child' );
		}
		echo esc_html( $xml );
	}

	protected static function get_server_getaway_interface() {
		$gate = isset( $_SERVER['GATEWAY_INTERFACE'] ) ? $_SERVER['GATEWAY_INTERFACE'] : '';
		echo esc_html( $gate );
	}

	public static function get_server_ip() {
		echo esc_html( $_SERVER['SERVER_ADDR'] );
	}

	protected static function get_server_name() {
		echo esc_html( $_SERVER['SERVER_NAME'] );
	}

	protected static function get_server_software() {
		echo esc_html( $_SERVER['SERVER_SOFTWARE'] );
	}

	protected static function get_server_protocol() {
		echo esc_html( $_SERVER['SERVER_PROTOCOL'] );
	}

	protected static function get_server_request_time() {
		echo esc_html( $_SERVER['REQUEST_TIME'] );
	}

	protected static function get_server_http_accept() {
		echo esc_html( $_SERVER['HTTP_ACCEPT'] );
	}

	protected static function get_server_accept_charset() {
		if ( ! isset( $_SERVER['HTTP_ACCEPT_CHARSET'] ) || ( '' === $_SERVER['HTTP_ACCEPT_CHARSET'] ) ) {
			esc_html_e( 'N/A', 'mainwp-child' );
		} else {
			echo esc_html( $_SERVER['HTTP_ACCEPT_CHARSET'] );
		}
	}

	protected static function get_http_host() {
		echo esc_html( $_SERVER['HTTP_HOST'] );
	}

	protected static function get_complete_url() {
		echo isset( $_SERVER['HTTP_REFERER'] ) ? esc_html( $_SERVER['HTTP_REFERER'] ) : '';
	}

	protected static function get_user_agent() {
		echo esc_html( $_SERVER['HTTP_USER_AGENT'] );
	}

	protected static function get_https() {
		if ( isset( $_SERVER['HTTPS'] ) && '' !== $_SERVER['HTTPS'] ) {
			echo esc_html( __( 'ON', 'mainwp-child' ) . ' - ' . $_SERVER['HTTPS'] );
		} else {
			esc_html_e( 'OFF', 'mainwp-child' );
		}
	}

	protected static function server_self_connect() {
		$url         = site_url( 'wp-cron.php' );
		$query_args  = array( 'mainwp_child_run' => 'test' );
		$url         = add_query_arg( $query_args, $url );
		$args        = array(
			'blocking'        => true,
			'sslverify'       => apply_filters( 'https_local_ssl_verify', true ),
			'timeout'         => 15,
		);
		$response    = wp_remote_post( $url, $args );
		$test_result = '';
		if ( is_wp_error( $response ) ) {
			$test_result .= sprintf( __( 'The HTTP response test get an error "%s"', 'mainwp-child' ), $response->get_error_message() );
		}
		$response_code = wp_remote_retrieve_response_code( $response );
		if ( $response_code < 200 && $response_code > 204 ) {
			$test_result .= sprintf( __( 'The HTTP response test get a false http status (%s)', 'mainwp-child' ), wp_remote_retrieve_response_code( $response ) );
		} else {
			$response_body = wp_remote_retrieve_body( $response );
			if ( false === strstr( $response_body, 'MainWP Test' ) ) {
				$test_result .= sprintf( __( 'Not expected HTTP response body: %s', 'mainwp-child' ), esc_attr( wp_strip_all_tags( $response_body ) ) );
			}
		}
		if ( empty( $test_result ) ) {
			_e( 'Response Test O.K.', 'mainwp-child' );
		} else {
			echo $test_result;
		}
	}


	protected static function get_remote_address() {
		echo esc_html( $_SERVER['REMOTE_ADDR'] );
	}

	protected static function get_remote_host() {
		if ( ! isset( $_SERVER['REMOTE_HOST'] ) || ( '' === $_SERVER['REMOTE_HOST'] ) ) {
			esc_html_e( 'N/A', 'mainwp-child' );
		} else {
			echo esc_html( $_SERVER['REMOTE_HOST'] );
		}
	}

	protected static function get_remote_port() {
		echo esc_html( $_SERVER['REMOTE_PORT'] );
	}

	protected static function get_script_file_name() {
		echo esc_html( $_SERVER['SCRIPT_FILENAME'] );
	}

	protected static function get_server_port() {
		echo esc_html( $_SERVER['SERVER_PORT'] );
	}

	protected static function get_current_page_uri() {
		echo esc_html( $_SERVER['REQUEST_URI'] );
	}

	protected static function get_wp_root() {
		echo esc_html( ABSPATH );
	}

	/*
	*Plugin-Name: Error Log Dashboard Widget
	*Plugin URI: http://wordpress.org/extend/plugins/error-log-dashboard-widget/
	*Description: Robust zero-configuration and low-memory way to keep an eye on error log.
	*Author: Andrey "Rarst" Savchenko
	*Author URI: http://www.rarst.net/
	*Version: 1.0.2
	*License: GPLv2 or later

	*Includes last_lines() function by phant0m, licensed under cc-wiki and GPLv2+
	*/

	public static function render_error_log_page() {
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
				$lines = array_merge( $lines, self::last_lines( $log, $count ) );
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

	public static function time_compare( $a, $b ) {
		if ( $a === $b ) {
			return 0;
		}

		return ( strtotime( $a['time'] ) > strtotime( $b['time'] ) ) ? - 1 : 1;
	}

	public static function last_lines( $path, $line_count, $block_size = 512 ) {
		$lines = array();

		// we will always have a fragment of a non-complete line.
		// keep this in here till we have our next entire line.
		$leftover = '';

		// phpcs:disable WordPress.WP.AlternativeFunctions -- to custom read file.
		$fh = fopen( $path, 'r' );
		// go to the end of the file.
		fseek( $fh, 0, SEEK_END );

		$count_lines = count( $lines );
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
			$split_data = array_reverse( explode( "\n", $data ) );
			$new_lines  = array_slice( $split_data, 0, - 1 );
			$lines      = array_merge( $lines, $new_lines );
			$leftover   = $split_data[ count( $split_data ) - 1 ];
		} while ( $count_lines < $line_count && 0 !== ftell( $fh ) );

		if ( 0 === ftell( $fh ) ) {
			$lines[] = $leftover;
		}

		fclose( $fh );

		// phpcs:enable

		// Usually, we will read too many lines, correct that here.
		return array_slice( $lines, 0, $line_count );
	}

	public static function render_wp_config() {
		?>
		<div class="postbox" id="mainwp-code-display">
			<h3 class="hndle" style="padding: 8px 12px; font-size: 14px;"><span>WP-Config.php</span></h3>
			<div style="padding: 1em;">
				<?php
				if ( file_exists( ABSPATH . 'wp-config.php' ) ) {
					show_source( ABSPATH . 'wp-config.php' );
				} else {
					$files       = get_included_files();
					$configFound = false;
					if ( is_array( $files ) ) {
						foreach ( $files as $file ) {
							if ( stristr( $file, 'wp-config.php' ) ) {
								$configFound = true;
								show_source( $file );
								break;
							}
						}
					}

					if ( ! $configFound ) {
						_e( 'wp-config.php not found', 'mainwp' );
					}
				}
				?>
			</div>
		</div>
		<?php
	}

	public static function renderhtaccess() {
		?>
		<div class="postbox" id="mainwp-code-display">
			<h3 class="hndle" style="padding: 8px 12px; font-size: 14px;"><span><?php _e( '.htaccess', 'mainwp-child' ); ?></span></h3>
			<div style="padding: 1em;"><?php show_source( ABSPATH . '.htaccess' ); ?></div>
		</div>
		<?php
	}

	public static function render_connection_details() {
		$branding_title = MainWP_Child_Branding::instance()->get_branding_title();
		if ( '' == $branding_title ) {
			$branding_title = 'MainWP';
		}

		global $current_user;
		$uniqueId = get_option( 'mainwp_child_uniqueId' );
		$details  = array(
			'siteurl' => array(
				'title' => __( 'Site URL', 'mainwp-child' ),
				'value' => get_bloginfo( 'url' ),
				'desc'  => get_bloginfo( 'url' ),
			),
			'adminuser' => array(
				'title' => __( 'Administrator name', 'mainwp-child' ),
				'value' => $current_user->user_login,
				'desc'  => __( 'This is your Administrator username, however, you can use any existing Administrator username.', 'mainwp-child' ),
			),
			'friendly_name' => array(
				'title' => __( 'Friendly site name', 'mainwp-child' ),
				'value' => get_bloginfo( 'name' ),
				'desc'  => __( 'For the friendly site name, you can use any name, this is just a suggestion.', 'mainwp-child' ),
			),
			'uniqueid' => array(
				'title' => __( 'Child unique security id', 'mainwp-child' ),
				'value' => ! empty( $uniqueId ) ? $uniqueId : __( 'Leave the field blank', 'mainwp-child' ),
				'desc'  => sprintf( __( 'Child unique security id is not required, however, since you have enabled it, you need to add it to your %s dashboard.', 'mainwp-child' ), stripslashes( $branding_title ) ),
			),
			'verify_ssl' => array(
				'title' => __( 'Verify certificate', 'mainwp-child' ),
				'value' => __( 'Yes', 'mainwp-child' ),
				'desc'  => __( 'If there is an issue with SSL certificate on this site, try to set this option to No.', 'mainwp-child' ),
			),
			'ssl_version' => array(
				'title' => __( 'SSL version', 'mainwp-child' ),
				'value' => __( 'Auto Detect', 'mainwp-child' ),
				'desc'  => __( 'Auto Detect', 'mainwp-child' ),
			),

		);
		?>
		<div class="postbox" id="connection_detail">
			<h3 class="mainwp_box_title"><span><?php _e( 'Connection details', 'mainwp-child' ); ?></span></h3>
			<div class="inside">
				<div class="mainwp-postbox-actions-top mainwp-padding-5">
					<?php echo sprintf( __( 'If you are trying to connect this child site to your %s Dashboard, you can use following details to do that. Please note that these are only suggested values.', 'mainwp-child' ), stripslashes( $branding_title ) ); ?>
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
