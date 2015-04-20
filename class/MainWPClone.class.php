<?php

class MainWPClone
{
    public static function init()
    {
        self::init_ajax();

        add_action('check_admin_referer', array('MainWPClone', 'permalinkChanged'));
        if (get_option('mainwp_child_clone_permalink') || get_option('mainwp_child_restore_permalink')) add_action('admin_notices', array('MainWPClone', 'permalinkAdminNotice'));
    }

    public static function init_menu($the_branding, $childMenuSlug = "")
    {     
        if (empty($the_branding))
            $the_branding = "MainWP";        
        //$page = add_options_page('MainWPClone', __($the_branding . ' Clone','mainwp-child'), 'manage_options', 'MainWPClone', array('MainWPClone', 'render'));
        $page = add_submenu_page($childMenuSlug, 'MainWPClone', __($the_branding . ' Clone','mainwp-child'), 'manage_options', 'MainWPClone', array('MainWPClone', 'render'));
        add_action('admin_print_scripts-'.$page, array('MainWPClone', 'print_scripts'));
    }

    public static function init_restore_menu($the_branding, $childMenuSlug = "")
    {
        if (empty($the_branding))
            $the_branding = "MainWP";       
        //$page = add_options_page('MainWPClone', __($the_branding . ' Restore','mainwp-child'), 'manage_options', 'MainWPRestore', array('MainWPClone', 'renderNormalRestore'));
        $page = add_submenu_page($childMenuSlug, 'MainWPClone', __($the_branding . ' Restore','mainwp-child'), 'manage_options', 'MainWPRestore', array('MainWPClone', 'renderNormalRestore'));
        add_action('admin_print_scripts-'.$page, array('MainWPClone', 'print_scripts'));
    }

    public static function print_scripts()
    {
        wp_enqueue_script('jquery-ui-tooltip');
        wp_enqueue_script('jquery-ui-autocomplete');
        wp_enqueue_script('jquery-ui-progressbar');
        wp_enqueue_script('jquery-ui-dialog');

        global $wp_scripts;
        $ui = $wp_scripts->query('jquery-ui-core');
        $version = $ui->ver;
        if (MainWPHelper::startsWith($version, '1.10'))
        {
            wp_enqueue_style('jquery-ui-style', plugins_url('/css/1.10.4/jquery-ui.min.css', dirname(__FILE__)));
        }
        else
        {
            wp_enqueue_style('jquery-ui-style', plugins_url('/css/1.11.1/jquery-ui.min.css', dirname(__FILE__)));
        }
    }

    public static function renderHeader()
    {
        self::renderStyle();
        ?>
        <div class="mainwp-child-container">
        <?php
    }

    public static function renderFooter()
    {
        ?>
        </div>
        <?php
    }

    public static function upload_mimes($mime_types = array())
    {
        if (!isset($mime_types['tar.bz2'])) $mime_types['tar.bz2'] = 'application/x-tar';

        return $mime_types;
    }

    public static function render()
    {
        $uploadError = false;
        $uploadFile = false;
        if (isset($_REQUEST['upload']))
        {
            if (isset($_FILES['file']))
            {
                if (!function_exists('wp_handle_upload')) require_once(ABSPATH . 'wp-admin/includes/file.php');
                $uploadedfile = $_FILES['file'];
                $upload_overrides = array('test_form' => false);
                add_filter('upload_mimes', array('MainWPClone', 'upload_mimes'));
                $movefile = wp_handle_upload($uploadedfile, $upload_overrides);
                if ($movefile)
                {
                    $uploadFile = str_replace(ABSPATH, '', $movefile['file']);
                }
                else
                {
                    $uploadError = __('File could not be uploaded.','mainwp-child');
                }
            }
            else
            {
                $uploadError = __('File is empty. Please upload something more substantial. This error could also be caused by uploads being disabled in your php.ini or by post_max_size being defined as smaller than upload_max_filesize in php.ini.','mainwp-child');
            }
        }

        $sitesToClone = get_option('mainwp_child_clone_sites');
        $uploadSizeInBytes = min(MainWPHelper::return_bytes(ini_get('upload_max_filesize')), MainWPHelper::return_bytes(ini_get('post_max_size')));
        $uploadSize = MainWPHelper::human_filesize($uploadSizeInBytes);
        self::renderHeader();

        ?><div id="icon-options-general" class="icon32"><br></div><h2><?php _e('Clone or Restore','mainwp-child'); ?></h2><?php

        if ($sitesToClone == '0')
        {
            echo '<div class="mainwp-child_info-box-red"><strong>' . __('Cloning is currently off - To turn on return to your main dashboard and turn cloning on on the Migrate/Clone page.','mainwp-child') . '</strong></div>';
            return;
        }
        $error = false;
        MainWPHelper::getWPFilesystem();
        global $wp_filesystem;
        if ((!empty($wp_filesystem) && !$wp_filesystem->is_writable(WP_CONTENT_DIR)) || (empty($wp_filesystem) && !is_writable(WP_CONTENT_DIR)))
        {
            echo '<div class="mainwp-child_info-box-red"><strong>' . __('Your content directory is not writable. Please set 0755 permission to ','mainwp-child') . basename(WP_CONTENT_DIR) . '. (' . WP_CONTENT_DIR . ')</strong></div>';
            $error = true;
        }
        ?>
    <div class="mainwp-child_info-box-green" style="display: none;"><?php _e('Cloning process completed successfully! You will now need to click ','mainwp-child'); ?> <a href="<?php echo admin_url('options-permalink.php'); ?>"><?php _e('here','mainwp-child'); ?></a><?php _e(' to re-login to the admin and re-save permalinks.','mainwp-child'); ?></div>

    <?php
        if ($uploadFile)
        {
           _e('Upload successful.','mainwp-child'); ?> <a href="#" id="mainwp-child_uploadclonebutton" class="button-primary" file="<?php echo $uploadFile; ?>"><?php _e('Clone/Restore Website','mainwp-child'); ?></a><?php
        }
        else
        {
            if ($uploadError)
            {
                ?><div class="mainwp-child_info-box-red"><?php echo $uploadError; ?></div><?php
            }

            if (empty($sitesToClone))
            {
                echo '<div class="mainwp-child_info-box-yellow"><strong>' . __('Cloning is currently on but no sites have been allowed, to allow sites return to your main dashboard and turn cloning on on the Migrate/Clone page.','mainwp-child') . '</strong></div>';
            }
            else
            {
?>
    <form method="post" action="">
        <div class="mainwp-child_select_sites_box">
            <div class="postbox">
                <div class="mainwp-child_displayby"><?php _e('Display by:','mainwp-child'); ?> <a class="mainwp-child_action left mainwp-child_action_down" href="#" id="mainwp-child_displayby_sitename"><?php _e('Site Name','mainwp-child'); ?></a><a class="mainwp-child_action right" href="#" id="mainwp-child_displayby_url"><?php _e('URL','mainwp-child'); ?></a></div><h2><?php _e('Clone Options','mainwp-child'); ?></h2>
                <div class="inside">
                    <div id="mainwp-child_clonesite_select_site">
                        <?php
                        foreach ($sitesToClone as $siteId => $siteToClone)
                        {
                            ?>
                            <div class="clonesite_select_site_item" id="<?php echo $siteId; ?>" rand="<?php echo MainWPHelper::randString(5); ?>">
                                <div class="mainwp-child_size_label" size="<?php echo $siteToClone['size']; ?>"><?php echo $siteToClone['size']; ?> MB</div>
                                <div class="mainwp-child_name_label"><?php echo $siteToClone['name']; ?></div>
                                <div class="mainwp-child_url_label"><?php echo MainWPHelper::getNiceURL($siteToClone['url']); ?></div>
                            </div>
                            <?php
                        }
                        ?>
                    </div>
                </div>
                <div class="mainwp-child_clonebutton_container"><?php if (!$error) { ?><a href="#" id="mainwp-child_clonebutton" class="button-primary"><?php _e('Clone Website','mainwp-child'); ?></a><?php } ?></div>
                <div style="clear:both"></div>
            </div>
        </div>
    </form>
    <br />
            <?php
            }
            $sitesToClone = get_option('mainwp_child_clone_sites');
?>
    <div id="icon-options-general" class="icon32"><br></div><h2><strong><?php _e('Option 1:', 'mainwp-child'); ?></strong> <?php _e('Restore/Clone From Backup','mainwp-child'); ?></h2>
        <br />
    <div class="mainwp-child_info-box-green"><?php _e('Upload backup in .zip format (Maximum filesize for your server settings: ','mainwp-child'); ?><?php echo $uploadSize; ?>)</div>
    <i><?php _e('If you have a FULL backup created by your Network dashboard you may restore it by uploading here.','mainwp-child'); ?><br />
    <?php _e('A database only backup will not work.','mainwp-child'); ?></i><br /><br />
    <form action="<?php echo admin_url('admin.php?page=' . ($sitesToClone != '0' ? 'MainWPClone' : 'MainWPRestore') . '&upload=yes'); ?>" method="post" enctype="multipart/form-data"><input type="file" name="file" id="file" /> <input type="submit" name="submit" id="filesubmit" disabled="disabled" value="<?php _e('Clone/Restore Website','mainwp-child'); ?>" /></form>
        <?php
        }
		
		self::renderCloneFromServer();

        self::renderJavaScript();
    }
	
    public static function renderNormalRestore()
    {
        $uploadError = false;
        $uploadFile = false;
        if (isset($_REQUEST['upload']))
        {
            if (isset($_FILES['file']))
            {
                if (!function_exists('wp_handle_upload')) require_once(ABSPATH . 'wp-admin/includes/file.php');
                $uploadedfile = $_FILES['file'];
                $upload_overrides = array('test_form' => false);
                $movefile = wp_handle_upload($uploadedfile, $upload_overrides);
                if ($movefile)
                {
                    $uploadFile = str_replace(ABSPATH, '', $movefile['file']);
                }
                else
                {
                    $uploadError = __('File could not be uploaded.','mainwp-child');
                }
            }
            else
            {
                $uploadError = __('File is empty. Please upload something more substantial. This error could also be caused by uploads being disabled in your php.ini or by post_max_size being defined as smaller than upload_max_filesize in php.ini.','mainwp-child');
            }
        }

        $uploadSizeInBytes = min(MainWPHelper::return_bytes(ini_get('upload_max_filesize')), MainWPHelper::return_bytes(ini_get('post_max_size')));
        $uploadSize = MainWPHelper::human_filesize($uploadSizeInBytes);
        self::renderHeader();

        ?><div id="icon-options-general" class="icon32"><br></div><h2><strong><?php _e('Option 1:', 'mainwp-child'); ?></strong> <?php _e('Restore','mainwp-child'); ?></h2><?php

        MainWPHelper::getWPFilesystem();
        global $wp_filesystem;
        if ((!empty($wp_filesystem) && !$wp_filesystem->is_writable(WP_CONTENT_DIR)) || (empty($wp_filesystem) && !is_writable(WP_CONTENT_DIR)))
        {
            echo '<div class="mainwp-child_info-box-red"><strong>' . __('Your content directory is not writable. Please set 0755 permission to ','mainwp-child') . basename(WP_CONTENT_DIR) . '. (' . WP_CONTENT_DIR . ')</strong></div>';
            $error = true;
        }
        ?>
    <div class="mainwp-child_info-box-green" style="display: none;"><?php _e('Restore process completed successfully! You will now need to click ','mainwp-child'); ?> <a href="<?php echo admin_url('options-permalink.php'); ?>"><?php _e('here','mainwp-child'); ?></a><?php _e(' to re-login to the admin and re-save permalinks.','mainwp-child'); ?></div>

    <?php
        if ($uploadFile)
        {
           _e('Upload successful.','mainwp-child'); ?> <a href="#" id="mainwp-child_uploadclonebutton" class="button-primary" file="<?php echo $uploadFile; ?>"><?php _e('Restore Website','mainwp-child'); ?></a><?php
        }
        else
        {
            if ($uploadError)
            {
                ?><div class="mainwp-child_info-box-red"><?php echo $uploadError; ?></div><?php
            }
?>
    <?php _e('Upload backup in .zip format (Maximum filesize for your server settings: ','mainwp-child'); ?><?php echo $uploadSize; ?>)<br/>
    <i><?php _e('If you have a FULL backup created by your Network dashboard you may restore it by uploading here.','mainwp-child'); ?><br />
    <?php _e('A database only backup will not work.','mainwp-child'); ?></i><br /><br />
    <form action="<?php echo admin_url('admin.php?page=MainWPRestore&upload=yes'); ?>" method="post" enctype="multipart/form-data"><input type="file" name="file" id="file" /> <input type="submit" name="submit" id="filesubmit" disabled="disabled" value="<?php _e('Restore Website','mainwp-child'); ?>" /></form>
        <?php
        }
		
		self::renderCloneFromServer();
		
        self::renderJavaScript();
    }
    
/*
Plugin Name: Add From Server
Version: 3.2.0.3
Plugin URI: http://dd32.id.au/wordpress-plugins/add-from-server/
Description: Plugin to allow the Media Manager to add files from the webservers filesystem. <strong>Note:</strong> All files are copied to the uploads directory.
Author: Dion Hulse
Author URI: http://dd32.id.au/
*/    
	
    public static function renderCloneFromServer() {

        $page = $_REQUEST['page'];

        $sitesToClone = get_option('mainwp_child_clone_sites');
        $url = admin_url('admin.php?page=' . ($sitesToClone != '0' ? 'MainWPClone' : 'MainWPRestore') . "#title_03");

        $dirs = MainWPHelper::getMainWPDir('backup', false);
        $current_dir = $backup_dir = $dirs[0];		

        if ( isset($_REQUEST['dir']) ) {
                $current_dir = stripslashes(urldecode($_REQUEST['dir']));
                $current_dir = "/" . ltrim($current_dir, "/");
                if (!is_readable($current_dir) && get_option('mainwp_child_clone_from_server_last_folder'))
                        $current_dir = get_option('mainwp_child_clone_from_server_last_folder') . $current_dir;
        }		

        if (!is_readable($current_dir))
                $current_dir = WP_CONTENT_DIR;

        $current_dir = str_replace('\\', '/', $current_dir);

        if ( strlen($current_dir) > 1 )
                $current_dir = untrailingslashit($current_dir);

        echo "<br /><hr /><br />";
        echo '<h2 id="title_03"><strong>' . __('Option 2:', 'mainwp-child') . '</strong> ' . __('Restore/Clone From Server','mainwp-child') . '</h2>';
        echo '<div class="mainwp-child_info-box-green">' . __('If you have uploaded a FULL backup to your server (via FTP or other means) you can use this section to locate the zip file and select it.  A database only backup will not work.','mainwp-child') . '</div>';

        if (!is_readable($current_dir)) {
                echo '<div class="mainwp-child_info-box-yellow"><strong>' . __('Root directory is not readable. Please contact with site administrator to correct.','mainwp-child') . '</strong></div>';
                return;
        }
        MainWPHelper::update_option('mainwp_child_clone_from_server_last_folder', rtrim($current_dir,'/'));

        $parts = explode('/', ltrim($current_dir, '/'));						
        $dirparts = '';
        for ( $i = count($parts)-1; $i >= 0; $i-- ) {
                $part = $parts[$i];
                $adir = implode('/', array_slice($parts, 0, $i+1));
                if ( strlen($adir) > 1 )
                        $adir = ltrim($adir, '/');
                $durl = esc_url(add_query_arg(array('dir' => rawurlencode($adir) ), $url));
                $dirparts = '<a href="' . $durl . '">' . $part . DIRECTORY_SEPARATOR . '</a>' . $dirparts; 			
        }

        echo '<p>' . __('<strong>Current Directory:</strong> <span>' . $dirparts . '</span>', 'mainwp') . '</p>';
        $quick_dirs = array();		
        $quick_dirs[] = array( __('Site Root', 'mainwp'), ABSPATH );
        $quick_dirs[] = array( __('Backup', 'mainwp'), $backup_dir );
        if (($uploads = wp_upload_dir()) && false === $uploads['error'])
                $quick_dirs[] = array( __('Uploads Folder', 'mainwp'), $uploads['path']);
        $quick_dirs[] = array( __('Content Folder', 'mainwp'), WP_CONTENT_DIR );		

        $quick_links = array();		
        foreach( $quick_dirs as $dir ) {
                list( $text, $adir ) = $dir;
                $adir = str_replace('\\', '/', strtolower($adir));						
                if ( strlen($adir) > 1 )
                        $adir = ltrim($adir, '/');
                $durl = esc_url(add_query_arg(array('dir' => rawurlencode($adir)), $url));
                $quick_links[] = "<a href='$durl'>$text</a>";
        }

        if (!empty($quick_links)) {
                echo '<p><strong>' . __('Quick Jump:', 'mainwp') . '</strong> ' . implode(' | ', $quick_links) . '</p>';
        }


        $dir_files = scandir($current_dir);				
        $directories = array();
        $files = array();
        $rejected_files = array();
        foreach((array)$dir_files as $file) {
                if (in_array($file, array('.', '..')))
                        continue;
                if (is_dir( $current_dir . "/" . $file) ) 
                        $directories[] = $file;				
                else {
                        if (!MainWPHelper::isArchive($file))
                                $rejected_files[] = $file;	
                        else 	
                                $files[] = $file;
                }
        }

        sort($directories);
        sort($files);
        $parent = dirname($current_dir);				
        ?>		

            <form method="post" action="">
            <div class="mainwp-child_select_sites_box" id="mainwp_child_select_files_from_server_box">
                    <div class="postbox">
                            <h2><?php _e('Select File','mainwp-child'); ?></h2>
                            <div class="inside">
                                    <div id="mainwp-child_clonesite_select_site">
                                            <div class="clonesite_select_site_item">                                
                                                            <div class="mainwp-child_name_label">
                                                                    <a href="<?php echo esc_url(add_query_arg(array('dir' => rawurlencode($parent)), $url)); ?>" title="<?php echo esc_attr(dirname($current_dir)) ?>"><?php _e('Parent Folder', 'mainwp') ?></a>
                                                            </div>                                
                                            </div>							

                                            <?php
                                            foreach( (array)$directories as $file  ) {
                                                    $filename = ltrim($file, '/');
                                                    $folder_url = esc_url(add_query_arg(array('dir' => rawurlencode($filename)), $url));
                                                    ?>
                                                    <div class="clonesite_select_site_item">                                
                                                            <div class="mainwp-child_name_label">
                                                                    <a href="<?php echo $folder_url ?>"><?php echo esc_html( rtrim($filename, '/') . DIRECTORY_SEPARATOR ); ?></a>
                                                            </div>	
                                                    </div>
                                                    <?php
                                            }

                                            foreach ($files as $file)
                                            {
                                                    ?>
                                                    <div class="clonesite_select_site_item">                                
                                                            <div class="mainwp-child_name_label">										
                                                                    <span><?php echo esc_html($file) ?></span>									
                                                            </div>                                
                                                    </div>
                                                    <?php
                                            }

                                            foreach ($rejected_files as $file)
                                            {
                                                    ?>
                                                    <div class="mainwp_rejected_files">                                
                                                            <div class="mainwp-child_name_label">										
                                                                    <span><?php echo esc_html($file) ?></span>									
                                                            </div>                                
                                                    </div>
                                                    <?php
                                            }

                                            ?>
                                    </div>
                            </div>
                            <div class="mainwp-child_clonebutton_container"><a href="#" id="mainwp-child_clonebutton_from_server" class="button-primary"><?php _e('Clone/Restore Website','mainwp-child'); ?></a></div>
                            <div style="clear:both"></div>
                    </div>
            </div>
        </form>
        <input type="hidden" id="clonesite_from_server_current_dir" value="<?php echo $current_dir; ?>" />
        <?php			
    }
	
    public static function renderJavaScript()
    {
        $uploadSizeInBytes = min(MainWPHelper::return_bytes(ini_get('upload_max_filesize')), MainWPHelper::return_bytes(ini_get('post_max_size')));
        $uploadSize = MainWPHelper::human_filesize($uploadSizeInBytes);
?>
    <div id="mainwp-child_clone_status" title="Restore process"></div>
    <script language="javascript">
        jQuery(document).on('change', '#file', function()
        {
            var maxSize = <?php echo $uploadSizeInBytes; ?>;
            var humanSize = '<?php echo $uploadSize; ?>';

            if (this.files[0].size > maxSize)
            {
                jQuery('#filesubmit').attr('disabled', 'disabled');
                alert('The selected file is bigger than your maximum allowed filesize. (Maximum: '+humanSize+')');
            }
            else
            {
                jQuery('#filesubmit').removeAttr('disabled');
            }
        });
        jQuery(document).on('click', '#mainwp-child_displayby_sitename', function() {
            jQuery('#mainwp-child_displayby_url').removeClass('mainwp-child_action_down');
            jQuery(this).addClass('mainwp-child_action_down');
            jQuery('.mainwp-child_url_label').hide();
            jQuery('.mainwp-child_name_label').show();
            return false;
        });
        jQuery(document).on('click', '#mainwp-child_displayby_url', function() {
            jQuery('#mainwp-child_displayby_sitename').removeClass('mainwp-child_action_down');
            jQuery(this).addClass('mainwp-child_action_down');
            jQuery('.mainwp-child_name_label').hide();
            jQuery('.mainwp-child_url_label').show();
            return false;
        });
        jQuery(document).on('click', '.clonesite_select_site_item', function() {
            jQuery('.clonesite_select_site_item').removeClass('selected');
            jQuery(this).addClass('selected');
        });

        var pollingCreation = undefined;
        var backupCreationFinished = false;

        var pollingDownloading = undefined;
        var backupDownloadFinished = false;

        handleCloneError = function(resp)
        {
            updateClonePopup(resp.error, true, 'red');
        };

        updateClonePopup = function(pText, pShowDate, pColor)
        {
            if (pShowDate == undefined) pShowDate = true;

            var theDiv = jQuery('#mainwp-child_clone_status');
            theDiv.append('<br /><span style="color: ' + pColor + ';">' + (pShowDate ? cloneDateToHMS(new Date()) + ' ' : '') + pText + '</span>');
            theDiv.animate({scrollTop: theDiv.height() * 2}, 100);
        };

        cloneDateToHMS = function(date) {
            var h = date.getHours();
            var m = date.getMinutes();
            var s = date.getSeconds();
            return '' + (h <= 9 ? '0' + h : h) + ':' + (m<=9 ? '0' + m : m) + ':' + (s <= 9 ? '0' + s : s);
        };

        var translations = [] ;
        translations['large_site'] = '<?php _e('This is a large site (%dMB), the restore process will more than likely fail.', 'mainwp-child'); ?>';
        translations['continue_anyway'] = '<?php _e('Continue Anyway?', 'mainwp-child'); ?>';
        translations['creating_backup'] = '<?php _e('Creating backup on %s expected size: %dMB (estimated time: %d seconds)', 'mainwp-child'); ?>';
        translations['backup_created'] = '<?php _e('Backup created on %s total size to download: %dMB', 'mainwp-child'); ?>';
        translations['downloading_backup'] = '<?php _e('Downloading backup', 'mainwp-child'); ?>';
        translations['backup_downloaded'] = '<?php _e('Backup downloaded', 'mainwp-child'); ?>';
        translations['extracting_backup'] = '<?php _e('Extracting backup and updating your database, this might take a while. Please be patient.', 'mainwp-child'); ?>';
        translations['clone_complete'] = '<?php _e('Cloning process completed successfully!', 'mainwp-child'); ?>';

        cloneInitiateBackupCreation = function(siteId, siteName, size, rand, continueAnyway)
        {
            if ((continueAnyway == undefined) && (size > 256))
            {
                updateClonePopup(mwp_sprintf(translations['large_site'], size)+' <a href="#" class="button continueCloneButton" onClick="cloneInitiateBackupCreation('+"'"+siteId+"'"+', '+"'"+siteName+"'"+', '+size+', '+"'"+rand+"'"+', true); return false;">'+translations['continue_anyway']+'</a>');
                return;
            }
            else
            {
                jQuery('.continueCloneButton').hide();
            }

            size = size / 2.4; //Guessing how large the zip will be

            //5 mb every 10 seconds
            updateClonePopup(mwp_sprintf(translations['creating_backup'], siteName, size.toFixed(2), (size / 5 * 3).toFixed(2)));

            updateClonePopup('<div id="mainwp-child-clone-create-progress" style="margin-top: 1em !important;"></div>', false);
            jQuery('#mainwp-child-clone-create-progress').progressbar({value: 0, max: (size * 1024)});

            var data = {
                action:'mainwp-child_clone_backupcreate',
                siteId: siteId,
                rand: rand
            };

            jQuery.post(ajaxurl, data, function(pSiteId, pSiteName) { return function(resp) {
                backupCreationFinished = true;
                clearTimeout(pollingCreation);

                var progressBar = jQuery('#mainwp-child-clone-create-progress');
                progressBar.progressbar('value', parseFloat(progressBar.progressbar('option', 'max')));

                if (resp.error)
                {
                    handleCloneError(resp);
                    return;
                }
                updateClonePopup(mwp_sprintf(translations['backup_created'], pSiteName, (resp.size / 1024).toFixed(2)));
                //update view;
                cloneInitiateBackupDownload(pSiteId, resp.url, resp.size);
            } }(siteId, siteName), 'json');

            //Poll for filesize 'till it's complete
            pollingCreation = setTimeout(function() { cloneBackupCreationPolling(siteId, rand); }, 1000);
        };

        cloneBackupCreationPolling = function(siteId, rand)
        {
            if (backupCreationFinished) return;

            var data = {
                action:'mainwp-child_clone_backupcreatepoll',
                siteId: siteId,
                rand: rand
            };

            jQuery.post(ajaxurl, data, function(pSiteId, pRand) { return function(resp) {
                if (backupCreationFinished) return;
                if (resp.size)
                {
                    var progressBar = jQuery('#mainwp-child-clone-create-progress');
                    if (progressBar.progressbar('option', 'value') < progressBar.progressbar('option', 'max'))
                    {
                        progressBar.progressbar('value', resp.size);
                    }

                    //Also update estimated time?? ETA??
                }
                pollingCreation = setTimeout(function() { cloneBackupCreationPolling(pSiteId, pRand); }, 1000);
            } }(siteId, rand), 'json');
        };

        cloneInitiateBackupDownload = function(pSiteId, pFile, pSize)
        {
            updateClonePopup(translations['downloading_backup']);

            updateClonePopup('<div id="mainwp-child-clone-download-progress" style="margin-top: 1em !important;"></div>', false);
            jQuery('#mainwp-child-clone-download-progress').progressbar({value: 0, max: pSize});

            var data = {
                action:'mainwp-child_clone_backupdownload',
                file: pFile
            };

            if (pSiteId != undefined) data['siteId'] = pSiteId;

            jQuery.post(ajaxurl, data, function(siteId) { return function(resp) {
                backupDownloadFinished = true;
                clearTimeout(pollingDownloading);

                var progressBar = jQuery('#mainwp-child-clone-download-progress');
                progressBar.progressbar('value', parseFloat(progressBar.progressbar('option', 'max')));

                if (resp.error)
                {
                    handleCloneError(resp);
                    return;
                }
                updateClonePopup(translations['backup_downloaded']);

                //update view
                cloneInitiateExtractBackup();
            }}(pSiteId), 'json');

            //Poll for filesize 'till it's complete
            pollingDownloading = setTimeout(function() { cloneBackupDownloadPolling(pSiteId, pFile); }, 1000);
        };

        cloneBackupDownloadPolling = function(siteId, pFile)
        {
            if (backupDownloadFinished) return;

            var data = {
                action:'mainwp-child_clone_backupdownloadpoll',
                siteId: siteId,
                file: pFile
            };

            jQuery.post(ajaxurl, data, function(pSiteId) { return function(resp) {
                if (backupDownloadFinished) return;
                if (resp.size)
                {
                    var progressBar = jQuery('#mainwp-child-clone-download-progress');
                    if (progressBar.progressbar('option', 'value') < progressBar.progressbar('option', 'max'))
                    {
                        progressBar.progressbar('value', resp.size);
                    }
                }

                pollingDownloading = setTimeout(function() { cloneBackupDownloadPolling(pSiteId); }, 1000);
            } }(siteId), 'json');
        };

        cloneInitiateExtractBackup = function(file)
        {
            if (file == undefined) file = '';

            updateClonePopup(translations['extracting_backup']);
            //Extract & install SQL
            var data = {
                action:'mainwp-child_clone_backupextract',
                f: file
            };

            jQuery.ajax({
                type:"POST",
                url: ajaxurl,
                data: data,
                success: function(resp) {
                    if (resp.error)
                    {
                        handleCloneError(resp);
                        return;
                    }

                    updateClonePopup(translations['clone_complete']);

                    setTimeout(function() {
                    jQuery('#mainwp-child_clone_status').dialog('close');
                    jQuery('.mainwp-child_select_sites_box').hide();
                    jQuery('.mainwp-child_info-box-green').show();
                    jQuery('#mainwp-child_uploadclonebutton').hide();
                    jQuery('#mainwp-child_clonebutton').hide();
                    jQuery('.mainwp-hide-after-restore').hide();
                    }, 1000);
                },
                dataType: 'json'});
        };

        jQuery(document).on('click', '#mainwp-child-restore', function() {
            jQuery('#mainwp-child_clone_status').dialog({
                resizable: false,
                height: 400,
                width: 750,
                modal: true,
                close: function(event, ui) {bulkTaskRunning = false; jQuery('#mainwp-child_clone_status').dialog('destroy'); }});

            cloneInitiateBackupDownload(undefined, jQuery(this).attr('file'), jQuery(this).attr('size'));
            return false;
        });

        jQuery(document).on('click', '#mainwp-child_uploadclonebutton', function()
        {
            var file = jQuery(this).attr('file');
            jQuery('#mainwp-child_clone_status').dialog({
                resizable: false,
                height: 400,
                width: 750,
                modal: true,
                close: function(event, ui) {bulkTaskRunning = false; jQuery('#mainwp-child_clone_status').dialog('destroy'); }});

            cloneInitiateExtractBackup(file);
            return false;
        });

        jQuery(document).on('click', '#mainwp-child_clonebutton', function() {
            jQuery('#mainwp-child_clone_status').dialog({
                resizable: false,
                height: 400,
                width: 750,
                modal: true,
                close: function(event, ui) {bulkTaskRunning = false; jQuery('#mainwp-child_clone_status').dialog('destroy'); }});

            //Initiate backup creation on other child
            var siteElement = jQuery('.clonesite_select_site_item.selected');
            var siteId = siteElement.attr('id');
            var siteName = siteElement.find('.mainwp-child_name_label').html();
            var siteSize = siteElement.find('.mainwp-child_size_label').attr('size');
            var siteRand = siteElement.attr('rand');
            cloneInitiateBackupCreation(siteId, siteName, siteSize, siteRand);

            return false;
        });

        function mwp_sprintf()
  		{
  			if (!arguments || arguments.length < 1 || !RegExp)
  			{
  				return;
  			}
  			var str = arguments[0];
  			var re = /([^%]*)%('.|0|\x20)?(-)?(\d+)?(\.\d+)?(%|b|c|d|u|f|o|s|x|X)(.*)/;
  			var a = b = [], numSubstitutions = 0, numMatches = 0;
  			while (a = re.exec(str))
  			{
  				var leftpart = a[1], pPad = a[2], pJustify = a[3], pMinLength = a[4];
  				var pPrecision = a[5], pType = a[6], rightPart = a[7];

  				//alert(a + '\n' + [a[0], leftpart, pPad, pJustify, pMinLength, pPrecision);

  				numMatches++;
  				if (pType == '%')
  				{
  					subst = '%';
  				}
  				else
  				{
  					numSubstitutions++;
  					if (numSubstitutions >= arguments.length)
  					{
  						alert('Error! Not enough function arguments (' + (arguments.length - 1) + ', excluding the string)\nfor the number of substitution parameters in string (' + numSubstitutions + ' so far).');
  					}
  					var param = arguments[numSubstitutions];
  					var pad = '';
  					       if (pPad && pPad.substr(0,1) == "'") pad = leftpart.substr(1,1);
  					  else if (pPad) pad = pPad;
  					var justifyRight = true;
  					       if (pJustify && pJustify === "-") justifyRight = false;
  					var minLength = -1;
  					       if (pMinLength) minLength = parseInt(pMinLength);
  					var precision = -1;
  					       if (pPrecision && pType == 'f') precision = parseInt(pPrecision.substring(1));
  					var subst = param;
  					       if (pType == 'b') subst = parseInt(param).toString(2);
  					  else if (pType == 'c') subst = String.fromCharCode(parseInt(param));
  					  else if (pType == 'd') subst = parseInt(param) ? parseInt(param) : 0;
  					  else if (pType == 'u') subst = Math.abs(param);
  					  else if (pType == 'f') subst = (precision > -1) ? Math.round(parseFloat(param) * Math.pow(10, precision)) / Math.pow(10, precision): parseFloat(param);
  					  else if (pType == 'o') subst = parseInt(param).toString(8);
  					  else if (pType == 's') subst = param;
  					  else if (pType == 'x') subst = ('' + parseInt(param).toString(16)).toLowerCase();
  					  else if (pType == 'X') subst = ('' + parseInt(param).toString(16)).toUpperCase();
  				}
  				str = leftpart + subst + rightPart;
  			}
  			return str;
  		}
                
        jQuery(document).on('click', '#mainwp-child_clonebutton_from_server', function()
        {
            var cur_dir = jQuery('#clonesite_from_server_current_dir').val();    
            var file = cur_dir + '/' + jQuery('.clonesite_select_site_item.selected span').html();
            jQuery('#mainwp-child_clone_status').dialog({
                resizable: false,
                height: 400,
                width: 750,
                modal: true,
                close: function(event, ui) {bulkTaskRunning = false; jQuery('#mainwp-child_clone_status').dialog('destroy'); }});

            cloneInitiateExtractBackup(file);
            return false;
        });
        
    </script>
    <?php
    self::renderFooter();
    }

    public static function renderStyle()
    {
        ?>
    <style>
        #mainwp-child_clone_status {
            display: none;
        }
        .mainwp-child-container {
            padding-right: 10px;
            padding-top: 20px;
        }
        .mainwp-child_info-box-yellow {
            margin: 5px 0 15px;
            padding: .6em;
            background: #fff;
            border-left: 4px solid #ffec00;
            clear: both ;
            color:#333;
            box-shadow: 0 1px 1px rgba(0,0,0,.04);
        }
        .mainwp-child_info-box-red {
            margin: 5px 0 15px;
            padding: .6em;
            background: #fff;
            border-left: 4px solid #bb4539;
            clear: both ;
            color:#333;
            box-shadow: 0 1px 1px rgba(0,0,0,.04);
        }
        .mainwp-child_info-box-green {
            margin: 5px 0 15px;
            padding: .6em;
            background: #fff;
            border-left: 4px solid #7fb100;
            clear: both ;
            color:#333;
            box-shadow: 0 1px 1px rgba(0,0,0,.04);
        }
        .mainwp-child_select_sites_box {
            width: 505px;
        }
        #mainwp-child_clonesite_select_site {
            max-height: 585px !important ;
            overflow: auto;
            background: #fff ;
            width: 480px;
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

        .mainwp-child_action
        {
            text-decoration: none;
            background: none repeat scroll 0 0 #FFFFFF;
            border-color: #C9CBD1 #BFC2C8 #A9ABB1;
            border-style: solid;
            color: #3A3D46;
            display: inline-block;
            font-size: 12px;
            padding: 4px 8px;
            -webkit-box-shadow: 0 1px 0 rgba(0,0,0,0.05);
            -moz-box-shadow: 0 1px 0 rgba(0,0,0,0.05);
            box-shadow: 0 1px 0 rgba(0,0,0,0.05);
        }
        .mainwp-child_action.left
        {
            border-width: 1px 0 1px 1px;
            -webkit-border-radius: 3px 0 0 3px;
            -moz-border-radius: 3px 0 0 3px;
            border-radius: 3px 0 0 3px;
        }
        .mainwp-child_action.right
        {
            border-width: 1px 1px 1px 1px;
            -webkit-border-radius: 0 3px 3px 0;
            -moz-border-radius: 0 3px 3px 0;
            border-radius: 0 3px 3px 0;
        }
        .mainwp-child_action_down
        {
            background-image: -webkit-gradient(linear, left top, left bottom, color-stop(0%, rgba(220, 221, 225, 1)), color-stop(100%, rgba(234, 236, 241, 1)));
            background: -webkit-linear-gradient(top, rgba(220, 221, 225, 1) 0%, rgba(234, 236, 241, 1) 100%);
            background: -moz-linear-gradient(top, rgba(220, 221, 225, 1) 0%, rgba(234, 236, 241, 1) 100%);
            background: -o-linear-gradient(top, rgba(220, 221, 225, 1) 0%, rgba(234, 236, 241, 1) 100%);
            background: -ms-linear-gradient(top, rgba(220, 221, 225, 1) 0%, rgba(234, 236, 241, 1) 100%);
            background: linear-gradient(top, rgba(220, 221, 225, 1) 0%, rgba(234, 236, 241, 1) 100%);
            filter: progid:DXImageTransform.Microsoft.gradient(startColorstr = '#dcdde1', endColorstr = '#eaecf1', GradientType = 0);
            -webkit-box-shadow: 0 1px 0 rgba(255, 255, 255, 0.59), 0 2px 0 rgba(0, 0, 0, 0.05) inset;
            -moz-box-shadow: 0 1px 0 rgba(255, 255, 255, 0.59), 0 2px 0 rgba(0, 0, 0, 0.05) inset;
            box-shadow: 0 1px 0 rgba(255, 255, 255, 0.59), 0 2px 0 rgba(0, 0, 0, 0.05) inset;
            border-color: #b1b5c7 #bec2d1 #c9ccd9;
        }
        .mainwp-child_displayby {
            float: right;
            padding-top: 15px;
            padding-right: 10px;
        }
        .mainwp-child_url_label {
            display: none;
        }
        .mainwp-child_size_label {
            float: right;
            padding-right: 10px;
            font-style:italic;
            color: #8f8f8f;
        }
        .mainwp-child_clonebutton_container {
            float: right;
            padding-right: 10px;
            padding-top: 5px;
            padding-bottom: 10px;
        }
        .ui-dialog {
            padding: .5em;
            width: 600px !important;
            overflow: hidden;
            -webkit-box-shadow: 0px 0px 15px rgba(50, 50, 50, 0.45);
            -moz-box-shadow:    0px 0px 15px rgba(50, 50, 50, 0.45);
            box-shadow:         0px 0px 15px rgba(50, 50, 50, 0.45);
            background: #fff !important;
        }
        .ui-dialog .ui-dialog-titlebar { background: none; border: none;}
        .ui-dialog .ui-dialog-title { font-size: 20px; font-family: Helvetica; text-transform: uppercase; color: #555; }
        .ui-dialog h3 {font-family: Helvetica; text-transform: uppercase; color: #888; border-radius: 25px; -moz-border-radius: 25px; -webkit-border-radius: 25px;}
        .ui-dialog .ui-dialog-titlebar-close { background: none; border-radius: 15px; -moz- border-radius: 15px; -webkit- border-radius: 15px; color: #fff;}
        .ui-dialog .ui-dialog-titlebar-close:hover { background: #7fb100;}
        /*
        .ui-dialog .ui-progressbar {border:5px Solid #ddd; border-radius: 25px; -moz-border-radius: 25px; -webkit-border-radius: 25px; }
        .ui-dialog .ui-progressbar-value {
            background: #7fb100;
            border-radius: 25px;
            -moz-border-radius: 25px;
            -webkit-border-radius: 25px;
            display: inline-block;
            overflow: hidden;
            -webkit-transition: width .4s ease-in-out;
            -moz-transition: width .4s ease-in-out;
            -ms-transition: width .4s ease-in-out;
            -o-transition: width .4s ease-in-out;
            transition: width .4s ease-in-out;
        */


        #mainwp-child_clone_status .ui-progressbar {
           border:5px Solid #ddd !important;
           border-radius: 25px !important;
           -moz-border-radius: 25px !important;
           -webkit-border-radius: 25px !important;
        }

        #mainwp-child_clone_status .ui-progressbar-value {
           background: #7fb100 !important;
           border-radius: 25px!important;
           -moz-border-radius: 25px!important;
           -webkit-border-radius: 25px!important;
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
            top: 0 ;
            height: 100%; width: 100%;
            display: inline-block;


            -webkit-background-size: 30px 30px;
            -moz-background-size:    30px 30px;
            background-size:         30px 30px;
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
                0% {background-position: 0 0;} 100% {background-position: 100% 0;}
            }

            @-moz-keyframes animate-stripes {
                0% {background-position: 0 0;} 100% {background-position: 100% 0;}
            }
			
			#mainwp_child_select_files_from_server_box .mainwp-child_name_label > a{
				text-decoration: none;
			}
			
			#mainwp_child_select_files_from_server_box .mainwp_rejected_files {
				background-color: #FFE8EE;
				padding: 5px;
			}
        </style>
        <?php
    }

    public static function init_ajax()
    {
        add_action('wp_ajax_mainwp-child_clone_backupcreate', array('MainWPClone', 'cloneBackupCreate'));
        add_action('wp_ajax_mainwp-child_clone_backupcreatepoll', array('MainWPClone', 'cloneBackupCreatePoll'));
        add_action('wp_ajax_mainwp-child_clone_backupdownload', array('MainWPClone', 'cloneBackupDownload'));
        add_action('wp_ajax_mainwp-child_clone_backupdownloadpoll', array('MainWPClone', 'cloneBackupDownloadPoll'));
        add_action('wp_ajax_mainwp-child_clone_backupextract', array('MainWPClone', 'cloneBackupExtract'));
    }

    public static function cloneBackupCreate()
    {
        try
        {
            if (!isset($_POST['siteId'])) throw new Exception(__('No site given','mainwp-child'));
            $siteId = $_POST['siteId'];
            $rand = $_POST['rand'];

            $sitesToClone = get_option('mainwp_child_clone_sites');
            if (!is_array($sitesToClone) || !isset($sitesToClone[$siteId])) throw new Exception(__('Site not found','mainwp-child'));

            $siteToClone = $sitesToClone[$siteId];
            $url = $siteToClone['url'];

            $key = $siteToClone['extauth'];

            MainWPHelper::endSession();
            //Send request to the childsite!
            global $wp_version;
            $method = (function_exists('gzopen') ? 'tar.gz' : 'zip');
            $result = MainWPHelper::fetchUrl($url, array('cloneFunc' => 'createCloneBackup', 'key' => $key, 'f' => $rand, 'wpversion' => $wp_version, 'zipmethod' => $method));

            if (!$result['backup']) throw new Exception(__('Could not create backupfile on child','mainwp-child'));
            @session_start();

            MainWPHelper::update_option('mainwp_temp_clone_plugins', $result['plugins']);
            MainWPHelper::update_option('mainwp_temp_clone_themes', $result['themes']);

            $output = array('url' => $result['backup'], 'size' => round($result['size'] / 1024, 0));
        }
        catch (Exception $e)
        {
            $output = array('error' => $e->getMessage());
        }

        die(json_encode($output));
    }

    public static function cloneBackupCreatePoll()
    {
        try
        {
            if (!isset($_POST['siteId'])) throw new Exception(__('No site given','mainwp-child'));
            $siteId = $_POST['siteId'];
            $rand = $_POST['rand'];

            $sitesToClone = get_option('mainwp_child_clone_sites');
            if (!is_array($sitesToClone) || !isset($sitesToClone[$siteId])) throw new Exception(__('Site not found','mainwp-child'));

            $siteToClone = $sitesToClone[$siteId];
            $url = $siteToClone['url'];

            $key = $siteToClone['extauth'];

            MainWPHelper::endSession();
            //Send request to the childsite!
            $result = MainWPHelper::fetchUrl($url, array('cloneFunc' => 'createCloneBackupPoll', 'key' => $key, 'f' => $rand));

            if (!isset($result['size'])) throw new Exception(__('Invalid response','mainwp-child'));

            $output = array('size' => round($result['size'] / 1024, 0));
        }
        catch (Exception $e)
        {
            $output = array('error' => $e->getMessage());
        }
        //Return size in kb
        die(json_encode($output));
    }

    public static function cloneBackupDownload()
    {
        try
        {
            if (!isset($_POST['file'])) throw new Exception(__('No download link given','mainwp-child'));
//            if (!isset($_POST['siteId'])) throw new Exception(__('No site given','mainwp-child'));

            $file = $_POST['file'];
            if (isset($_POST['siteId']))
            {
                $siteId = $_POST['siteId'];

                $sitesToClone = get_option('mainwp_child_clone_sites');
                if (!is_array($sitesToClone) || !isset($sitesToClone[$siteId])) throw new Exception(__('Site not found', 'mainwp-child'));

                $siteToClone = $sitesToClone[$siteId];
                $url = $siteToClone['url'];
                $key = $siteToClone['extauth'];

                $url = trailingslashit($url) . '?cloneFunc=dl&key=' . urlencode($key) . '&f=' . $file;
            }
            else
            {
                $url = $file;
            }
            MainWPHelper::endSession();
            //Send request to the childsite!
            $split = explode('=', $file);
            $file = urldecode($split[count($split) - 1]);
            $filename = 'download-'.basename($file);
            $dirs = MainWPHelper::getMainWPDir('backup', false);
            $backupdir = $dirs[0];

            if ($dh = opendir($backupdir))
            {
                while (($file = readdir($dh)) !== false)
                {
                    if ($file != '.' && $file != '..' && MainWPHelper::isArchive($file, 'download-'))
                    {
                        @unlink($backupdir . $file);
                    }
                }
                closedir($dh);
            }

            $filename = $backupdir . $filename;

            $response = wp_remote_get($url, array( 'timeout' => 300000, 'stream' => true, 'filename' => $filename ) );

            if ( is_wp_error( $response ) ) {
           		unlink( $filename );
           		return $response;
           	}

           	if ( 200 != wp_remote_retrieve_response_code( $response ) ){
           		unlink( $filename );
           		return new WP_Error( 'http_404', trim( wp_remote_retrieve_response_message( $response ) ) );
           	}

            $output = array('done' => $filename);

            //Delete backup on child
            try
            {
                if (isset($_POST['siteId']))
                {
                    $siteId = $_POST['siteId'];
                    $sitesToClone = get_option('mainwp_child_clone_sites');
                    if (is_array($sitesToClone) && isset($sitesToClone[$siteId]))
                    {
                        $siteToClone = $sitesToClone[$siteId];

                        MainWPHelper::fetchUrl($siteToClone['url'], array('cloneFunc' => 'deleteCloneBackup', 'key' => $siteToClone['extauth'], 'f' => $_POST['file']));
                    }
                }
            }
            catch (Exception $e)
            {
                throw $e;
            }
        }
        catch (Exception $e)
        {
            $output = array('error' => $e->getMessage());
        }

        die(json_encode($output));
    }

    public static function cloneBackupDownloadPoll()
    {
        try
        {
            MainWPHelper::endSession();
            $dirs = MainWPHelper::getMainWPDir('backup', false);
            $backupdir = $dirs[0];

            $files = glob($backupdir . 'download-*');
            $archiveFile = false;
            foreach ($files as $file)
            {
                if (MainWPHelper::isArchive($file, 'download-'))
                {
                    $archiveFile = $file;
                    break;
                }
            }
            if ($archiveFile === false) throw new Exception(__('No download file found','mainwp-child'));

            $output = array('size' => filesize($archiveFile) / 1024);
        }
        catch (Exception $e)
        {
            $output = array('error' => $e->getMessage());
        }
        //return size in kb
        die(json_encode($output));
    }

    public static function cloneBackupExtract()
    {
        try
        {
            MainWPHelper::endSession();

            $file = (isset($_POST['f']) ? $_POST['f'] : $_POST['file']);
            $testFull = false;
            if ($file == '')
            {
                $dirs = MainWPHelper::getMainWPDir('backup', false);
                $backupdir = $dirs[0];

                $files = glob($backupdir . 'download-*');
                $archiveFile = false;
                foreach ($files as $file)
                {
                    if (MainWPHelper::isArchive($file, 'download-'))
                    {
                        $archiveFile = $file;
                        break;
                    }
                }
                if ($archiveFile === false) throw new Exception(__('No download file found','mainwp-child'));
                $file = $archiveFile;
            } else if(file_exists($file)) {
                $testFull = true;
            } else {
                $file = ABSPATH . $file;
                if (!file_exists($file)) throw new Exception(__('Backup file not found','mainwp-child'));
                $testFull = true;
            }
            //return size in kb
            $cloneInstall = new MainWPCloneInstall($file);

            //todo: RS: refactor to get those plugins after install (after .18 release)
            $cloneInstall->readConfigurationFile();

            $plugins = get_option('mainwp_temp_clone_plugins');
            $themes = get_option('mainwp_temp_clone_themes');

            if ($testFull)
            {
                $cloneInstall->testDownload();
            }
            $cloneInstall->removeConfigFile();
            $cloneInstall->extractBackup();

            $pubkey = get_option('mainwp_child_pubkey');
            $uniqueId = get_option('mainwp_child_uniqueId');
            $server = get_option('mainwp_child_server');
            $nonce = get_option('mainwp_child_nonce');
            $nossl = get_option('mainwp_child_nossl');
            $nossl_key = get_option('mainwp_child_nossl_key');
            $sitesToClone = get_option('mainwp_child_clone_sites');
			
            $cloneInstall->install();
            $cloneInstall->updateWPConfig();


//            $cloneInstall->update_option('mainwp_child_pubkey', $pubkey);
//            $cloneInstall->update_option('mainwp_child_uniqueId', $uniqueId);
//            $cloneInstall->update_option('mainwp_child_server', $server);
//            $cloneInstall->update_option('mainwp_child_nonce', $nonce);
//            $cloneInstall->update_option('mainwp_child_nossl', $nossl);
//            $cloneInstall->update_option('mainwp_child_nossl_key', $nossl_key);
//            $cloneInstall->update_option('mainwp_child_clone_sites', $sitesToClone);
//            $cloneInstall->update_option('mainwp_child_clone_permalink', true);
            MainWPHelper::update_option('mainwp_child_pubkey', $pubkey);
            MainWPHelper::update_option('mainwp_child_uniqueId', $uniqueId);
            MainWPHelper::update_option('mainwp_child_server', $server);
            MainWPHelper::update_option('mainwp_child_nonce', $nonce);
            MainWPHelper::update_option('mainwp_child_nossl', $nossl);
            MainWPHelper::update_option('mainwp_child_nossl_key', $nossl_key);
            MainWPHelper::update_option('mainwp_child_clone_sites', $sitesToClone);
            if (!MainWPHelper::startsWith(basename($file), 'download-backup-'))
            {
                MainWPHelper::update_option('mainwp_child_restore_permalink', true);
            }
            else
            {
                MainWPHelper::update_option('mainwp_child_clone_permalink', true);
            }
			
            $cloneInstall->clean();
            if ($plugins !== false)
            {
                $out = array();
                if (is_array($plugins))
                {
                    $dir = WP_CONTENT_DIR . '/plugins/';
                    $fh = @opendir($dir);
                    while ($entry = @readdir($fh))
                    {
                        if (!is_dir($dir . $entry)) continue;
                        if (($entry == '.') || ($entry == '..')) continue;

                        if (!in_array($entry, $plugins)) MainWPHelper::delete_dir($dir . $entry);
                    }
                    @closedir($fh);
                }

                delete_option('mainwp_temp_clone_plugins');
            }
            if ($themes !== false)
            {
                $out = array();
                if (is_array($themes))
                {
                    $dir = WP_CONTENT_DIR . '/themes/';
                    $fh = @opendir($dir);
                    while ($entry = @readdir($fh))
                    {
                        if (!is_dir($dir . $entry)) continue;
                        if (($entry == '.') || ($entry == '..')) continue;

                        if (!in_array($entry, $themes)) MainWPHelper::delete_dir($dir . $entry);
                    }
                    @closedir($fh);
                }

                delete_option('mainwp_temp_clone_themes');
            }
            $output = array('result' => 'ok');
            //todo: remove old tables if other prefix?

            wp_logout();
            wp_set_current_user(0);
        }
        catch (Exception $e)
        {
            $output = array('error' => $e->getMessage());
        }
        //return size in kb
        die(json_encode($output));
    }

    public static function permalinkChanged($action)
    {
        if ($action == 'update-permalink')
        {
            if (isset($_POST['permalink_structure']) || isset($_POST['category_base']) || isset($_POST['tag_base']))
            {
                delete_option('mainwp_child_clone_permalink');
                delete_option('mainwp_child_restore_permalink');
            }
        }
    }

    public static function permalinkAdminNotice()
    {
        if (isset($_POST['permalink_structure']) || isset($_POST['category_base']) || isset($_POST['tag_base'])) return;
        ?>
        <style>
        .mainwp-child_info-box-green {
            margin: 5px 0 15px;
            padding: .6em;
            background: rgba(127, 177, 0, 0.3);
            border: 1px solid #7fb100;
            border-radius: 3px ;
            margin-right: 10px;
            -moz-border-radius: 3px ;
            -webkit-border-radius: 3px ;
            clear: both ;
        }
        </style>
        <div class="mainwp-child_info-box-green"><?php if (get_option('mainwp_child_restore_permalink') == true) { _e('Restore process completed successfully! Check and re-save permalinks ','mainwp-child'); } else { _e('Cloning process completed successfully! Check and re-save permalinks ','mainwp-child'); } ?> <a href="<?php echo admin_url('options-permalink.php'); ?>"><?php _e('here','mainwp-child'); ?></a>.</div>
        <?php
    }

    public static function renderRestore()
    {
        if (session_id() == '') @session_start();
        $file = null;
        $size = null;
        if (isset($_SESSION['file']))
        {
            $file = $_SESSION['file'];
            $size = $_SESSION['size'];
            unset($_SESSION['file']);
            unset($_SESSION['size']);
        }

        if ($file == null)
        {
            die('<meta http-equiv="refresh" content="0;url=' . admin_url() . '">');
        }

        self::renderStyle();
        ?>
        <div id="icon-options-general" class="icon32"><br></div><h2><?php _e('Restore','mainwp-child'); ?></h2>
        <div class="mainwp-hide-after-restore">
        <br /><?php _e('Be sure to use a FULL backup created by your Network dashboard, if critical folders are excluded it may result in a not working installation.','mainwp-child'); ?>
        <br /><br /><a href="#" class="button-primary" file="<?php echo urldecode($file); ?>" size="<?php echo ($size / 1024); ?>" id="mainwp-child-restore"><?php _e('Start Restore','mainwp-child'); ?></a> <i><?php _e('CAUTION: this will overwrite your existing site.','mainwp-child'); ?></i>
        </div>
        <div class="mainwp-child_info-box-green" style="display: none;"><?php _e('Restore process completed successfully! You will now need to click ','mainwp-child'); ?> <a href="<?php echo admin_url('options-permalink.php'); ?>"><?php _e('here','mainwp-child'); ?></a><?php _e(' to re-login to the admin and re-save permalinks.','mainwp-child'); ?></div>
        <?php
        self::renderJavaScript();
        ?>
            <script type="text/javascript">translations['clone_complete'] = '<?php _e('Restore process completed successfully!', 'mainwp-child'); ?>';</script>
        <?php
    }
}