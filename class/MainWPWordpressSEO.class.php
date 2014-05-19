<?php

class MainWPWordpressSEO
{   
    public static $instance = null;
    
    static function Instance() {
        if (MainWPWordpressSEO::$instance == null) {
            MainWPWordpressSEO::$instance = new MainWPWordpressSEO();
        }
        return MainWPWordpressSEO::$instance;
    }    
    
    public function __construct() {
        global $wpdb;
        add_action('mainwp_child_deactivation', array($this, 'child_deactivation'));
    }
    
    public function child_deactivation()
    {
        $dell_all = array();
        foreach ($dell_all as $opt)
        {
            delete_option($opt);
        }
    }
    
    public function action() {        
        if (!class_exists('wpseo_admin')) {
            $information['error'] = 'NO_WPSEO';
            MainWPHelper::write($information);
        }
        $result = array();
        switch ($_POST['action']) {
            case 'import_settings':
                $information = $this->import_settings();
            break;                
        }        
        MainWPHelper::write($information);
    }  
    
    public function import_settings() {
        $file_url = base64_decode($_POST['file_url']);
        $temporary_file = "";
        try
        {           
            include_once(ABSPATH . 'wp-admin/includes/file.php'); //Contains download_url         
            $temporary_file = download_url($file_url);

            if (is_wp_error($temporary_file))
            {
                throw new Exception('Error: ' . $temporary_file->get_error_message());
            }
            else
            {
                if ($this->import_seo_settings($temporary_file))
                    $information['success'] = true;
                else 
                    throw new Exception(__( 'Settings could not be imported:', 'wordpress-seo' )); 
            }            
        }
        catch (Exception $e)
        {
            $information['error'] = $e->getMessage();            
        }
        
        if (file_exists($temporary_file))
        {
            unlink($temporary_file);
        }            
        MainWPHelper::write($information);        
    }
    
    public function import_seo_settings($file) {
	if ( !empty( $file ) ) {
            $upload_dir = wp_upload_dir();

            if ( ! defined( 'DIRECTORY_SEPARATOR' ) ) {
                    define( 'DIRECTORY_SEPARATOR', '/' );
            }
            $p_path   = $upload_dir['basedir'] . DIRECTORY_SEPARATOR . 'wpseo-import' . DIRECTORY_SEPARATOR;

            if ( ! isset( $GLOBALS['wp_filesystem'] ) || ! is_object( $GLOBALS['wp_filesystem'] ) ) {
                    WP_Filesystem();
            }

            $unzipped = unzip_file( $file, $p_path );
            if ( ! is_wp_error( $unzipped ) ) {
                $filename = $p_path . 'settings.ini';
                if ( @is_file( $filename ) && is_readable( $filename) ) {
                    $options = parse_ini_file( $filename, true );

                    if ( is_array( $options ) && $options !== array() ) {
                        $old_wpseo_version = null;
                        if ( isset( $options['wpseo']['version'] ) && $options['wpseo']['version'] !== '' ) {
                                $old_wpseo_version = $options['wpseo']['version'];
                        }
                        foreach ( $options as $name => $optgroup ) {
                            if ( $name === 'wpseo_taxonomy_meta' ) {
                                $optgroup = json_decode( urldecode( $optgroup['wpseo_taxonomy_meta'] ), true );
                            }
                            // Make sure that the imported options are cleaned/converted on import
                            $option_instance = WPSEO_Options::get_option_instance( $name );
                            if ( is_object( $option_instance ) && method_exists( $option_instance, 'import' ) ) {
                                    $optgroup = $option_instance->import( $optgroup, $old_wpseo_version, $options );
                            }
                        }
                        return true;
                    }
                    else {
                        throw new Exception(__( 'Settings could not be imported:', 'wordpress-seo' ));
                    }
                    unset( $options, $name, $optgroup );
                }
                else {
                    throw new Exception(__( 'Settings could not be imported:', 'wordpress-seo' ));
                }
                @unlink( $filename );
                @unlink( $p_path );
            }
            else {
                throw new Exception(__( 'Settings could not be imported:', 'wordpress-seo' ) . ' ' . sprintf( __( 'Unzipping failed with error "%s".', 'wordpress-seo' ), $unzipped->get_error_message() ));
            }
            unset( $zip, $unzipped );
            @unlink( $file );
	}
	else {
            throw new Exception(__( 'Settings could not be imported:', 'wordpress-seo' ) . ' ' . __( 'Upload failed.', 'wordpress-seo' )); 
	}
        return false;
    }
    
}

