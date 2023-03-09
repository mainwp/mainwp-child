<?php
/**
 * MainWP Clone Page.
 *
 * MainWP Clone extension handler.
 *
 * @link https://mainwp.com/extension/clone/
 *
 * @package MainWP\Child
 */

namespace MainWP\Child;

/**
 * Class MainWP_Clone_Page
 *
 * MainWP Clone extension handler.
 */
class MainWP_Clone_Page {

	/**
	 * Public static variable to hold the single instance of the class.
	 *
	 * @var mixed Default null
	 */
	protected static $instance = null;

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
	 * Create a public static instance of MainWP_Clone_Page.
	 *
	 * @return MainWP_Clone_Page|null
	 */
	public static function get() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Method print_scripts()
	 *
	 * @uses \MainWP\Child\MainWP_Helper::starts_with()
	 */
	public static function print_scripts() {
		wp_enqueue_script( 'jquery-ui-tooltip' );
		wp_enqueue_script( 'jquery-ui-autocomplete' );
		wp_enqueue_script( 'jquery-ui-progressbar' );
		wp_enqueue_script( 'jquery-ui-dialog' );

		/**
		 * WordPress Core class used to register scripts.
		 *
		 * @global object $wp_scripts WordPress Core class used to register scripts.
		 */
		global $wp_scripts;

		$ui      = $wp_scripts->query( 'jquery-ui-core' );
		$version = $ui->ver;
		if ( MainWP_Helper::starts_with( $version, '1.10' ) ) {
			wp_enqueue_style( 'jquery-ui-style', plugins_url( '/css/1.10.4/jquery-ui.min.css', dirname( __FILE__ ) ), array(), '1.10', 'all' );
		} else {
			wp_enqueue_style( 'jquery-ui-style', plugins_url( '/css/1.11.1/jquery-ui.min.css', dirname( __FILE__ ) ), array(), '1.11', 'all' );
		}
	}


	/**
	 * Render Clone page.
	 *
	 * @uses \MainWP\Child\MainWP_Clone::get_class_name()
	 * @uses \MainWP\Child\MainWP_Helper::return_bytes()
	 * @uses \MainWP\Child\MainWP_Helper::human_filesize()
	 * @uses \MainWP\Child\MainWP_Helper::get_wp_filesystem()
	 */
	public static function render() {
		$uploadError = false;
		$uploadFile  = false;
		if ( isset( $_REQUEST['_nonce'] ) && wp_verify_nonce( sanitize_text_field( wp_unslash( $_REQUEST['_nonce'] ) ), 'cloneRestore' ) ) {
			if ( isset( $_FILES['file'] ) ) {
				if ( ! function_exists( 'wp_handle_upload' ) ) {
					require_once ABSPATH . 'wp-admin/includes/file.php';
				}
				$uploadedfile     = isset( $_FILES['file'] ) ? wp_unslash( $_FILES['file'] ) : '';
				$upload_overrides = array( 'test_form' => false );
				add_filter( 'upload_mimes', array( MainWP_Clone::get_class_name(), 'upload_mimes' ) );
				$movefile = wp_handle_upload( $uploadedfile, $upload_overrides );
				if ( $movefile ) {
					$uploadFile = str_replace( ABSPATH, '', $movefile['file'] );
				} else {
					$uploadError = esc_html__( 'File could not be uploaded.', 'mainwp-child' );
				}
			} else {
				$uploadError = esc_html__( 'File is empty. Please upload something more substantial. This error could also be caused by uploads being disabled in your php.ini or by post_max_size being defined as smaller than upload_max_filesize in php.ini.', 'mainwp-child' );
			}
		}

		$sitesToClone      = get_option( 'mainwp_child_clone_sites' );
		$uploadSizeInBytes = min( MainWP_Helper::return_bytes( ini_get( 'upload_max_filesize' ) ), MainWP_Helper::return_bytes( ini_get( 'post_max_size' ) ) );
		$uploadSize        = MainWP_Helper::human_filesize( $uploadSizeInBytes );

		self::render_style();

		if ( '0' === $sitesToClone ) {
			echo '<div class="mainwp-child_info-box-red"><strong>' . esc_html__( 'Cloning is currently off - To turn on return to your main dashboard and turn cloning on on the Clone page.', 'mainwp-child' ) . '</strong></div>';
			return;
		}
		$error = false;
		MainWP_Helper::get_wp_filesystem();

		/**
		 * Global variable containing the instance of the (auto-)configured filesystem object after the filesystem "factory" has been run.
		 *
		 * @global object $wp_filesystem Filesystem object.
		 */
		global $wp_filesystem;

		if ( ( ! empty( $wp_filesystem ) && ! $wp_filesystem->is_writable( WP_CONTENT_DIR ) ) || ( empty( $wp_filesystem ) && ! is_writable( WP_CONTENT_DIR ) ) ) {
			echo '<div class="mainwp-child_info-box-red"><strong>' . esc_html__( 'Your content directory is not writable. Please set 0755 permission to ', 'mainwp-child' ) . esc_html( basename( WP_CONTENT_DIR ) ) . '. (' . esc_html( WP_CONTENT_DIR ) . ')</strong></div>';
			$error = true;
		}
		?>
		<div class="mainwp-child_info-box-green" style="display: none;">
			<?php esc_html_e( 'Cloning process completed successfully! You will now need to click ', 'mainwp-child' ); ?>
			<a href="<?php echo esc_url( admin_url( 'options-permalink.php' ) ); ?>"><?php esc_html_e( 'here', 'mainwp-child' ); ?></a>
			<?php esc_html_e( ' to re-login to the admin and re-save permalinks.', 'mainwp-child' ); ?>
		</div>

		<?php
		self::render_form( $sitesToClone, $uploadFile, $uploadSize, $error, $uploadError );
		self::render_clone_from_server();
		self::render_java_script();
	}

	/**
	 * Render clone form.
	 *
	 * @param array  $sitesToClone Sites that may be cloned.
	 * @param mixed  $uploadFile Path to File.
	 * @param string $uploadSize Size of upload.
	 * @param bool   $error true|false.
	 * @param string $uploadError Upload error message.
	 *
	 * @uses \MainWP\Child\MainWP_Helper::rand_string()
	 * @uses \MainWP\Child\MainWP_Helper::get_nice_url()
	 */
	private static function render_form( $sitesToClone, $uploadFile, $uploadSize, $error, $uploadError ) {

		if ( ! empty( $uploadFile ) ) {
			esc_html_e( 'Upload successful.', 'mainwp-child' );
			?>
			<a href="#" id="mainwp-child_uploadclonebutton" class="button-primary" file="<?php echo esc_attr( $uploadFile ); ?>"><?php esc_html_e( 'Clone/Restore website', 'mainwp-child' ); ?></a>
			<?php
			return;
		}

		if ( $uploadError ) {
			?>
			<div class="mainwp-child_info-box-red"><?php echo esc_html( $uploadError ); ?></div>
			<?php
		}

		if ( empty( $sitesToClone ) ) {
			echo '<div class="mainwp-child_info-box-yellow"><strong>' . esc_html__( 'Cloning is currently on but no sites have been allowed, to allow sites return to your main dashboard and turn cloning on on the Clone page.', 'mainwp-child' ) . '</strong></div>';
		} else {
			?>
			<form method="post" action="">
				<div class="mainwp-child_select_sites_box">
					<div class="postbox">
						<div class="mainwp-child_displayby"><?php esc_html_e( 'Display by:', 'mainwp-child' ); ?> <a class="mainwp-child_action left mainwp-child_action_down" href="#" id="mainwp-child_displayby_sitename"><?php esc_html_e( 'Site Name', 'mainwp-child' ); ?></a><a class="mainwp-child_action right" href="#" id="mainwp-child_displayby_url"><?php esc_html_e( 'URL', 'mainwp-child' ); ?></a></div>
						<h2 class="hndle"><?php esc_html_e( 'Select Source for clone', 'mainwp-child' ); ?></h2>
						<div class="inside">
							<div id="mainwp-child_clonesite_select_site">
								<?php
								foreach ( $sitesToClone as $siteId => $siteToClone ) {
									?>
									<div class="clonesite_select_site_item" id="<?php echo esc_attr( $siteId ); ?>" rand="<?php echo esc_attr( MainWP_Helper::rand_string( 5 ) ); ?>">
										<div class="mainwp-child_size_label" size="<?php echo esc_attr( $siteToClone['size'] ); ?>"><?php echo esc_html( $siteToClone['size'] ); ?>MB</div>
										<div class="mainwp-child_name_label"><?php echo esc_html( $siteToClone['name'] ); ?></div>
										<div class="mainwp-child_url_label"><?php echo esc_html( MainWP_Helper::get_nice_url( $siteToClone['url'] ) ); ?></div>
									</div>
									<?php
								}
								?>
							</div>
							<p><?php esc_html_e( 'The site selected above will replace this site\'s files and database', 'mainwp-child' ); ?></p>
						</div>
						<div class="mainwp-child_clonebutton_container">
							<?php if ( ! $error ) { ?>
								<a href="#" id="mainwp-child_clonebutton" class="button-primary"><?php esc_html_e( 'Clone website', 'mainwp-child' ); ?></a>
							<?php } ?>
						</div>
						<div style="clear:both"></div>
					</div>
				</div>
			</form>
			<br/>
			<?php
		}
		?>
		<div class="postbox">
			<h2 class="hndle"><strong><?php esc_html_e( 'Option 1:', 'mainwp-child' ); ?></strong> <?php esc_html_e( 'Restore/Clone from backup', 'mainwp-child' ); ?></h2>
			<div class="inside">
				<p><?php esc_html_e( 'Upload backup in .zip format (Maximum filesize for your server settings: ', 'mainwp-child' ); ?><?php echo esc_html( $uploadSize ); ?>)</p>
				<em><?php esc_html_e( 'If you have a FULL backup created by the default MainWP Backup system you may restore it by uploading here. Backups created by 3rd party plugins will not work.', 'mainwp-child' ); ?>
				<br/>
				<?php esc_html_e( 'A database only backup will not work.', 'mainwp-child' ); ?></em>
				<br/>
				<br/>
				<form action="<?php echo esc_attr( admin_url( 'options-general.php?page=mainwp_child_tab&tab=restore-clone&upload=yes' ) ); ?>" method="post" enctype="multipart/form-data">
					<input type="file" name="file" id="file"/>
					<input type="submit" name="submit" id="filesubmit" class="button button-primary" disabled="disabled" value="<?php esc_attr_e( 'Clone/Restore Website', 'mainwp-child' ); ?>"/>
					<input type="hidden" name="_nonce" value="<?php echo wp_create_nonce( 'cloneRestore' ); ?>" />
				</form>
			</div>
		</div>
		<?php
	}

	/**
	 * Render normal restore page.
	 *
	 * @uses \MainWP\Child\MainWP_Child_Branding::get_branding_title()
	 * @uses \MainWP\Child\MainWP_Helper::return_bytes()
	 * @uses \MainWP\Child\MainWP_Helper::human_filesize()
	 * @uses \MainWP\Child\MainWP_Helper::get_wp_filesystem()
	 */
	public static function render_normal_restore() {
		$uploadError = false;
		$uploadFile  = false;

		if ( isset( $_REQUEST['_nonce'] ) && wp_verify_nonce( sanitize_text_field( wp_unslash( $_REQUEST['_nonce'] ) ), 'cloneRestore' ) ) {
			if ( isset( $_FILES['file'] ) ) {
				if ( ! function_exists( 'wp_handle_upload' ) ) {
					require_once ABSPATH . 'wp-admin/includes/file.php';
				}
				$uploadedfile     = isset( $_FILES['file'] ) ? wp_unslash( $_FILES['file'] ) : '';
				$upload_overrides = array( 'test_form' => false );
				$movefile         = wp_handle_upload( $uploadedfile, $upload_overrides );
				if ( $movefile ) {
					$uploadFile = str_replace( ABSPATH, '', $movefile['file'] );
				} else {
					$uploadError = esc_html__( 'File could not be uploaded.', 'mainwp-child' );
				}
			} else {
				$uploadError = esc_html__( 'File is empty. Please upload something more substantial. This error could also be caused by uploads being disabled in your php.ini or by post_max_size being defined as smaller than upload_max_filesize in php.ini.', 'mainwp-child' );
			}
		}

		$uploadSizeInBytes = min( MainWP_Helper::return_bytes( ini_get( 'upload_max_filesize' ) ), MainWP_Helper::return_bytes( ini_get( 'post_max_size' ) ) );
		$uploadSize        = MainWP_Helper::human_filesize( $uploadSizeInBytes );

		self::render_style();
		?>
		<div class="postbox">
			<h2 class="hndle"><span><strong><?php esc_html_e( 'Option 1:', 'mainwp-child' ); ?></strong> <?php esc_html_e( 'Restore', 'mainwp-child' ); ?></span></h2>
			<div class="inside">
				<?php
				MainWP_Helper::get_wp_filesystem();

				/**
				 * Global variable containing the instance of the (auto-)configured filesystem object after the filesystem "factory" has been run.
				 *
				 * @global object $wp_filesystem Filesystem object.
				 */
				global $wp_filesystem;

				if ( ( ! empty( $wp_filesystem ) && ! $wp_filesystem->is_writable( WP_CONTENT_DIR ) ) || ( empty( $wp_filesystem ) && ! is_writable( WP_CONTENT_DIR ) ) ) {
					echo '<div class="mainwp-child_info-box-red"><strong>' . esc_html__( 'Your content directory is not writable. Please set 0755 permission to ', 'mainwp-child' ) . esc_html( basename( WP_CONTENT_DIR ) ) . '. (' . esc_html( WP_CONTENT_DIR ) . ')</strong></div>';
					$error = true;
				}
				?>
				<div class="mainwp-child_info-box-green" style="display: none;"><?php esc_html_e( 'Restore process completed successfully! You will now need to click ', 'mainwp-child' ); ?>
					<a href="<?php echo esc_attr( admin_url( 'options-permalink.php' ) ); ?>"><?php esc_html_e( 'here', 'mainwp-child' ); ?></a><?php esc_html_e( ' to re-login to the admin and re-save permalinks.', 'mainwp-child' ); ?>
				</div>
				<?php
				if ( $uploadFile ) {
					esc_html_e( 'Upload successful.', 'mainwp-child' );
					?>
					<a href="#" id="mainwp-child_uploadclonebutton" class="button-primary" file="<?php echo esc_attr( $uploadFile ); ?>"><?php esc_html_e( 'Restore Website', 'mainwp-child' ); ?></a>
					<?php
				} else {
					if ( $uploadError ) {
						?>
						<div class="mainwp-child_info-box-red"><?php echo esc_html( $uploadError ); ?></div>
						<?php
					}
					?>
					<p><?php esc_html_e( 'Upload backup in .zip format (Maximum filesize for your server settings: ', 'mainwp-child' ); ?><?php echo esc_html( $uploadSize ); ?>)</p>
					<?php
						$branding_title = MainWP_Child_Branding::instance()->get_branding_title();
					if ( '' != $branding_title ) {
						$branding_msg = 'If you have a FULL backup created by basic ' . esc_html( stripslashes( $branding_title ) ) . ' Backup system you may restore it by uploading here. Backups created by 3rd party plugins will not work.';
					} else {
						$branding_msg = esc_html__( 'If you have a FULL backup created by basic MainWP Backup system you may restore it by uploading here. Backups created by 3rd party plugins will not work.', 'mainwp-child' );
					}
					?>
						<em>
							<?php echo $branding_msg; ?><br/>
							<?php esc_html_e( 'A database only backup will not work.', 'mainwp-child' ); ?>
						</em><br/><br/>
					<form action="<?php echo esc_attr( admin_url( 'options-general.php?page=mainwp_child_tab&tab=restore-clone&upload=yes' ) ); ?>" method="post" enctype="multipart/form-data">
						<input type="file" name="file" id="file" />
						<input type="submit" name="submit" class="button button-primary" id="filesubmit" disabled="disabled" value="<?php esc_attr_e( 'Restore Website', 'mainwp-child' ); ?>"/>
						<input type="hidden" name="_nonce" value="<?php echo wp_create_nonce( 'cloneRestore' ); ?>" />
					</form>
				<?php } ?>
			</div>
		</div>
		<?php

		self::render_clone_from_server();
		self::render_java_script();
	}

	/**
	 * Credit
	 *
	 * Plugin-Name: Add From Server
	 * Version: 3.2.0.3
	 * Plugin URI: http://dd32.id.au/wordpress-plugins/add-from-server/
	 * Description: Plugin to allow the Media Manager to add files from the webservers filesystem. <strong>Note:</strong> All files are copied to the uploads directory.
	 * Author: Dion Hulse
	 * Author URI: http://dd32.id.au/
	 */
	/**
	 * Render Clone from server
	 * Allows the Media Manager to add files from the webservers filesystem. Note: All files are copied to the uploads directory.
	 *
	 * @uses \MainWP\Child\MainWP_Helper::get_mainwp_dir()
	 * @uses \MainWP\Child\MainWP_Helper::update_option()
	 */
	private static function render_clone_from_server() {
		$page         = isset( $_REQUEST['page'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['page'] ) ) : '';
		$sitesToClone = get_option( 'mainwp_child_clone_sites' );
		$url          = admin_url( 'options-general.php?page=mainwp_child_tab&tab=restore-clone#title_03' );
		$dirs         = MainWP_Helper::get_mainwp_dir( 'backup', false );
		$current_dir  = $dirs[0];
		$backup_dir   = $current_dir;

		if ( isset( $_REQUEST['dir'] ) ) {
			$current_dir = isset( $_REQUEST['dir'] ) ? stripslashes( rawurldecode( wp_unslash( $_REQUEST['dir'] ) ) ) : '';
			$current_dir = '/' . ltrim( $current_dir, '/' );
			if ( ! is_readable( $current_dir ) && get_option( 'mainwp_child_clone_from_server_last_folder' ) ) {
				$current_dir = get_option( 'mainwp_child_clone_from_server_last_folder' ) . $current_dir;
			}
		}

		if ( ! is_readable( $current_dir ) ) {
			$current_dir = WP_CONTENT_DIR;
		}

		$current_dir = str_replace( '\\', '/', $current_dir );

		if ( strlen( $current_dir ) > 1 ) {
			$current_dir = untrailingslashit( $current_dir );
		}

		echo '<div class="postbox">';
		echo '<h2 id="title_03" class="hndle"><span><strong>' . esc_html__( 'Option 2:', 'mainwp-child' ) . '</strong> ' . esc_html__( 'Restore/Clone From Server', 'mainwp-child' ) . '</span></h2>';
		echo '<div class="inside">';
		echo '<em>' . esc_html__( 'If you have uploaded a FULL backup to your server (via FTP or other means) you can use this section to locate the zip file and select it. A database only backup will not work.', 'mainwp-child' ) . '</em>';

		if ( ! is_readable( $current_dir ) ) {
			echo '<div class="mainwp-child_info-box-yellow"><strong>' . esc_html__( 'Root directory is not readable. Please contact with site administrator to correct.', 'mainwp-child' ) . '</strong></div>';

			return;
		}
		MainWP_Helper::update_option( 'mainwp_child_clone_from_server_last_folder', rtrim( $current_dir, '/' ) );

		$parts       = explode( '/', ltrim( $current_dir, '/' ) );
		$dirparts    = '';
		$count_parts = count( $parts );
		for ( $i = $count_parts - 1; $i >= 0; $i -- ) {
			$part = $parts[ $i ];
			$adir = implode( '/', array_slice( $parts, 0, $i + 1 ) );
			if ( strlen( $adir ) > 1 ) {
				$adir = ltrim( $adir, '/' );
			}
			$durl     = esc_url( add_query_arg( array( 'dir' => rawurlencode( $adir ) ), $url ) );
			$dirparts = '<a href="' . $durl . '">' . $part . DIRECTORY_SEPARATOR . '</a>' . $dirparts;
		}

		echo '<div style="padding: 8px 12px; background-color: #e5e5e5; margin-top: 1em;">' . sprintf( esc_html__( '%1$sCurrent Directory:%2$s %3$s', 'mainwp-child' ), '<strong>', '</strong>', '<span>' . $dirparts . '</span>' ) . '</div>';
		$quick_dirs   = array();
		$quick_dirs[] = array( esc_html__( 'Site Root', 'mainwp-child' ), ABSPATH );
		$quick_dirs[] = array( esc_html__( 'Backup', 'mainwp-child' ), $backup_dir );
		$uploads      = wp_upload_dir();
		if ( $uploads && false === $uploads['error'] ) {
			$quick_dirs[] = array( esc_html__( 'Uploads Folder', 'mainwp-child' ), $uploads['path'] );
		}
		$quick_dirs[] = array( esc_html__( 'Content Folder', 'mainwp-child' ), WP_CONTENT_DIR );

		$quick_links = array();
		foreach ( $quick_dirs as $dir ) {
			list( $text, $adir ) = $dir;
			$adir                = str_replace( '\\', '/', strtolower( $adir ) );
			if ( strlen( $adir ) > 1 ) {
				$adir = ltrim( $adir, '/' );
			}
			$durl          = esc_url( add_query_arg( array( 'dir' => rawurlencode( $adir ) ), $url ) );
			$quick_links[] = "<a href='$durl'>$text</a>";
		}

		if ( ! empty( $quick_links ) ) {
			echo '<div style="padding: 8px 12px; border-bottom: 1px solid #e5e5e5; margin-bottom: 1em;"><strong>' . esc_html__( 'Quick Jump:', 'mainwp-child' ) . '</strong> ' . implode( ' | ', $quick_links ) . '</div>';
		}

		self::render_clone_from_server_form( $current_dir, $url );
	}

	/**
	 * Render clone from server form.
	 *
	 * @param string $current_dir Current parent directory.
	 * @param string $url URL of file to upload.
	 *
	 * @uses MainWP_Clone::is_archive()
	 */
	private static function render_clone_from_server_form( $current_dir, $url ) {

		$dir_files      = scandir( $current_dir );
		$directories    = array();
		$files          = array();
		$rejected_files = array();
		foreach ( (array) $dir_files as $file ) {
			if ( in_array( $file, array( '.', '..' ) ) ) {
				continue;
			}
			if ( is_dir( $current_dir . '/' . $file ) ) {
				$directories[] = $file;
			} else {
				if ( ! MainWP_Clone::is_archive( $file ) ) {
					$rejected_files[] = $file;
				} else {
					$files[] = $file;
				}
			}
		}

		sort( $directories );
		sort( $files );
		$parent = dirname( $current_dir );
		?>
		<form method="post" action="">
			<div class="mainwp-child_select_sites_box" id="mainwp_child_select_files_from_server_box">
				<div class="postbox">
					<h2 class="hndle"><?php esc_html_e( 'Select File', 'mainwp-child' ); ?></h2>
					<div class="inside">
						<div id="mainwp-child_clonesite_select_site">
							<div class="clonesite_select_site_item">
								<div class="mainwp-child_name_label">
									<a href="<?php echo esc_url( add_query_arg( array( 'dir' => rawurlencode( $parent ) ), $url ) ); ?>" title="<?php echo esc_attr( dirname( $current_dir ) ); ?>"><?php esc_html_e( 'Parent Folder', 'mainwp-child' ); ?></a>
								</div>
							</div>
							<?php
							foreach ( (array) $directories as $file ) {
								$filename   = ltrim( $file, '/' );
								$folder_url = esc_url( add_query_arg( array( 'dir' => rawurlencode( $filename ) ), $url ) );
								?>
								<div class="clonesite_select_site_item">
									<div class="mainwp-child_name_label">
										<a href="<?php echo esc_attr( $folder_url ); ?>"><?php echo esc_html( rtrim( $filename, '/' ) . DIRECTORY_SEPARATOR ); ?></a>
									</div>
								</div>
								<?php
							}
							foreach ( $files as $file ) {
								?>
								<div class="clonesite_select_site_item">
									<div class="mainwp-child_name_label">
										<span><?php echo esc_html( $file ); ?></span>
									</div>
								</div>
								<?php
							}
							foreach ( $rejected_files as $file ) {
								?>
								<div class="mainwp_rejected_files">
									<div class="mainwp-child_name_label">
										<span><?php echo esc_html( $file ); ?></span>
									</div>
								</div>
								<?php
							}
							?>
						</div>
					</div>
					<div class="mainwp-child_clonebutton_container">
						<a href="#" id="mainwp-child_clonebutton_from_server" class="button-primary button"><?php esc_html_e( 'Clone/Restore Website', 'mainwp-child' ); ?></a>
					</div>
					<div style="clear:both"></div>
				</div>
			</div>
		</form>
		<input type="hidden" id="clonesite_from_server_current_dir" value="<?php echo esc_attr( $current_dir ); ?>"/>
		</div>
		</div>
		<?php
	}

	/**
	 * Render javascript.
	 *
	 * @uses \MainWP\Child\MainWP_Clone::get_security_nonces()
	 * @uses \MainWP\Child\MainWP_Helper::return_bytes()
	 * @uses \MainWP\Child\MainWP_Helper::human_filesize()
	 */
	public static function render_java_script() {
		$uploadSizeInBytes = min( MainWP_Helper::return_bytes( ini_get( 'upload_max_filesize' ) ), MainWP_Helper::return_bytes( ini_get( 'post_max_size' ) ) );
		$uploadSize        = MainWP_Helper::human_filesize( $uploadSizeInBytes );
		?>
		<div id="mainwp-child_clone_status" title="Restore process"></div>
		<script language="javascript">
			var child_security_nonces = [];
			<?php
			$security_nonces = MainWP_Clone::instance()->get_security_nonces();
			foreach ( $security_nonces as $k => $v ) {
				echo 'child_security_nonces[' . "'" . $k . "'" . '] = ' . "'" . $v . "';\n";
			}
			?>

			mainwpchild_secure_data = function(data, includeDts)
			{
				if (data['action'] == undefined) return data;

				data['security'] = child_security_nonces[data['action']];
				if (includeDts) data['dts'] = Math.round(new Date().getTime() / 1000);
				return data;
			};

			jQuery( document ).on( 'change', '#file', function () {
				var maxSize = <?php echo esc_js( $uploadSizeInBytes ); ?>;
				var humanSize = '<?php echo esc_js( $uploadSize ); ?>';

				if ( this.files[0].size > maxSize ) {
					jQuery( '#filesubmit' ).attr( 'disabled', 'disabled' );
					alert( 'The selected file is bigger than your maximum allowed filesize. (Maximum: ' + humanSize + ')' );
				}
				else {
					jQuery( '#filesubmit' ).removeAttr( 'disabled' );
				}
			} );
			jQuery( document ).on( 'click', '#mainwp-child_displayby_sitename', function () {
				jQuery( '#mainwp-child_displayby_url' ).removeClass( 'mainwp-child_action_down' );
				jQuery( this ).addClass( 'mainwp-child_action_down' );
				jQuery( '.mainwp-child_url_label' ).hide();
				jQuery( '.mainwp-child_name_label' ).show();
				return false;
			} );
			jQuery( document ).on( 'click', '#mainwp-child_displayby_url', function () {
				jQuery( '#mainwp-child_displayby_sitename' ).removeClass( 'mainwp-child_action_down' );
				jQuery( this ).addClass( 'mainwp-child_action_down' );
				jQuery( '.mainwp-child_name_label' ).hide();
				jQuery( '.mainwp-child_url_label' ).show();
				return false;
			} );
			jQuery( document ).on( 'click', '.clonesite_select_site_item', function () {
				jQuery( '.clonesite_select_site_item' ).removeClass( 'selected' );
				jQuery( this ).addClass( 'selected' );
			} );

			var pollingCreation = undefined;
			var backupCreationFinished = false;

			var pollingDownloading = undefined;
			var backupDownloadFinished = false;

			handleCloneError = function ( resp ) {
				updateClonePopup( resp.error, true, 'red' );
			};

			updateClonePopup = function ( pText, pShowDate, pColor ) {
				if ( pShowDate == undefined ) pShowDate = true;

				var theDiv = jQuery( '#mainwp-child_clone_status' );
				theDiv.append( '<br /><span style="color: ' + pColor + ';">' + (pShowDate ? cloneDateToHMS( new Date() ) + ' ' : '') + pText + '</span>' );
				theDiv.animate( {scrollTop: theDiv.height() * 2}, 100 );
			};

			cloneDateToHMS = function ( date ) {
				var h = date.getHours();
				var m = date.getMinutes();
				var s = date.getSeconds();
				return '' + (h <= 9 ? '0' + h : h) + ':' + (m <= 9 ? '0' + m : m) + ':' + (s <= 9 ? '0' + s : s);
			};

			var translations = [];
			translations['large_site'] = "<?php esc_html_e( 'This is a large site (%dMB), the restore process will more than likely fail.', 'mainwp-child' ); ?>";
			translations['continue_anyway'] = "<?php esc_html_e( 'Continue Anyway?', 'mainwp-child' ); ?>";
			translations['creating_backup'] = "<?php esc_html_e( 'Creating backup on %1$s expected size: %2$dMB (estimated time: %3$d seconds)', 'mainwp-child' ); ?>";
			translations['backup_created'] = "<?php esc_html_e( 'Backup created on %1$s total size to download: %2$dMB', 'mainwp-child' ); ?>";
			translations['downloading_backup'] = "<?php esc_html_e( 'Downloading backup', 'mainwp-child' ); ?>";
			translations['backup_downloaded'] = "<?php esc_html_e( 'Backup downloaded', 'mainwp-child' ); ?>";
			translations['extracting_backup'] = "<?php esc_html_e( 'Extracting backup and updating your database, this might take a while. Please be patient.', 'mainwp-child' ); ?>";
			translations['clone_complete'] = "<?php esc_html_e( 'Cloning process completed successfully!', 'mainwp-child' ); ?>";

			cloneInitiateBackupCreation = function ( siteId, siteName, size, rand, continueAnyway ) {
				if ( (continueAnyway == undefined) && (size > 256) ) {
					updateClonePopup( mwp_sprintf( translations['large_site'], size ) + ' <a href="#" class="button continueCloneButton" onClick="cloneInitiateBackupCreation(' + "'" + siteId + "'" + ', ' + "'" + siteName + "'" + ', ' + size + ', ' + "'" + rand + "'" + ', true); return false;">' + translations['continue_anyway'] + '</a>' );
					return;
				}
				else {
					jQuery( '.continueCloneButton' ).hide();
				}

				size = size / 2.4;
				updateClonePopup( mwp_sprintf( translations['creating_backup'], siteName, size.toFixed( 2 ), (size / 5 * 3).toFixed( 2 ) ) );

				updateClonePopup( '<div id="mainwp-child-clone-create-progress" style="margin-top: 1em !important;"></div>', false );
				jQuery( '#mainwp-child-clone-create-progress' ).progressbar( {value: 0, max: (size * 1024)} );

				var data = mainwpchild_secure_data({
					action: 'mainwp-child_clone_backupcreate',
					siteId: siteId,
					rand: rand
				});

				jQuery.post( ajaxurl, data, function ( pSiteId, pSiteName ) {
					return function ( resp ) {
						backupCreationFinished = true;
						clearTimeout( pollingCreation );

						var progressBar = jQuery( '#mainwp-child-clone-create-progress' );
						progressBar.progressbar( 'value', parseFloat( progressBar.progressbar( 'option', 'max' ) ) );

						if ( resp.error ) {
							handleCloneError( resp );
							return;
						}
						updateClonePopup( mwp_sprintf( translations['backup_created'], pSiteName, (resp.size / 1024).toFixed( 2 ) ) );
						cloneInitiateBackupDownload( pSiteId, resp.url, resp.size );
					}
				}( siteId, siteName ), 'json' );
				pollingCreation = setTimeout( function () {
					cloneBackupCreationPolling( siteId, rand );
				}, 1000 );
			};

			cloneBackupCreationPolling = function ( siteId, rand ) {
				if ( backupCreationFinished ) return;

				var data = mainwpchild_secure_data({
					action: 'mainwp-child_clone_backupcreatepoll',
					siteId: siteId,
					rand: rand
				});

				jQuery.post( ajaxurl, data, function ( pSiteId, pRand ) {
					return function ( resp ) {
						if ( backupCreationFinished ) return;
						if ( resp.size ) {
							var progressBar = jQuery( '#mainwp-child-clone-create-progress' );
							if ( progressBar.progressbar( 'option', 'value' ) < progressBar.progressbar( 'option', 'max' ) ) {
								progressBar.progressbar( 'value', resp.size );
							}
						}
						pollingCreation = setTimeout( function () {
							cloneBackupCreationPolling( pSiteId, pRand );
						}, 1000 );
					}
				}( siteId, rand ), 'json' );
			};

			cloneInitiateBackupDownload = function ( pSiteId, pFile, pSize ) {
				updateClonePopup( translations['downloading_backup'] );

				updateClonePopup( '<div id="mainwp-child-clone-download-progress" style="margin-top: 1em !important;"></div>', false );
				jQuery( '#mainwp-child-clone-download-progress' ).progressbar( {value: 0, max: pSize} );

				var data = mainwpchild_secure_data({
					action: 'mainwp-child_clone_backupdownload',
					file: pFile
				});

				if ( pSiteId != undefined ) data['siteId'] = pSiteId;

				jQuery.post( ajaxurl, data, function ( siteId ) {
					return function ( resp ) {
						backupDownloadFinished = true;
						clearTimeout( pollingDownloading );

						var progressBar = jQuery( '#mainwp-child-clone-download-progress' );
						progressBar.progressbar( 'value', parseFloat( progressBar.progressbar( 'option', 'max' ) ) );

						if ( resp.error ) {
							handleCloneError( resp );
							return;
						}
						updateClonePopup( translations['backup_downloaded'] );

						cloneInitiateExtractBackup();
					}
				}( pSiteId ), 'json' );

				pollingDownloading = setTimeout( function () {
					cloneBackupDownloadPolling( pSiteId, pFile );
				}, 1000 );
			};

			cloneBackupDownloadPolling = function ( siteId, pFile ) {
				if ( backupDownloadFinished ) return;

				var data = mainwpchild_secure_data({
					action: 'mainwp-child_clone_backupdownloadpoll',
					siteId: siteId,
					file: pFile
				});

				jQuery.post( ajaxurl, data, function ( pSiteId ) {
					return function ( resp ) {
						if ( backupDownloadFinished ) return;
						if ( resp.size ) {
							var progressBar = jQuery( '#mainwp-child-clone-download-progress' );
							if ( progressBar.progressbar( 'option', 'value' ) < progressBar.progressbar( 'option', 'max' ) ) {
								progressBar.progressbar( 'value', resp.size );
							}
						}

						pollingDownloading = setTimeout( function () {
							cloneBackupDownloadPolling( pSiteId );
						}, 1000 );
					}
				}( siteId ), 'json' );
			};

			cloneInitiateExtractBackup = function ( file ) {
				if ( file == undefined ) file = '';

				updateClonePopup( translations['extracting_backup'] );
				var data = mainwpchild_secure_data({
					action: 'mainwp-child_clone_backupextract',
					f: file
				});

				jQuery.ajax( {
					type: "POST",
					url: ajaxurl,
					data: data,
					success: function ( resp ) {
						if ( resp.error ) {
							handleCloneError( resp );
							return;
						}

						updateClonePopup( translations['clone_complete'] );

						setTimeout( function () {
							jQuery( '#mainwp-child_clone_status' ).dialog( 'close' );
							jQuery( '.mainwp-child_select_sites_box' ).hide();
							jQuery( '.mainwp-child_info-box-green' ).show();
							jQuery( '#mainwp-child_uploadclonebutton' ).hide();
							jQuery( '#mainwp-child_clonebutton' ).hide();
							jQuery( '.mainwp-hide-after-restore' ).hide();
						}, 1000 );
					},
					dataType: 'json'
				} );
			};

			jQuery( document ).on( 'click', '#mainwp-child-restore', function () {
				jQuery( '#mainwp-child_clone_status' ).dialog( {
					resizable: false,
					height: 400,
					width: 750,
					modal: true,
					close: function ( event, ui ) {
						bulkTaskRunning = false;
						jQuery( '#mainwp-child_clone_status' ).dialog( 'destroy' );
					}
				} );

				cloneInitiateBackupDownload( undefined, jQuery( this ).attr( 'file' ), jQuery( this ).attr( 'size' ) );
				return false;
			} );

			jQuery( document ).on( 'click', '#mainwp-child_uploadclonebutton', function () {
				var file = jQuery( this ).attr( 'file' );
				jQuery( '#mainwp-child_clone_status' ).dialog( {
					resizable: false,
					height: 400,
					width: 750,
					modal: true,
					close: function ( event, ui ) {
						bulkTaskRunning = false;
						jQuery( '#mainwp-child_clone_status' ).dialog( 'destroy' );
					}
				} );

				cloneInitiateExtractBackup( file );
				return false;
			} );

			jQuery( document ).on( 'click', '#mainwp-child_clonebutton', function () {
				jQuery( '#mainwp-child_clone_status' ).dialog( {
					resizable: false,
					height: 400,
					width: 750,
					modal: true,
					close: function ( event, ui ) {
						bulkTaskRunning = false;
						jQuery( '#mainwp-child_clone_status' ).dialog( 'destroy' );
					}
				} );

				var siteElement = jQuery( '.clonesite_select_site_item.selected' );
				var siteId = siteElement.attr( 'id' );
				var siteName = siteElement.find( '.mainwp-child_name_label' ).html();
				var siteSize = siteElement.find( '.mainwp-child_size_label' ).attr( 'size' );
				var siteRand = siteElement.attr( 'rand' );
				cloneInitiateBackupCreation( siteId, siteName, siteSize, siteRand );

				return false;
			} );

			function mwp_sprintf() {
				if ( !arguments || arguments.length < 1 || !RegExp ) {
					return;
				}
				var str = arguments[0];
				var re = /([^%]*)%('.|0|\x20)?(-)?(\d+)?(\.\d+)?(\d\$)?(%|b|c|d|u|f|o|s|x|X)(.*)/;
				var a = b = [], numSubstitutions = 0, numMatches = 0;
				while ( a = re.exec( str ) ) {
					var leftpart = a[1], pPad = a[2], pJustify = a[3], pMinLength = a[4];
					var pPrecision = a[5], pOrder = a[6], pType = a[7], rightPart = a[8];

					numMatches++;
					if ( pType == '%' ) {
						subst = '%';
					}
					else {
						numSubstitutions++;
						if ( numSubstitutions >= arguments.length ) {
							alert( 'Error! Not enough function arguments (' + (arguments.length - 1) + ', excluding the string)\nfor the number of substitution parameters in string (' + numSubstitutions + ' so far).' );
						}
						var param = arguments[numSubstitutions];
						var pad = '';
						if ( pPad && pPad.substr( 0, 1 ) == "'" ) pad = leftpart.substr( 1, 1 );
						else if ( pPad ) pad = pPad;
						var justifyRight = true;
						if ( pJustify && pJustify === "-" ) justifyRight = false;
						var minLength = -1;
						if ( pMinLength ) minLength = parseInt( pMinLength );
						var precision = -1;
						if ( pPrecision && pType == 'f' ) precision = parseInt( pPrecision.substring( 1 ) );
						var subst = param;
						if ( pType == 'b' ) subst = parseInt( param ).toString( 2 );
						else if ( pType == 'c' ) subst = String.fromCharCode( parseInt( param ) );
						else if ( pType == 'd' ) subst = parseInt( param ) ? parseInt( param ) : 0;
						else if ( pType == 'u' ) subst = Math.abs( param );
						else if ( pType == 'f' ) subst = (precision > -1) ? Math.round( parseFloat( param ) * Math.pow( 10, precision ) ) / Math.pow( 10, precision ) : parseFloat( param );
						else if ( pType == 'o' ) subst = parseInt( param ).toString( 8 );
						else if ( pType == 's' ) subst = param;
						else if ( pType == 'x' ) subst = ('' + parseInt( param ).toString( 16 )).toLowerCase();
						else if ( pType == 'X' ) subst = ('' + parseInt( param ).toString( 16 )).toUpperCase();
					}
					str = leftpart + subst + rightPart;
				}
				return str;
			}

			jQuery( document ).on( 'click', '#mainwp-child_clonebutton_from_server', function () {
				var cur_dir = jQuery( '#clonesite_from_server_current_dir' ).val();
				var file = cur_dir + '/' + jQuery( '.clonesite_select_site_item.selected span' ).html();
				jQuery( '#mainwp-child_clone_status' ).dialog( {
					resizable: false,
					height: 400,
					width: 750,
					modal: true,
					close: function ( event, ui ) {
						bulkTaskRunning = false;
						jQuery( '#mainwp-child_clone_status' ).dialog( 'destroy' );
					}
				} );

				cloneInitiateExtractBackup( file );
				return false;
			} );

		</script>
		<?php
	}

	/**
	 * Render style.
	 */
	public static function render_style() {
		?>
		<style>
			#mainwp-child_clone_status {
				display: none;
			}
			.mainwp-child_info-box-yellow {
				margin: 5px 0 15px;
				padding: .6em;
				background: #fff;
				border-left: 4px solid #ffec00;
				clear: both;
				color: #333;
				box-shadow: 0 1px 1px rgba(0, 0, 0, .04);
			}

			.mainwp-child_info-box-red {
				margin: 5px 0 15px;
				padding: .6em;
				background: #fff;
				border-left: 4px solid #bb4539;
				clear: both;
				color: #333;
				box-shadow: 0 1px 1px rgba(0, 0, 0, .04);
			}

			.mainwp-child_info-box-green {
				margin: 5px 0 15px;
				padding: .6em;
				background: #fff;
				border-left: 4px solid #7fb100;
				clear: both;
				color: #333;
				box-shadow: 0 1px 1px rgba(0, 0, 0, .04);
			}

			.mainwp-child_select_sites_box {
				width: 100%;
			}

			#mainwp-child_clonesite_select_site {
				max-height: 585px !important;
				overflow: auto;
				background: #fff;
				width: 100%;
				border: 1px solid #DDDDDD;
				height: 300px;
				overflow-y: scroll;
				margin-top: 10px;
			}

			.clonesite_select_site_item {
				padding: 5px;
			}

			.clonesite_select_site_item.selected {
				background-color: rgba(127, 177, 0, 0.3);
			}

			.clonesite_select_site_item:hover {
				cursor: pointer;
				background-color: rgba(127, 177, 0, 0.3);
			}

			.mainwp-child_select_sites_box .postbox h2 {
				margin-left: 10px;
			}

			.mainwp-child_action {
				text-decoration: none;
				background: none repeat scroll 0 0 #FFFFFF;
				border-color: #C9CBD1 #BFC2C8 #A9ABB1;
				border-style: solid;
				color: #3A3D46;
				display: inline-block;
				font-size: 12px;
				padding: 4px 8px;
				-webkit-box-shadow: 0 1px 0 rgba(0, 0, 0, 0.05);
				-moz-box-shadow: 0 1px 0 rgba(0, 0, 0, 0.05);
				box-shadow: 0 1px 0 rgba(0, 0, 0, 0.05);
			}

			.mainwp-child_action.left {
				border-width: 1px 0 1px 1px;
				-webkit-border-radius: 3px 0 0 3px;
				-moz-border-radius: 3px 0 0 3px;
				border-radius: 3px 0 0 3px;
			}

			.mainwp-child_action.right {
				border-width: 1px 1px 1px 1px;
				-webkit-border-radius: 0 3px 3px 0;
				-moz-border-radius: 0 3px 3px 0;
				border-radius: 0 3px 3px 0;
			}

			.mainwp-child_action_down {
				background-image: -webkit-gradient(linear, left top, left bottom, color-stop(0%, rgba(220, 221, 225, 1)), color-stop(100%, rgba(234, 236, 241, 1)));
				background: -webkit-linear-gradient(top, rgba(220, 221, 225, 1) 0%, rgba(234, 236, 241, 1) 100%);
				background: -moz-linear-gradient(top, rgba(220, 221, 225, 1) 0%, rgba(234, 236, 241, 1) 100%);
				background: -o-linear-gradient(top, rgba(220, 221, 225, 1) 0%, rgba(234, 236, 241, 1) 100%);
				background: -ms-linear-gradient(top, rgba(220, 221, 225, 1) 0%, rgba(234, 236, 241, 1) 100%);
				background: linear-gradient(top, rgba(220, 221, 225, 1) 0%, rgba(234, 236, 241, 1) 100%);
				filter: progid:DXImageTransform.Microsoft.gradient(startColorstr='#dcdde1', endColorstr='#eaecf1', GradientType=0);
				-webkit-box-shadow: 0 1px 0 rgba(255, 255, 255, 0.59), 0 2px 0 rgba(0, 0, 0, 0.05) inset;
				-moz-box-shadow: 0 1px 0 rgba(255, 255, 255, 0.59), 0 2px 0 rgba(0, 0, 0, 0.05) inset;
				box-shadow: 0 1px 0 rgba(255, 255, 255, 0.59), 0 2px 0 rgba(0, 0, 0, 0.05) inset;
				border-color: #b1b5c7 #bec2d1 #c9ccd9;
			}

			.mainwp-child_displayby {
				float: right;
				padding-top: 15px;
				padding-right: 10px;
				margin-top: -8px;
			}

			.mainwp-child_url_label {
				display: none;
			}

			.mainwp-child_size_label {
				float: right;
				padding-right: 10px;
				font-style: italic;
				color: #8f8f8f;
			}

			.mainwp-child_clonebutton_container {
				padding: 10px;
			}

			.ui-dialog {
				padding: .5em;
				width: 600px !important;
				overflow: hidden;
				-webkit-box-shadow: 0px 0px 15px rgba(50, 50, 50, 0.45);
				-moz-box-shadow: 0px 0px 15px rgba(50, 50, 50, 0.45);
				box-shadow: 0px 0px 15px rgba(50, 50, 50, 0.45);
				background: #fff !important;
			}

			.ui-dialog .ui-dialog-titlebar {
				background: none;
				border: none;
			}

			.ui-dialog .ui-dialog-title {
				font-size: 20px;
				font-family: Helvetica;
				text-transform: uppercase;
				color: #555;
			}

			.ui-dialog h3 {
				font-family: Helvetica;
				text-transform: uppercase;
				color: #888;
				border-radius: 25px;
				-moz-border-radius: 25px;
				-webkit-border-radius: 25px;
			}

			.ui-dialog .ui-dialog-titlebar-close {
				background: none;
				border-radius: 15px;
				-moz-border-radius: 15px;
				-webkit-border-radius: 15px;
				color: #fff;
			}

			.ui-dialog .ui-dialog-titlebar-close:hover {
				background: #7fb100;
			}

			#mainwp-child_clone_status .ui-progressbar {
				border: 5px Solid #ddd !important;
				border-radius: 25px !important;
				-moz-border-radius: 25px !important;
				-webkit-border-radius: 25px !important;
			}

			#mainwp-child_clone_status .ui-progressbar-value {
				background: #7fb100 !important;
				border-radius: 25px !important;
				-moz-border-radius: 25px !important;
				-webkit-border-radius: 25px !important;
				display: inline-block;
				overflow: hidden;
				-webkit-transition: width .4s ease-in-out;
				-moz-transition: width .4s ease-in-out;
				-ms-transition: width .4s ease-in-out;
				-o-transition: width .4s ease-in-out;
				transition: width .4s ease-in-out;
			}

			#mainwp-child_clone_status .ui-progressbar-value:after {
				content: "";
				position: relative;
				top: 0;
				height: 100%;
				width: 100%;
				display: inline-block;

				-webkit-background-size: 30px 30px;
				-moz-background-size: 30px 30px;
				background-size: 30px 30px;
				overflow: hidden !important;
				background-image: -webkit-gradient(linear, left top, right bottom,
				color-stop(.25, rgba(255, 255, 255, .15)), color-stop(.25, transparent),
				color-stop(.5, transparent), color-stop(.5, rgba(255, 255, 255, .15)),
				color-stop(.75, rgba(255, 255, 255, .15)), color-stop(.75, transparent),
				to(transparent));
				background-image: -webkit-linear-gradient(135deg, rgba(255, 255, 255, .15) 25%, transparent 25%,
				transparent 50%, rgba(255, 255, 255, .15) 50%, rgba(255, 255, 255, .15) 75%,
				transparent 75%, transparent);
				background-image: -moz-linear-gradient(135deg, rgba(255, 255, 255, .15) 25%, transparent 25%,
				transparent 50%, rgba(255, 255, 255, .15) 50%, rgba(255, 255, 255, .15) 75%,
				transparent 75%, transparent);
				background-image: -ms-linear-gradient(135deg, rgba(255, 255, 255, .15) 25%, transparent 25%,
				transparent 50%, rgba(255, 255, 255, .15) 50%, rgba(255, 255, 255, .15) 75%,
				transparent 75%, transparent);
				background-image: -o-linear-gradient(135deg, rgba(255, 255, 255, .15) 25%, transparent 25%,
				transparent 50%, rgba(255, 255, 255, .15) 50%, rgba(255, 255, 255, .15) 75%,
				transparent 75%, transparent);
				background-image: linear-gradient(135deg, rgba(255, 255, 255, .15) 25%, transparent 25%,
				transparent 50%, rgba(255, 255, 255, .15) 50%, rgba(255, 255, 255, .15) 75%,
				transparent 75%, transparent);

				-webkit-animation: animate-stripes 6s linear infinite;
				-moz-animation: animate-stripes 6s linear infinite;
			}

			@-webkit-keyframes animate-stripes {
				0% {
					background-position: 0 0;
				}
				100% {
					background-position: 100% 0;
				}
			}

			@-moz-keyframes animate-stripes {
				0% {
					background-position: 0 0;
				}
				100% {
					background-position: 100% 0;
				}
			}

			#mainwp_child_select_files_from_server_box .mainwp-child_name_label > a {
				text-decoration: none;
			}

			#mainwp_child_select_files_from_server_box .mainwp_rejected_files {
				background-color: #FFE8EE;
				padding: 5px;
			}
		</style>
		<?php
	}

	/**
	 * Method permalink_admin_notice()
	 */
	public static function permalink_admin_notice() {
		if ( isset( $_POST['permalink_structure'] ) || isset( $_POST['category_base'] ) || isset( $_POST['tag_base'] ) ) {
			return;
		}
		?>
		<style>
			.mainwp-child_info-box-green {
				margin: 5px 0 15px;
				padding: .6em;
				background: rgba(127, 177, 0, 0.3);
				border: 1px solid #7fb100;
				border-radius: 3px;
				margin-right: 10px;
				-moz-border-radius: 3px;
				-webkit-border-radius: 3px;
				clear: both;
			}
		</style>
		<div class="mainwp-child_info-box-green">
			<?php
			if ( get_option( 'mainwp_child_restore_permalink' ) ) {
				esc_html_e( 'Restore process completed successfully! Check and re-save permalinks ', 'mainwp-child' );
			} else {
				esc_html_e( 'Cloning process completed successfully! Check and re-save permalinks ', 'mainwp-child' );
			}
			?>
			<a href="<?php echo esc_attr( admin_url( 'options-permalink.php' ) ); ?>"><?php esc_html_e( 'here', 'mainwp-child' ); ?></a>.
		</div>
		<?php
	}

	/**
	 * Render Restore.
	 */
	public static function render_restore() {
		if ( '' === session_id() ) {
			session_start();
		}

		$file = isset( $_SESSION['file'] ) ? wp_unslash( $_SESSION['file'] ) : null;
		$size = isset( $_SESSION['size'] ) ? wp_unslash( $_SESSION['size'] ) : null;

		if ( isset( $_SESSION['file'] ) ) {
			unset( $_SESSION['file'] );
		}

		if ( isset( $_SESSION['size'] ) ) {
			unset( $_SESSION['size'] );
		}

		self::render_style();
		?>
		<div class="postbox">
			<h2 class="hndle"><?php esc_html_e( 'Restore', 'mainwp-child' ); ?></h2>
			<div class="inside">
				<div class="mainwp-hide-after-restore">
					<br/>
					<?php esc_html_e( 'Be sure to use a FULL backup created by your Network dashboard, if critical folders are excluded it may result in a not working installation.', 'mainwp-child' ); ?>
					<br/>
					<br/>
					<a href="#" class="button-primary" file="<?php echo esc_attr( urldecode( $file ) ); ?>" size="<?php echo esc_attr( $size / 1024 ); ?>" id="mainwp-child-restore"><?php esc_html_e( 'Start Restore', 'mainwp-child' ); ?></a>
					<i><?php esc_html_e( 'CAUTION: this will overwrite your existing site.', 'mainwp-child' ); ?></i>
				</div>
				<div class="mainwp-child_info-box-green" style="display: none;"><?php esc_html_e( 'Restore process completed successfully! You will now need to click ', 'mainwp-child' ); ?>
					<a href="<?php echo esc_attr( admin_url( 'options-permalink.php' ) ); ?>"><?php esc_html_e( 'here', 'mainwp-child' ); ?></a><?php esc_html_e( ' to re-login to the admin and re-save permalinks.', 'mainwp-child' ); ?>
				</div>
			</div>
		</div>
		<?php self::render_java_script(); ?>
		<script type="text/javascript">translations['clone_complete'] = '<?php esc_html_e( 'Restore process completed successfully!', 'mainwp-child' ); ?>';</script>
		<?php
	}
}
