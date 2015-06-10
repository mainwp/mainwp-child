<?php

class MainWPChildBackUpWordPress
{   
    public static $instance = null;   
    
    static function Instance() {        
        if (MainWPChildBackUpWordPress::$instance == null) {
            MainWPChildBackUpWordPress::$instance = new MainWPChildBackUpWordPress();
        }
        return MainWPChildBackUpWordPress::$instance;
    }    
    
    public function __construct() {                
        
    }
      
    public function init()
    {  
        if (get_option('mainwp_backupwordpress_ext_enabled') !== "Y")
            return;
        
        if (get_option('mainwp_backupwordpress_hide_plugin') === "hide")
        {
            add_filter('all_plugins', array($this, 'all_plugins'));   
            add_action('admin_menu', array($this, 'remove_menu'));            
            add_filter('site_transient_update_plugins', array(&$this, 'remove_update_nag')); 
        }        
    }
    
    function remove_update_nag($value) {
        if (isset($value->response['backupwordpress/backupwordpress.php']))
            unset($value->response['backupwordpress/backupwordpress.php']);        
        return $value;
    }
    
    public function action() {        
        $information = array();          
        if (!self::isActivated()) {
            $information['error'] = 'NO_BACKUPWORDPRESS';
            MainWPHelper::write($information);
        }   
        if (isset($_POST['mwp_action'])) {            
            switch ($_POST['mwp_action']) {                               
                case "set_showhide":
                    $information = $this->set_showhide();
                break; 
                case "delete_schedule":                    
                    $information = $this->delete_schedule();
                break;
                case "cancel_schedule":                    
                    $information = $this->hmbkp_request_cancel_backup();
                break;
                case "get_backup_status":                    
                    $information = $this->get_backup_status();
                break;
                case "reload_backupslist":
                    $information = $this->reload_backups();
                break;
                case "delete_backup":
                    $information = $this->hmbkp_request_delete_backup();
                break;
                case "run_schedule":
                    $information = $this->run_schedule();
                break;
                case "update_schedule":
                    $information = $this->update_schedule();
                break;
                case "get_excluded":
                    $information = $this->get_excluded();
                break;
                case "directory_browse":
                    $information = $this->directory_browse();
                break;
                case "exclude_add_rule":
                    $information = $this->hmbkp_add_exclude_rule();
                break;
                case "exclude_remove_rule":
                    $information = $this->hmbkp_remove_exclude_rule();
                break;
            }        
        }
        MainWPHelper::write($information);
    }
    
    
    function check_schedule() {
        $schedule_id = (isset($_POST['schedule_id']) && !empty($_POST['schedule_id'])) ? $_POST['schedule_id'] : "";                        
        if (empty($schedule_id)) {
            $information = array('error' => 'Empty schedule id');
            MainWPHelper::write($information);
        } else {        
            $schedule_id = sanitize_text_field( urldecode( $schedule_id ) );  
            HM\BackUpWordPress\Schedules::get_instance()->refresh_schedules();
            if ( ! HM\BackUpWordPress\Schedules::get_instance()->get_schedule( $schedule_id ) ) 
            {
                $information = array('result' => 'NOTFOUND');
                MainWPHelper::write($information);
            }       
        }
         
        return $schedule_id;
    }
            
    public function syncData() {
        if (!self::isActivated()) 
            return "";
        return $this->get_sync_data();
    }   
    
    private function get_sync_data() {
         HM\BackUpWordPress\Schedules::get_instance()->refresh_schedules();
         $schedules = HM\BackUpWordPress\Schedules::get_instance()->get_schedules();
         $backups_time = array();         
         foreach($schedules as $sche) {
            $existing_backup = $sche->get_backups();
            if (!empty($existing_backup)) {
                $backups_time = array_merge( $backups_time, array_keys($existing_backup));
            }            
         }   
         
         $lasttime_backup = 0;
         if(!empty($backups_time))
             $lasttime_backup = max($backups_time);
         
         $return = array('lasttime_backup' => $lasttime_backup);  
         return $return;         
    }
            
    function set_showhide() {
        MainWPHelper::update_option('mainwp_backupwordpress_ext_enabled', "Y");        
        $hide = isset($_POST['showhide']) && ($_POST['showhide'] === "hide") ? 'hide' : "";
        MainWPHelper::update_option('mainwp_backupwordpress_hide_plugin', $hide);        
        $information['result'] = 'SUCCESS';
        return $information;
    }
    
    function delete_schedule() {                       
        $schedule_id = $this->check_schedule();        
        $schedule = new HM\BackUpWordPress\Scheduled_Backup( sanitize_text_field( urldecode( $schedule_id ) ) );
        
        if ($schedule) {
            $schedule->cancel( true );
            $information['result'] = 'SUCCESS';
        } else {
            $information['result'] = 'NOTFOUND';
        }        
        return $information;
    }
  
    function hmbkp_request_cancel_backup() {
        $schedule_id = $this->check_schedule();        
        $schedule = new HM\BackUpWordPress\Scheduled_Backup( sanitize_text_field( urldecode( $schedule_id ) ) );

        // Delete the running backup
        if ( $schedule->get_running_backup_filename() && file_exists( trailingslashit( hmbkp_path() ) . $schedule->get_running_backup_filename() ) ) {
                unlink( trailingslashit( hmbkp_path() ) . $schedule->get_running_backup_filename() );
        }

        if ( $schedule->get_schedule_running_path() && file_exists( $schedule->get_schedule_running_path() ) ) {
                unlink( $schedule->get_schedule_running_path() );
        }
        HM\BackUpWordPress\Path::get_instance()->cleanup();
        
        $information['scheduleStatus'] = $schedule->get_status(); 
        $information['result'] = 'SUCCESS';
        return $information;
    }
    
    function get_backup_status() {
        $schedule_id = $this->check_schedule();        
        $schedule = new HM\BackUpWordPress\Scheduled_Backup( sanitize_text_field( urldecode( $schedule_id ) ) );

        HM\BackUpWordPress\Path::get_instance()->cleanup();
        
        $information['scheduleStatus'] = $schedule->get_status(); 
        $information['result'] = 'SUCCESS';
        return $information;
    }
    
    function run_schedule() {                       
        $schedule_id = $this->check_schedule();              
        HM\BackUpWordPress\Path::get_instance()->cleanup();
	// Fixes an issue on servers which only allow a single session per client
	session_write_close();
	     
        $task = new \HM\Backdrop\Task( 'hmbkp_run_schedule_async', $schedule_id );
        
	$task->schedule(); 
        $schedule = new HM\BackUpWordPress\Scheduled_Backup( sanitize_text_field( urldecode( $schedule_id ) ) );
        
        $information['scheduleStatus'] = $schedule->get_status();        
        $information['file_size_text'] = $this->hmbkp_get_site_size_text( $schedule );
        $information['started_ago'] = human_time_diff( $schedule->get_schedule_running_start_time());
        return array('result' => 'SUCCESS');
    }
    
    function reload_backups() {  

        $scheduleIds = isset($_POST['schedule_ids']) ? $_POST['schedule_ids'] : array();
        HM\BackUpWordPress\Schedules::get_instance()->refresh_schedules();
        
        $all_schedules_ids = array();        
        $schedules = HM\BackUpWordPress\Schedules::get_instance()->get_schedules();
        foreach($schedules as $sch) {
            $all_schedules_ids[] = $sch->get_id();
        }
        
        if (empty($all_schedules_ids))
            return array('error' => __("Not found schedules.", 'mainwp-child'));
        
        foreach ($all_schedules_ids as $schedule_id ) {
            if ( ! HM\BackUpWordPress\Schedules::get_instance()->get_schedule( $schedule_id ) ) 
                continue;     
            
                $schedule = new HM\BackUpWordPress\Scheduled_Backup( sanitize_text_field( urldecode( $schedule_id ) ) );               
                
                $out = array(
                    'b' => $this->get_backupslist_html($schedule),
                    'count' =>  count($schedule->get_backups()),
                    'file_size_text' => $this->hmbkp_get_site_size_text( $schedule ),
                    'scheduleStatus' => $schedule->get_status(),
                    'started_ago' => human_time_diff( $schedule->get_schedule_running_start_time())
                );
                
                $information['backups'][$schedule_id] = $out;
        }
        
        $send_back_schedules = array();
        
        $schedules = HM\BackUpWordPress\Schedules::get_instance()->get_schedules();
        foreach($schedules as $schedule) {
            $sch_id = $schedule->get_id();
            if (!in_array($sch_id, $scheduleIds)) {
                 $current_option = get_option( 'hmbkp_schedule_' . $sch_id );
                 if (is_array($current_option)) {
                     unset($current_option['excludes']); // not send this value
                     $send_back_schedules[$sch_id] = array(
                                                    'options' => $current_option,
                                                    'b' => $this->get_backupslist_html($schedule),
                                                    'count' =>  count($schedule->get_backups()),
                                                    'file_size_text' => $this->hmbkp_get_site_size_text( $schedule ),                                                    
                                                    'scheduleStatus' => $schedule->get_status(),
                                                    'started_ago' => human_time_diff( $schedule->get_schedule_running_start_time())
                                                );
                 }  
            }
            
        }        
             
        $information['backups_path'] = str_replace( HM\BackUpWordPress\Backup::get_home_path(), '', hmbkp_path() );               
        $information['send_back_schedules'] = $send_back_schedules;        
        $information['result'] = 'SUCCESS';
        return $information;
    }
    
    function hmbkp_request_delete_backup() {
        if (!isset($_POST['hmbkp_backuparchive']) || empty($_POST['hmbkp_backuparchive']))
            return array('error' => __("Error data.", 'mainwp-child'));

        $schedule_id = $this->check_schedule();    

        $schedule = new HM\BackUpWordPress\Scheduled_Backup( sanitize_text_field( urldecode( $schedule_id ) ) );

        $deleted = $schedule->delete_backup( sanitize_text_field( base64_decode( $_POST['hmbkp_backuparchive'] ) ) );

        if ( is_wp_error( $deleted ) ) {
            return array( 'error' => $deleted->get_error_message());
        }
                
        return array('result' => 'SUCCESS', 
                        'b' => $this->get_backupslist_html($schedule),
                        'count' =>  count($schedule->get_backups()),
                        'file_size_text' => $this->hmbkp_get_site_size_text( $schedule ),
                        'scheduleStatus' => $schedule->get_status(),            
                    );                    
    }
    
    function get_backupslist_html($schedule) {
        ob_start();
        ?>        
        <table class="widefat">

                    <thead>

                            <tr>

                                    <th scope="col"><?php hmbkp_backups_number( $schedule ); ?></th>
                                    <th scope="col"><?php _e( 'Size', 'mainwp-backupwordpress-extension' ); ?></th>
                                    <th scope="col"><?php _e( 'Type', 'mainwp-backupwordpress-extension' ); ?></th>
                                    <th scope="col"><?php _e( 'Actions', 'mainwp-backupwordpress-extension' ); ?></th>

                            </tr>

                    </thead>

                    <tbody>

                            <?php if ( $schedule->get_backups() ) {

                                    $schedule->delete_old_backups();

                                    foreach ( $schedule->get_backups() as $file ) {

                                            if ( ! file_exists( $file ) ) {
                                                    continue;
                                            }

                                            $this->hmbkp_get_backup_row( $file, $schedule );

                                    }

                            } else { ?>

                                    <tr>
                                            <td class="hmbkp-no-backups" colspan="4"><?php _e( 'This is where your backups will appear once you have some.', 'mainwp-backupwordpress-extension' ); ?></td>
                                    </tr>

                            <?php } ?>

                    </tbody>

        </table>
        <?php
        $html = ob_get_clean();
        return $html;
    }  
    
    
    function hmbkp_get_site_size_text( HM\BackUpWordPress\Scheduled_Backup $schedule ) {

            if ( ( 'database' === $schedule->get_type() ) || $schedule->is_site_size_cached() ) {

                    return sprintf( '(<code title="' . __( 'Backups will be compressed and should be smaller than this.', 'mainwp-backupwordpress-extension' ) . '">%s</code>)', esc_attr( $schedule->get_formatted_site_size() ) );

            } else {

                    return sprintf( '(<code class="calculating" title="' . __( 'this shouldn\'t take long&hellip;', 'mainwp-backupwordpress-extension' ) . '">' . __( 'calculating the size of your backup&hellip;', 'mainwp-backupwordpress-extension' ) . '</code>)' );

            }

    }
    
    function hmbkp_get_backup_row( $file, HM\BackUpWordPress\Scheduled_Backup $schedule ) {

	$encoded_file = urlencode( base64_encode( $file ) );
	$offset       = get_option( 'gmt_offset' ) * 3600;

	?>

	<tr class="hmbkp_manage_backups_row">

		<th scope="row">
			<?php echo esc_html( date_i18n( get_option( 'date_format' ) . ' - ' . get_option( 'time_format' ), @filemtime( $file ) + $offset ) ); ?>
		</th>

		<td class="code">
			<?php echo esc_html( size_format( @filesize( $file ) ) ); ?>
		</td>

		<td><?php echo esc_html( hmbkp_human_get_type( $file, $schedule ) ); ?></td>

		<td>

			<?php if (  hmbkp_is_path_accessible( hmbkp_path() )  ) : 
                            ?>
				<a href="#" onclick="event.preventDefault(); mainwp_backupwp_download_backup('<?php echo $encoded_file; ?>', <?php echo  $schedule->get_id(); ?>, this);" class="download-action"><?php _e( 'Download', 'backupwordpress' ); ?></a> |
			<?php endif; ?>

			<a href="#" onclick="event.preventDefault(); mainwp_backupwp_delete_backup('<?php echo $encoded_file; ?>', <?php echo  $schedule->get_id(); ?>, this);"  class="delete-action"><?php _e( 'Delete', 'backupwordpress' ); ?></a>

		</td>

	</tr>

<?php }

    function get_excluded($browse_dir = null) {
        
        $schedule_id = $this->check_schedule();        
        $schedule = new HM\BackUpWordPress\Scheduled_Backup( sanitize_text_field( urldecode( $schedule_id ) ) );
        ob_start();
        
        ?>
    <div class="hmbkp-exclude-settings">

	<?php if ( $schedule->get_excludes() ) : ?>

		<h3>
			<?php _e( 'Currently Excluded', 'backupwordpress' ); ?>
		</h3>

		<p><?php _e( 'We automatically detect and ignore common <abbr title="Version Control Systems">VCS</abbr> folders and other backup plugin folders.', 'backupwordpress' ); ?></p>

		<table class="widefat">

			<tbody>

			<?php foreach ( array_diff( $schedule->get_excludes(), $schedule->backup->default_excludes() ) as $key => $exclude ) :

				$exclude_path = new SplFileInfo( trailingslashit( $schedule->backup->get_root() ) . ltrim( str_ireplace( $schedule->backup->get_root(), '', $exclude ), '/' ) ); ?>

				<tr>

					<th scope="row">

						<?php if ( $exclude_path->isFile() ) { ?>

							<div class="dashicons dashicons-media-default"></div>

						<?php } elseif ( $exclude_path->isDir() ) { ?>

							<div class="dashicons dashicons-portfolio"></div>

						<?php } ?>

					</th>

					<td>
						<code><?php echo esc_html( str_ireplace( $schedule->backup->get_root(), '', $exclude ) ); ?></code>

					</td>

					<td>

						<?php if ( ( in_array( $exclude, $schedule->backup->default_excludes() ) ) || ( hmbkp_path() === untrailingslashit( $exclude ) ) ) : ?>

							<?php _e( 'Default rule', 'backupwordpress' ); ?>

						<?php elseif ( defined( 'HMBKP_EXCLUDE' ) && false !== strpos( HMBKP_EXCLUDE, $exclude ) ) : ?>

							<?php _e( 'Defined in wp-config.php', 'backupwordpress' ); ?>

						<?php else : ?>

							<a href="#" onclick="event.preventDefault(); mainwp_backupwp_remove_exclude_rule('<?php echo $exclude; ?>', this);"  class="delete-action"><?php _e( 'Stop excluding', 'backupwordpress' ); ?></a>

						<?php endif; ?>

					</td>

				</tr>

			<?php endforeach; ?>

			</tbody>

		</table>

	<?php endif; ?>

	<h3 id="directory-listing"><?php _e( 'Directory Listing', 'backupwordpress' ); ?></h3>

	<p><?php _e( 'Here\'s a directory listing of all files on your site, you can browse through and exclude files or folders that you don\'t want included in your backup.', 'backupwordpress' ); ?></p>

	<?php

	// The directory to display
	$directory = $schedule->backup->get_root();

	if ( isset( $browse_dir ) ) {

		$untrusted_directory = urldecode( $browse_dir) ;

		// Only allow real sub directories of the site root to be browsed
		if ( false !== strpos( $untrusted_directory, $schedule->backup->get_root() ) && is_dir( $untrusted_directory ) ) {
			$directory = $untrusted_directory;
		}

	}

	$exclude_string = $schedule->backup->exclude_string( 'regex' );

	// Kick off a recursive filesize scan
	$files = $schedule->list_directory_by_total_filesize( $directory );

	if ( $files ) { ?>

            <table class="widefat">
                <thead>
                <tr>
                        <th></th>
                        <th scope="col"><?php _e( 'Name', 'backupwordpress' ); ?></th>
                        <th scope="col" class="column-format"><?php _e( 'Size', 'backupwordpress' ); ?></th>
                        <th scope="col" class="column-format"><?php _e( 'Permissions', 'backupwordpress' ); ?></th>
                        <th scope="col" class="column-format"><?php _e( 'Type', 'backupwordpress' ); ?></th>
                        <th scope="col" class="column-format"><?php _e( 'Status', 'backupwordpress' ); ?></th>
                </tr>

                <tr>

                        <th scope="row">
                                <div class="dashicons dashicons-admin-home"></div>
                        </th>

                        <th scope="col">

                                <?php if ( $schedule->backup->get_root() !== $directory ) { 
                                        // echo esc_url( remove_query_arg( 'hmbkp_directory_browse' ) );                                     
                                        ?>
                                        <a href="#" onclick="event.preventDefault(); mainwp_backupwp_directory_browse('', this)"><?php echo esc_html( $schedule->backup->get_root() ); ?></a>
                                        <code>/</code>

                                        <?php $parents = array_filter( explode( '/', str_replace( trailingslashit( $schedule->backup->get_root() ), '', trailingslashit( dirname( $directory ) ) ) ) );

                                        foreach ( $parents as $directory_basename ) { ?>

                                                <a href="#" onclick="event.preventDefault(); mainwp_backupwp_directory_browse('<?php echo urlencode( substr( $directory, 0, strpos( $directory, $directory_basename ) ) . $directory_basename ); ?>', this)" ><?php echo esc_html( $directory_basename ); ?></a>
                                                <code>/</code>

                                        <?php } ?>

                                        <?php echo esc_html( basename( $directory ) ); ?>

                                <?php } else { ?>

                                        <?php echo esc_html( $schedule->backup->get_root() ); ?>

                                <?php } ?>

                        </th>

                        <td class="column-filesize">

                                <?php if ( $schedule->is_site_size_being_calculated() ) { ?>

                                        <span class="spinner"></span>

                                <?php } else {

                                        $root = new SplFileInfo( $schedule->backup->get_root() );

                                        $size = $schedule->filesize( $root, true );

                                        if ( false !== $size ) {

                                                $size = size_format( $size );

                                                if ( ! $size ) {
                                                        $size = '0 B';
                                                } ?>

                                                <code>

                                                        <?php echo esc_html( $size ); ?>

                                                        <a class="dashicons dashicons-update"
                                                           href="<?php echo wp_nonce_url( add_query_arg( 'hmbkp_recalculate_directory_filesize', urlencode( $schedule->backup->get_root() ) ), 'hmbkp-recalculate_directory_filesize' ); ?>"><span><?php _e( 'Refresh', 'backupwordpress' ); ?></span></a>

                                                </code>


                                        <?php } ?>

                                <?php } ?>

                        <td>
                                <?php echo esc_html( substr( sprintf( '%o', fileperms( $schedule->backup->get_root() ) ), - 4 ) ); ?>
                        </td>

                        <td>

                                <?php if ( is_link( $schedule->backup->get_root() ) ) {

                                        _e( 'Symlink', 'backupwordpress' );

                                } elseif ( is_dir( $schedule->backup->get_root() ) ) {

                                        _e( 'Folder', 'backupwordpress' );

                                } ?>

                        </td>

                        <td></td>

                </tr>

                </thead>

                <tbody>

                <?php foreach ( $files as $size => $file ) {

                        $is_excluded = $is_unreadable = false;

                        // Check if the file is excluded
                        if ( $exclude_string && preg_match( '(' . $exclude_string . ')', str_ireplace( trailingslashit( $schedule->backup->get_root() ), '', HM\BackUpWordPress\Backup::conform_dir( $file->getPathname() ) ) ) ) {
                                $is_excluded = true;
                        }

                        // Skip unreadable files
                        if ( ! @realpath( $file->getPathname() ) || ! $file->isReadable() ) {
                                $is_unreadable = true;
                        } ?>

                        <tr>

                                <td>

                                        <?php if ( $is_unreadable ) { ?>

                                                <div class="dashicons dashicons-dismiss"></div>

                                        <?php } elseif ( $file->isFile() ) { ?>

                                                <div class="dashicons dashicons-media-default"></div>

                                        <?php } elseif ( $file->isDir() ) { ?>

                                                <div class="dashicons dashicons-portfolio"></div>

                                        <?php } ?>

                                </td>

                                <td>

                                        <?php if ( $is_unreadable ) { ?>

                                                <code class="strikethrough"
                                                      title="<?php echo esc_attr( $file->getRealPath() ); ?>"><?php echo esc_html( $file->getBasename() ); ?></code>

                                        <?php } elseif ( $file->isFile() ) { ?>

                                                <code
                                                        title="<?php echo esc_attr( $file->getRealPath() ); ?>"><?php echo esc_html( $file->getBasename() ); ?></code>

                                        <?php } elseif ( $file->isDir() ) { 
                                                //echo add_query_arg( 'hmbkp_directory_browse', urlencode( $file->getPathname() ) ); 
                                                ?>
                                                <code title="<?php echo esc_attr( $file->getRealPath() ); ?>"><a
                                                        href="#" onclick="event.preventDefault(); mainwp_backupwp_directory_browse('<?php echo urlencode( $file->getPathname()); ?>', this)" ><?php echo esc_html( $file->getBasename() ); ?></a></code>

                                        <?php } ?>

                                </td>

                                <td class="column-format column-filesize">

                                        <?php if ( $file->isDir() && $schedule->is_site_size_being_calculated() ) { ?>

                                                <span class="spinner"></span>

                                        <?php } else {

                                                $size = $schedule->filesize( $file );

                                                if ( false !== $size ) {

                                                        $size = size_format( $size );

                                                        if ( ! $size ) {
                                                                $size = '0 B';
                                                        } ?>

                                                        <code>

                                                                <?php echo esc_html( $size ); ?>

                                                                <?php if ( $file->isDir() ) { ?>

                                                                        <a title="<?php _e( 'Recalculate the size of this directory', 'backupwordpress' ); ?>"
                                                                           class="dashicons dashicons-update"
                                                                           href="<?php echo wp_nonce_url( add_query_arg( 'hmbkp_recalculate_directory_filesize', urlencode( $file->getPathname() ) ), 'hmbkp-recalculate_directory_filesize' ); ?>"><span><?php _e( 'Refresh', 'backupwordpress' ); ?></span></a>

                                                                <?php } ?>

                                                        </code>


                                                <?php } else { ?>

                                                        <code>--</code>

                                                <?php }
                                        } ?>

                                </td>

                                <td>
                                        <?php echo esc_html( substr( sprintf( '%o', $file->getPerms() ), - 4 ) ); ?>
                                </td>

                                <td>

                                        <?php if ( $file->isLink() ) { ?>

                                                <span
                                                        title="<?php echo esc_attr( $file->GetRealPath() ); ?>"><?php _e( 'Symlink', 'backupwordpress' ); ?></span>

                                        <?php } elseif ( $file->isDir() ) {

                                                _e( 'Folder', 'backupwordpress' );

                                        } else {

                                                _e( 'File', 'backupwordpress' );

                                        } ?>

                                </td>

                                <td class="column-format">

                                        <?php if ( $is_unreadable ) { ?>

                                                <strong
                                                        title="<?php _e( 'Unreadable files won\'t be backed up.', 'backupwordpress' ); ?>"><?php _e( 'Unreadable', 'backupwordpress' ); ?></strong>

                                        <?php } elseif ( $is_excluded ) { ?>

                                                <strong><?php _e( 'Excluded', 'backupwordpress' ); ?></strong>

                                        <?php } else {

                                                $exclude_path = $file->getPathname();

                                                // Excluded directories need to be trailingslashed
                                                if ( $file->isDir() ) {
                                                        $exclude_path = trailingslashit( $file->getPathname() );
                                                } 
                                                
                                                ?>

                                                <a href="#" onclick="event.preventDefault(); mainwp_backupwp_exclude_add_rule('<?php echo urlencode( $exclude_path ); ?>', this)"
                                                   class="button-secondary"><?php _e( 'Exclude &rarr;', 'backupwordpress' ); ?></a>

                                        <?php } ?>

                                </td>

                        </tr>

                <?php } ?>
                </tbody>
        </table>

                <?php } ?>

                <p class="submit">
                        <a href="#" onclick="event.preventDefault(); mainwp_backupwp_edit_exclude_done()"
                           class="button-primary"><?php _e( 'Done', 'backupwordpress' ); ?></a>
                </p>

        </div>

        <?php
        $output = ob_get_clean();        
        $information['e'] = $output;       
        return $information;
        
    }
     
    function directory_browse() {
        $browse_dir = $_POST['browse_dir'];    
        $out = array();
        $return = $this->get_excluded($browse_dir);        
        $out['e'] = $return['e'];        
        $out['current_browse_dir'] = $browse_dir;
        return $out;        
    }
    
    function hmbkp_add_exclude_rule() {

            if ( ! isset( $_POST['exclude_pathname'] ) || empty( $_POST['exclude_pathname']) ) {
                    return array('error' => __("Error: Empty exclude directory path."));
            }
            
            $schedule_id = $this->check_schedule();        
            $schedule = new HM\BackUpWordPress\Scheduled_Backup( sanitize_text_field( $schedule_id ) );
            
            $exclude_rule = urldecode($_POST['exclude_pathname']);

            $schedule->set_excludes( $exclude_rule, true );

            $schedule->save();
            
            $current_path = urldecode($_POST['browse_dir']);
            
            if (empty($current_path))
                $current_path = null;
            
            $return = $this->get_excluded($current_path); 
            $out['e'] = $return['e'];            
            $out['current_browse_dir'] = $_POST['browse_dir'];
            return $out;

    }

    function hmbkp_remove_exclude_rule() {
  
            if ( ! isset( $_POST['remove_rule'] ) || empty( $_POST['remove_rule']) ) {
                return array('error' => __("Error: Empty exclude directory path."));
            }
            
            $schedule_id = $this->check_schedule();        
            $schedule = new HM\BackUpWordPress\Scheduled_Backup( sanitize_text_field( $schedule_id ) );
            
            $excludes = $schedule->get_excludes();

            $schedule->set_excludes( array_diff( $excludes, (array) stripslashes( sanitize_text_field( $_POST['remove_rule'] ) ) ) );

            $schedule->save();

            $current_path = urldecode($_POST['browse_dir']);
            
            if (empty($current_path))
                $current_path = null;
            
            $return = $this->get_excluded($current_path); 
            
            $out['e'] = $return['e'];            
            $out['current_browse_dir'] = $_POST['browse_dir'];            
            return $out;
    }

    function update_schedule() {                     
        $sch_id = isset($_POST['schedule_id']) ? $_POST['schedule_id'] : 0;
        $sch_id = sanitize_text_field( urldecode( $sch_id ));
        $options = isset($_POST['options']) ? unserialize(base64_decode($_POST['options'])) : false;        

        if (!is_array($options) || empty($options) || empty($sch_id))
            return array('error' => 'Error: Schedule data');        
        
        $current_value = get_option( 'hmbkp_schedule_' . $sch_id );
        if (is_array($current_value) && isset($current_value['excludes'])) {
            // do not update 'excludes' value
            $options['excludes'] =  $current_value['excludes'];
        }
        
        $out = array();
        if ( is_array($options)) {          
            update_option( 'hmbkp_schedule_' . $sch_id, $options );
            $out['result'] = 'SUCCESS';                
        } else {
            $out['result'] = 'NOTCHANGE';
        }   
        
        $schedule = new HM\BackUpWordPress\Scheduled_Backup( $sch_id );        
        
        if ( ! empty( $options['reoccurrence'] ) && ! empty( $options['schedule_start_time'] ) ) {
               // Calculate the start time depending on the recurrence
               $start_time = $options['schedule_start_time'];  
               if ( $start_time ) {
                       $schedule->set_schedule_start_time( $start_time );
               }
        }
        
        if ( ! empty( $options['reoccurrence'] ) ) {
               $schedule->set_reoccurrence( $options['reoccurrence'] );
        }
        $out['next_occurrence'] = $schedule->get_next_occurrence(false);

        return $out;
    }
    
    public static function isActivated() {
        if (!defined('HMBKP_PLUGIN_PATH') || !class_exists('HM\BackUpWordPress\Plugin')) 
            return false;
        return true;
    }
      
   
    public function all_plugins($plugins) {
        foreach ($plugins as $key => $value)
        {
            $plugin_slug = basename($key, '.php');
            if ($plugin_slug == 'backupwordpress')
                unset($plugins[$key]);
        }
        return $plugins;       
    }
    
    public function remove_menu() {
        global $submenu;        
        if (isset($submenu['tools.php'])) {
            foreach($submenu['tools.php'] as $index => $item) {
                if ($item[2] == 'backupwordpress') {
                    unset($submenu['tools.php'][$index]);
                    break;
                }
            }
        }        
        
        $pos = stripos($_SERVER['REQUEST_URI'], 'tools.php?page=backupwordpress');        
        if ($pos !== false) {
            wp_redirect(get_option('siteurl') . '/wp-admin/index.php'); 
            exit();
        }
    }         
}

