<?php

/*
 *
 * Credits
 *
 * Plugin-Name: WP Staging
 * Plugin URI: https://wordpress.org/plugins/wp-staging
 * Author: WP-Staging
 * Author URI: https://wp-staging.com
 * Contributors: ReneHermi, ilgityildirim
 *
 * The code is used for the MainWP Staging Extension
 * Extension URL: https://mainwp.com/extension/staging/
 *
*/


class MainWP_Child_Staging {

    public static $instance = null;
    public $is_plugin_installed = false;

    static function Instance() {
        if ( null === MainWP_Child_Staging::$instance ) {
            MainWP_Child_Staging::$instance = new MainWP_Child_Staging();
        }
        return MainWP_Child_Staging::$instance;
    }

    public function __construct() {
        require_once( ABSPATH . 'wp-admin/includes/plugin.php' );
		if ( is_plugin_active( 'wp-staging/wp-staging.php' ) && defined('WPSTG_PLUGIN_DIR')) {
            $this->is_plugin_installed = true;
		} else if ( is_plugin_active( 'wp-staging-pro/wp-staging-pro.php' ) ) {
            $this->is_plugin_installed = true;
		}

        if (!$this->is_plugin_installed)
            return;

        add_filter( 'mainwp-site-sync-others-data', array( $this, 'syncOthersData' ), 10, 2 );
    }


	public function init() {
		if ( get_option( 'mainwp_wp_staging_ext_enabled' ) !== 'Y' )
            return;

        if (!$this->is_plugin_installed)
            return;

		if ( get_option( 'mainwp_wp_staging_hide_plugin' ) === 'hide' ) {
			add_filter( 'all_plugins', array( $this, 'all_plugins' ) );
			add_action( 'admin_menu', array( $this, 'remove_menu' ) );
			add_filter( 'site_transient_update_plugins', array( &$this, 'remove_update_nag' ) );
            add_filter( 'mainwp_child_hide_update_notice', array( &$this, 'hide_update_notice' ) );
		}
	}

	public function syncOthersData( $information, $data = array() ) {
        if ( isset( $data['syncWPStaging'] ) && $data['syncWPStaging'] ) {
            try{
                $information['syncWPStaging'] = $this->get_sync_data();
            } catch(Exception $e) {
                // do not exit
            }
        }
		return $information;
	}
    // ok
    public function get_sync_data() {
        return $this->get_overview();
    }

    public function action() {
            if (!$this->is_plugin_installed) {
                 MainWP_Helper::write( array('error' => 'Please install WP Staging plugin on child website') );
            }

            if (!class_exists( 'WPStaging\WPStaging' )){
                require_once WPSTG_PLUGIN_DIR . "apps/Core/WPStaging.php";
            }
             \WPStaging\WPStaging::getInstance();

            $information = array();
            if (get_option( 'mainwp_wp_staging_ext_enabled' ) !== 'Y') {
                MainWP_Helper::update_option( 'mainwp_wp_staging_ext_enabled', 'Y', 'yes' );
            }

            if ( isset( $_POST['mwp_action'] ) ) {
                switch ( $_POST['mwp_action'] ) {
                    case 'set_showhide':
                            $information = $this->set_showhide();
                        break;
                    case 'save_settings':
                            $information = $this->save_settings();
                        break;
                    case 'get_overview':
                            $information = $this->get_overview();
                        break;
                    case 'get_scan':
                            $information = $this->get_scan();
                        break;
                    case 'check_disk_space':
                            $information = $this->ajaxCheckFreeSpace();
                        break;
                    case 'check_clone':
                            $information = $this->ajaxCheckCloneName();
                        break;
                    case 'start_clone':
                            $information = $this->ajaxStartClone();
                        break;
                    case 'clone_database':
                            $information = $this->ajaxCloneDatabase();
                        break;
                    case 'prepare_directories':
                            $information = $this->ajaxPrepareDirectories();
                        break;
                    case 'copy_files':
                            $information = $this->ajaxCopyFiles();
                        break;
                    case 'replace_data':
                            $information = $this->ajaxReplaceData();
                        break;
                    case 'clone_finish':
                            $information = $this->ajaxFinish();
                        break;
                    case 'delete_confirmation':
                            $information = $this->ajaxDeleteConfirmation();
                        break;
                    case 'delete_clone':
                            $information = $this->ajaxDeleteClone();
                        break;
                    case 'cancel_clone':
                            $information = $this->ajaxCancelClone();
                        break;
					case 'staging_update':
                            $information = $this->ajaxUpdateProcess();
                        break;
					case 'cancel_update':
                            $information = $this->ajaxCancelUpdate();
                        break;
                }
            }
            MainWP_Helper::write( $information );
    }

    function set_showhide() {
		$hide = isset( $_POST['showhide'] ) && ( 'hide' === $_POST['showhide'] ) ? 'hide' : '';
		MainWP_Helper::update_option( 'mainwp_wp_staging_hide_plugin', $hide, 'yes' );
		$information['result'] = 'SUCCESS';
		return $information;
	}

    function save_settings() {
        $settings = $_POST['settings'];
        $filters = array(
            'queryLimit',
            'fileLimit',
            'batchSize',
            'cpuLoad',
            'delayRequests',
            'disableAdminLogin',
            'querySRLimit',
            'maxFileSize',
            //'wpSubDirectory', // removed
            'debugMode',
            'unInstallOnDelete',
            'checkDirectorySize',
			'optimizer',
			//'loginSlug' // removed
        );

        $save_fields = array();
        foreach($filters as $field) {
            if (isset($settings[$field])) {
                $save_fields[$field]  = $settings[$field];
            }
        }
        update_option('wpstg_settings', $save_fields );
        return array('result' => 'success');
    }

    public function get_overview() {
        $return = array(
            'availableClones' =>  get_option( "wpstg_existing_clones_beta", array())
        );
        return $return;
    }

    public function get_scan() {
        // Scan
        $scan = new WPStaging\Backend\Modules\Jobs\Scan();
        $scan->start();

        // Get Options
        $options = $scan->getOptions();

        $return = array(
            'options' => serialize($options),
            'directoryListing' => $scan->directoryListing(),
            'prefix' => WPStaging\WPStaging::getTablePrefix()
        );
        return $return;
   }


    public function ajaxCheckCloneName() {
      $cloneName = sanitize_key( $_POST["cloneID"] );
      $cloneNameLength = strlen( $cloneName );
      $clones = get_option( "wpstg_existing_clones_beta", array() );

      // Check clone name length
      if( $cloneNameLength < 1 || $cloneNameLength > 16 ) {
         echo array(
             "status" => "failed",
             "message" => "Clone name must be between 1 - 16 characters"
         );
      } elseif( array_key_exists( $cloneName, $clones ) ) {
         return array(
             "status" => "failed",
             "message" => "Clone name is already in use, please choose an another clone name"
         );
      }

      return array("status" => "success");
   }

   public function ajaxStartClone() {

	   $this->url = ''; // to fix warning
      $cloning = new WPStaging\Backend\Modules\Jobs\Cloning();


      if( !$cloning->save() ) {
         return;
      }

      ob_start();
      require_once WPSTG_PLUGIN_DIR . "apps/Backend/views/clone/ajax/start.php";
      $result = ob_get_clean();
      return $result;
   }

    public function ajaxCloneDatabase() {

      $cloning = new WPStaging\Backend\Modules\Jobs\Cloning();

      return $cloning->start();
   }

   /**
    * Ajax Prepare Directories (get listing of files)
    */
   public function ajaxPrepareDirectories() {

      $cloning = new WPStaging\Backend\Modules\Jobs\Cloning();

      return $cloning->start();
   }

   /**
    * Ajax Clone Files
    */
   public function ajaxCopyFiles() {

      $cloning = new WPStaging\Backend\Modules\Jobs\Cloning();

      return $cloning->start();
   }

   /**
    * Ajax Replace Data
    */
   public function ajaxReplaceData() {
      $cloning = new WPStaging\Backend\Modules\Jobs\Cloning();
      return $cloning->start();
   }

   /**
    * Ajax Finish
    */
   public function ajaxFinish() {

      $cloning = new WPStaging\Backend\Modules\Jobs\Cloning();
      $this->url = ''; // to fix warning
      $return = $cloning->start();
      $return->blogInfoName = get_bloginfo("name");

      return $return;
   }

   /**
    * Ajax Delete Confirmation
    */
   public function ajaxDeleteConfirmation() {

      $delete = new WPStaging\Backend\Modules\Jobs\Delete();
      $delete->setData();
      $clone = $delete->getClone();
      $result = array(
                    'clone' => $clone,
                    'deleteTables' => $delete->getTables()
                );
      return $result;
   }

   /**
    * Delete clone
    */
   public function ajaxDeleteClone() {

      $delete = new WPStaging\Backend\Modules\Jobs\Delete();
      return $delete->start();
   }

   /**
    * Delete clone
    */
   public function ajaxCancelClone() {
      $cancel = new WPStaging\Backend\Modules\Jobs\Cancel();
      return $cancel->start();
   }

    public function ajaxCancelUpdate() {
	  $cancel = new WPStaging\Backend\Modules\Jobs\CancelUpdate();
      return $cancel->start();
   }

   public function ajaxUpdateProcess() {

		$cloning = new WPStaging\Backend\Modules\Jobs\Updating();

		if( !$cloning->save() ) {
		   return;
		}

		ob_start();
		require_once WPSTG_PLUGIN_DIR . "apps/Backend/views/clone/ajax/update.php";
		$result = ob_get_clean();
		return $result;
   }

    public function ajaxCheckFreeSpace() {
       return $this->hasFreeDiskSpace();
    }

    // from wp-staging plugin
    public function hasFreeDiskSpace() {
      if( !function_exists( "disk_free_space" ) ) {
         return null;
      }
      $freeSpace = @disk_free_space( ABSPATH );
      if( false === $freeSpace ) {
         $data = array(
             'freespace' => false,
             'usedspace' => $this->formatSize($this->getDirectorySizeInclSubdirs(ABSPATH))
         );
         return $data;
      }
      $data = array(
          'freespace' => $this->formatSize($freeSpace),
          'usedspace' => $this->formatSize($this->getDirectorySizeInclSubdirs(ABSPATH))
      );
      return $data;
   }

    // from wp-staging plugin
    function getDirectorySizeInclSubdirs( $dir ) {
      $size = 0;
      foreach ( glob( rtrim( $dir, '/' ) . '/*', GLOB_NOSORT ) as $each ) {
         $size += is_file( $each ) ? filesize( $each ) : $this->getDirectorySizeInclSubdirs( $each );
      }
      return $size;
   }

    // from wp-staging plugin
    public function formatSize($bytes, $precision = 2)
    {
        if ((double) $bytes < 1)
        {
            return '';
        }

        $units  = array('B', "KB", "MB", "GB", "TB");

        $bytes  = (double) $bytes;
        $base   = log($bytes) / log(1000); // 1024 would be for MiB KiB etc
        $pow    = pow(1000, $base - floor($base)); // Same rule for 1000

        return round($pow, $precision) . ' ' . $units[(int) floor($base)];
    }


    public function all_plugins( $plugins ) {
		foreach ( $plugins as $key => $value ) {
			$plugin_slug = basename( $key, '.php' );
			if ( 'wp-staging' === $plugin_slug ) {
				unset( $plugins[ $key ] );
			}
		}

		return $plugins;
	}

	public function remove_menu() {
        remove_menu_page( 'wpstg_clone' );
		$pos = stripos( $_SERVER['REQUEST_URI'], 'admin.php?page=wpstg_clone' );
		if ( false !== $pos ) {
			wp_redirect( get_option( 'siteurl' ) . '/wp-admin/index.php' );
			exit();
		}
	}

    function hide_update_notice( $slugs ) {
        $slugs[] = 'wp-staging/wp-staging.php';
        return $slugs;
    }

	function remove_update_nag( $value ) {
		if ( isset( $_POST['mainwpsignature'] ) ) {
			return $value;
		}

        if (! MainWP_Helper::is_screen_with_update()) {
            return $value;
        }

		if ( isset( $value->response['wp-staging/wp-staging.php'] ) ) {
			unset( $value->response['wp-staging/wp-staging.php'] );
		}

        if ( isset( $value->response['wp-staging-pro/wp-staging-pro.php'] ) ) {
			unset( $value->response['wp-staging-pro/wp-staging-pro.php'] );
		}

		return $value;
	}
}

