<?php

/*
Plugin Name: Vendi Abandoned Plugin Check
Description: Provides information about abandoned plugins.
Version: 3.1.1
License: GPLv2
Author: Vendi Advertising (Chris Haas)
*/

class MainWPChildThemesCheck
{   
 
    public static $instance = null;  
    
    private $cron_name_watcher = 'mainwp_child_cron_theme_health_check_watcher';

    private $cron_name_daily = 'mainwp_child_cron_theme_health_check_daily';

    private $cron_name_batching = 'mainwp_child_cron_theme_health_check_batching';

    private $tran_name_theme_timestamps = 'mainwp_child_tran_name_theme_timestamps';

    private $tran_name_themes_to_batch = 'mainwp_child_tran_name_themes_to_batch';

    private $option_name_last_daily_run = 'mainwp_child_theme_last_daily_run';
    
    public static function Instance() {        
        if (MainWPChildThemesCheck::$instance === null) {
            MainWPChildThemesCheck::$instance = new MainWPChildThemesCheck();
        }
        return MainWPChildThemesCheck::$instance;
    }      

    public function __construct()
    {              
       $this->schedule_watchdog();        
        add_action( $this->cron_name_batching, array( $this, 'run_check' ) );
        add_action( $this->cron_name_daily, array( $this, 'run_check' ) );

        add_action( $this->cron_name_watcher, array( $this, 'perform_watchdog' ) );

        add_filter( 'themes_api_args', array( $this, 'modify_theme_api_search_query' ), 10, 2 );

        add_action('mainwp_child_deactivation', array($this, 'cleanup_deactivation'));        
    }
    
    private function cleanup_basic()
    {
        wp_clear_scheduled_hook( $this->cron_name_daily );
        wp_clear_scheduled_hook( $this->cron_name_batching );
        delete_transient( $this->tran_name_themes_to_batch );
    }


    public function cleanup_deactivation($del = true)
    {
        $this->cleanup_basic();
        wp_clear_scheduled_hook( $this->cron_name_watcher );       
        delete_option( $this->option_name_last_daily_run );
        if ($del)
            delete_transient( $this->tran_name_theme_timestamps );
    }

    
    public function modify_theme_api_search_query( $args, $action )
    {
        if( isset( $action ) && 'query_themes' === $action )
        {

            if( ! is_object( $args ) )
            {
                $args = new \stdClass();
            }

            if( ! property_exists( $args, 'fields' ) )
            {
                $args->fields = array();
            }

            $args->fields = array_merge( $args->fields, array( 'last_updated' => true ) );
        }

        return $args;
    }

    public function perform_watchdog()
    {
        if ( false === wp_next_scheduled( $this->cron_name_daily ) && false === wp_next_scheduled( $this->cron_name_batching ) )
        {
            $last_run = get_option( $this->option_name_last_daily_run );

            if( false === $last_run || ! is_integer( $last_run ) )
            {
                $last_run = false;
            }
            else
            {
                $last_run = new \DateTime( '@' . $last_run );
            }

            //Get now
            $now = new \DateTime();

            if( false === $last_run || (int)$now->diff( $last_run )->format( '%h' ) >= 24 )
            {
                $this->cleanup_basic( );

                wp_schedule_event( time(), 'daily', $this->cron_name_daily );

                update_option( $this->option_name_last_daily_run, $now->getTimestamp( ) );

                
            }
        }
    }

    public function schedule_watchdog()
    {   
        //For testing
        //$this->cleanup_deactivation();
        
        //Schedule a global watching cron just in case both other crons get killed
        if ( ! wp_next_scheduled( $this->cron_name_watcher ) )
        {
            wp_schedule_event( time(), 'hourly', $this->cron_name_watcher );
        }


    }

    public function get_themes_outdate_info() {
        $themes_outdate = get_transient( $this->tran_name_theme_timestamps );
        if (!is_array($themes_outdate))
            $themes_outdate = array();
         if( ! function_exists( 'wp_get_themes' ) )
        {
            require_once(ABSPATH . '/wp-admin/includes/theme.php');
        }
        $themes = wp_get_themes();        
        $update = false;        
        foreach($themes_outdate as $slug => $v) {
            if (!isset($themes[$slug])) {
                unset($themes_outdate[$slug]);
                $update = true;
            }            
        }
        if ($update) {
              set_transient( $this->tran_name_theme_timestamps, $themes_outdate, DAY_IN_SECONDS );
        }
        return $themes_outdate;
    }
   
    public function run_check()
    {
        if( ! function_exists( 'wp_get_themes' ) )
        {
            require_once(ABSPATH . '/wp-admin/includes/theme.php');
        }

        //Get our previous results
        $responses = get_transient( $this->tran_name_theme_timestamps );

        if( false === $responses || ! is_array( $responses ) )
        {
            $responses = array();
        }

        $all_themes = get_transient( $this->tran_name_themes_to_batch );        
        //If there wasn't a previous cache
        if( false === $all_themes || ! is_array( $all_themes ) )
        {            
            $themes = wp_get_themes();
            if (is_array($themes)) {                
                foreach ($themes as $theme)
                {
                    $slug = $theme->get_stylesheet();
                    $all_themes[$slug] = array(
                                                'Name' => $theme->get('Name'),                                                                                                                                                            
                                                'Version' => $theme->display('Version', true, false)  
                                            );
                    
                }
            }            
            $responses = array();
        }

        $themes_to_scan = array_splice( $all_themes, 0, apply_filters( 'mainwp_child_theme_health_check_max_themes_to_batch', 10 ) );
        
        foreach( $themes_to_scan as $slug => $v )
        {
            
            $body = $this->try_get_response_body( $slug, false );

            if( false === $body )
            {
                continue;
            }
                        
            //Deserialize the response
            $obj = unserialize( $body );
            
            $now = new \DateTime();
            
            //Sanity check that deserialization worked and that our property exists
            if( false !== $obj && is_object( $obj ) && property_exists( $obj, 'last_updated' ) )
            {
                $last_updated =  strtotime( $obj->last_updated );
                $theme_last_updated_date = new \DateTime( '@'. $last_updated );

                $diff_in_days = $now->diff( $theme_last_updated_date )->format( '%a' );

                
                $tolerance_in_days = get_option( 'mainwp_child_plugintheme_days_outdate', 365 );
                                
                if ($diff_in_days < $tolerance_in_days)
                    continue;                
                
                $v['last_updated'] = $last_updated;                
                
                $responses[ $slug ] = $v;
            }
        }

        if( ! defined( 'MINUTE_IN_SECONDS' ) )
        {
            define( 'MINUTE_IN_SECONDS', 60 );
        }

        if( ! defined( 'HOUR_IN_SECONDS' ) )
        {
            define( 'HOUR_IN_SECONDS', 60 * MINUTE_IN_SECONDS);
        }

        if( ! defined( 'DAY_IN_SECONDS' ) )
        {
            define( 'DAY_IN_SECONDS', 24 * HOUR_IN_SECONDS );
        }

        //Store the master response for usage in the plugin table
        set_transient( $this->tran_name_theme_timestamps, $responses, DAY_IN_SECONDS );

        if( 0 === count( $all_themes ) )
        {
            delete_transient( $this->tran_name_themes_to_batch );
            wp_schedule_single_event( time() + DAY_IN_SECONDS, $this->cron_name_daily );
        }
        else
        {
            set_transient( $this->tran_name_themes_to_batch, $all_themes, DAY_IN_SECONDS );
            wp_schedule_single_event( time(), $this->cron_name_batching );

        }
    }


    private function try_get_response_body( $theme )
    {
        //Some of this code is lifted from class-wp-upgrader
        
        //Get the WordPress current version to be polite in the API call
        include( ABSPATH . WPINC . '/version.php' );

        if( ! defined( 'MINUTE_IN_SECONDS' ) )
        {
            define( 'MINUTE_IN_SECONDS', 60 );
        }

        if( ! defined( 'HOUR_IN_SECONDS' ) )
        {
            define( 'HOUR_IN_SECONDS', 60 * MINUTE_IN_SECONDS);
        }
        
        $url = $http_url = 'http://api.wordpress.org/themes/info/1.0/';
        if ( $ssl = wp_http_supports( array( 'ssl' ) ) )
            $url = set_url_scheme( $url, 'https' );
        
        $args = array('slug' => $theme, 'fields' => array('sections' => false, 'tags' => false) ) ;
        $args = (object)$args;

        $http_args = array(
                'body' => array(
                        'action' => 'theme_information',
                        'request' => serialize( $args )
                )
        );
    

        $raw_response = wp_remote_post( $url, $http_args );

        if( ! is_wp_error( $raw_response ) && 200 == wp_remote_retrieve_response_code( $raw_response ) )
        {
            //Get the actual body
            //Requires WP 2.7.0
            $body = wp_remote_retrieve_body( $raw_response );

            //Make sure that it isn't empty and also not an empty serialized object
            if( '' != $body && 'N;' != $body  )
            {
                //If valid, return that
                return $body;
            }
        }
        
        //The above valid
        //If we previously tried an SSL version try without SSL
        //Code below same as above block
        if( $ssl )
        {
            $raw_response = wp_remote_post( $http_url, $http_args );
        
            if( ! is_wp_error( $raw_response ) && 200 == wp_remote_retrieve_response_code( $raw_response ) )
            {
                $body = wp_remote_retrieve_body( $raw_response );
                if( '' != $body && 'N;' != $body  )
                {
                    return $body;
                }
            }
        }

        //Everything above failed, bail
        return false;
    }
    
  
}

