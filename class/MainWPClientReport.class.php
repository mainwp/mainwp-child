<?php

class MainWPClientReport
{   
    public static $instance = null;
    
    static function Instance() {
        if (MainWPClientReport::$instance == null) {
            MainWPClientReport::$instance = new MainWPClientReport();
        }
        return MainWPClientReport::$instance;
    }    
    
    public function __construct() {
        global $wpdb;
        add_action('mainwp_child_deactivation', array($this, 'child_deactivation'));
    }
    
    public function child_deactivation()
    {
       
    }
    
    public function action() {   
        $information = array();
        if (!function_exists('wp_stream_query')) {
            $information['error'] = 'NO_STREAM';
            MainWPHelper::write($information);
        }   
        if (isset($_POST['mwp_action'])) {
            switch ($_POST['mwp_action']) {
                case "get_stream":
                    $information = $this->get_stream();
                break;                
            }        
        }
        MainWPHelper::write($information);
    }  
        
    public function get_stream() {        
        // Filters
        $allowed_params = array(
                'connector',
                'context',
                'action',
                'author',
                'author_role',
                'object_id',
                'search',
                'date',
                'date_from',
                'date_to',
                'record__in',
                'blog_id',
                'ip',
        );
        
        $stream_tokens = array();
        if (isset($_POST['stream_tokens'])) {
            $stream_tokens = unserialize(base64_decode($_POST['stream_tokens']));            
        }        
        if (!is_array($stream_tokens))
            $stream_tokens = array();
        
        $args = array();  
        foreach ( $allowed_params as $param ) {                                            
                $paramval = wp_stream_filter_input( INPUT_POST, $param );                
                if ( $paramval || '0' === $paramval ) {
                        $args[ $param ] = $paramval;
                }
        }
        
        foreach ( $args as $arg => $val ) { 
            if (!in_array($arg, $allowed_params)) {
                unset($args[$arg]);
            }                
        }
        
        $records = wp_stream_query( $args );
        if (!is_array($records)) 
            $records = array();
        //return $records;
        $token_values = array();
        foreach ($records as $record) {
            $name = "[" . $record->context . "." . $record->action . "]";            
            if (in_array($name, $stream_tokens)) {
                $token_values[$name][] = $record->summary;
            }
        }
        $information = array('token_values' => $token_values);    
        return $information;
    }
    
}

