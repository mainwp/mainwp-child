<?php

class MainWPKeywordLinks
{   
    public static $instance = null;    
    protected $config;
    protected $keyword_links;
    protected $server;
    protected $siteurl;
    protected $link_temp;
    protected $link_count_temp;
    protected $link_count_each_temp;
    
    static function Instance() {
        if (MainWPKeywordLinks::$instance == null) {
            MainWPKeywordLinks::$instance = new MainWPKeywordLinks();
        }
        return MainWPKeywordLinks::$instance;
    }
    
    public function __construct() {
        global $wpdb;
        $this->server = get_option('mainwp_child_server');
        add_action('wp_ajax_keywordLinksSaveClick', array($this, 'saveClickCallback'));        
        add_action('wp_ajax_nopriv_keywordLinksSaveClick', array($this, 'saveClickCallback'));
        add_action('template_redirect', array($this, 'keywordLinksJS'));
        $this->config = get_option('mainwp_kwl_options', array());
        $this->keyword_links = get_option('mainwp_kwl_keyword_links', array()); 
        if (empty($this->keyword_links))
            $this->keyword_links = array();
        $this->siteurl = get_option('siteurl');
		add_action('permalink_structure_changed', array(&$this, 'permalinkChanged'), 10, 2);
    }
    
    public function keywordLinksJS()
    {	
        if (!is_admin() && get_option('mainwp_kwl_enable_statistic'))
        {                
            wp_enqueue_script('jquery');
            wp_enqueue_script('keywordLinks', plugins_url('/js/keywordlinks.js', dirname(__FILE__)));
            add_action('wp_head', array($this, 'head_loading'), 1);            
        }                
    }

    public function head_loading()
    {   
        ?>
        <script type="text/javascript">
                var kwlAjaxUrl="<?php echo admin_url('admin-ajax.php'); ?>";
                var kwlNonce="<?php echo wp_create_nonce('keywordLinksSaveClick'); ?>";
                var kwlIp ="<?php echo $_SERVER['REMOTE_ADDR']; ?>"; 
                var kwlReferer ="<?php echo $_SERVER['HTTP_REFERER']; ?>"; 
        </script>
        <?php
    }
	
	
      public function permalinkChanged($old_struct, $new_struct)
    {
        if (get_option('mainwpKeywordLinks') != 1) {
            if (get_option('mainwp_keyword_links_htaccess_set') == 'yes') {
                $this->update_htaccess(false, true); // force clear               
            }
        } else {            
            $this->update_htaccess(true); // force update        
        }
    }
    
    function mod_rewrite_rules($pRules)
    {
        $home_root = parse_url(home_url());
        if (isset($home_root['path']))
            $home_root = trailingslashit($home_root['path']);
        else
            $home_root = '/';

        $rules = "<IfModule mod_rewrite.c>\n";
        $rules .= "RewriteEngine On\n";
        $rules .= "RewriteBase $home_root\n";

        //add in the rules that don't redirect to WP's index.php (and thus shouldn't be handled by WP at all)
        foreach ($pRules as $match => $query)
        {
            // Apache 1.3 does not support the reluctant (non-greedy) modifier.
            $match = str_replace('.+?', '.+', $match);

            $rules .= 'RewriteRule ^' . $match . ' ' . $home_root . $query . " [QSA,L]\n";
        }

        $rules .= "</IfModule>\n";

        return $rules;
    }
    
    function update_htaccess($force_update = false, $force_clear = false)
    {      
        if ($force_clear)
            $this->do_update_htaccess(true);
        else if ($force_update) {
            return $this->do_update_htaccess();            
        } else {
            if ('' ==  get_option( 'permalink_structure') && get_option('mainwp_keyword_links_htaccess_set') != 'yes')
                $this->do_update_htaccess(); // need to update
            else if ('' !=  get_option( 'permalink_structure') && get_option('mainwp_keyword_links_htaccess_set') == 'yes')
                $this->do_update_htaccess(); // need to update
        }
        return true;
    }
    
    public static function clear_htaccess() {
        include_once(ABSPATH . '/wp-admin/includes/misc.php');
        $home_path = ABSPATH;
        $htaccess_file = $home_path . '.htaccess';
        if (function_exists('save_mod_rewrite_rules'))
        {
            $rules = explode("\n", '');
            insert_with_markers($htaccess_file, 'MainWP Keyword Links Extension', $rules);
        }
        update_option('mainwp_keyword_links_htaccess_set', '');
    }
    
    public function do_update_htaccess($force_clear = false) {    
        if ($force_clear) {
            self::clear_htaccess();
            return true;
        } else if ('' ==  get_option( 'permalink_structure')) {
            include_once(ABSPATH . '/wp-admin/includes/misc.php');
            $redirection_folder = $this->get_option('redirection_folder', 'goto');            
            if (empty($redirection_folder))
                $redirection_folder = "goto";
            
            //Create rewrite ruler
            $rules = $this->mod_rewrite_rules(array($redirection_folder.'/'  => 'index.php'));
            $home_path = ABSPATH;
            $htaccess_file = $home_path . '.htaccess';
            if (function_exists('save_mod_rewrite_rules'))
            {
                $rules = explode("\n", $rules);
                insert_with_markers($htaccess_file, 'MainWP Keyword Links Extension', $rules);
            }
            update_option('mainwp_keyword_links_htaccess_set', 'yes');  
            return true;
        } else {
            self::clear_htaccess();
            return true;
        }
        return false;
    }
    
	
    
    public function saveClickCallback()
    {
            if ( ! wp_verify_nonce($_POST['nonce'], 'keywordLinksSaveClick') )
                    return false;
            $link_id = intval($_POST['link_id']);            
            if ($link_id) {
                $this->add_statistic($link_id, $_POST['ip'], $_POST['referer']);                               
            }            
            exit;
    }
        
    public function sendClick()
    {
            $url = $this->server.'admin-ajax.php';
            $clickData = get_option('mainwp_kwl_click_statistic_data');
            $key = get_option('mainwp_child_pubkey');
            if ( ! is_array($clickData) )
                    return false;
            $timestamp = time();
            $signature = $this->createSignature($key, $timestamp, $clickData);
            $request = wp_remote_post($url, array(
                    'headers' => array(
                            'Referer' => site_url()
                    ),
                    'body' => array(
                            'timestamp' => $timestamp,
                            'signature' => $signature,
                            'data' => base64_encode(serialize($clickData)),
                            'action' => 'keywordLinksSendClick'
                    )
            ));
            if ( is_array($request) && intval($request['body']) > 0 )
                    delete_option('mainwp_kwl_click_statistic_data');                       
    }
    
    public function createSignature( $key, $timestamp, $data )
    {
            $datamd5 = md5($timestamp.base64_encode(serialize($data)));
            $signature = md5($key.$datamd5);
            return $signature;
    }
    
    public function checkSignature( $signature, $timestamp, $data )
    {
            $key = get_option('mainwp_child_pubkey');
            if ( ! $key )
                    return false;
            $createSign = $this->createSignature($key, $timestamp, $data);
            return ( $signature == $createSign );
    }

    public function get_option($key, $default = '') {
        if (isset($this->config[$key]))
            return $this->config[$key];
        return $default;
    }
    
    public function set_option($key, $value) {
        $this->config[$key] = $value;
        return update_option('mainwp_kwl_options', $this->config);
    }
    
    public function get_link($link_id, $default = '') {
        if (isset($this->keyword_links[$link_id]))
            return $this->keyword_links[$link_id];
        return $default;
    }
    
    public function set_link($link_id, $link) {
        if (empty($link))
            unset($this->keyword_links[$link_id]);
        else
            $this->keyword_links[$link_id] = $link;
        return update_option('mainwp_kwl_keyword_links', $this->keyword_links);
    }
       
    
    // This function is to generate links for keywords in post content 
    public function filter_content($content) {
        global $post, $wpdb;        
        if ($this->get_option('mainwp_kwl_do_not_link_site_blocked', false))
            return $content;
        
        // get allow post typies, if it isn't belong that => avoid         
        $allow_post_type = (array) $this->get_option('enable_post_type');                   
        if (!in_array($post->post_type, $allow_post_type))
            return $content;
        
        
        if ($post) {            
            // Check if this post was disabled with this function, come back
            $disable = get_post_meta($post->ID, '_mainwp_kl_disable', true);        
            if ($disable == 1)
                return $content;      
            
            $paths_blocked = $this->get_option('mainwp_kwl_do_not_link_paths_blocked', array());            			
            if (is_array($paths_blocked)) {
                $permalink = get_permalink($post->ID);
                $url_paths = str_replace($this->siteurl,'', $permalink);
				$url_paths = trim($url_paths, '/');
				
				// check full path blocked
				if (in_array($url_paths, $paths_blocked))
					return $content;
				
                $url_paths = explode('/', $url_paths);
                foreach($url_paths as $path) {
					// check partial paths blocked
                    if (!empty($path) && in_array($path, $paths_blocked)) {
                        return $content;
                    }
                }                                
            }
        }
        
        // save specific link 
        if ($post) {            
            $specific_link = get_post_meta($post->ID, '_mainwp_kwl_specific_link', true);               
            $specific_link = unserialize($specific_link); 
            if (is_array($specific_link) && count($specific_link) > 0) {  
                $specific_link = current($specific_link);
                $specific_link->post_id = $post->ID;                
                //update_post_meta($post->ID, '_mainwp_kwl_specific_link_save', array($specific_link->id => $specific_link));                   
                update_post_meta($post->ID, '_mainwp_kwl_specific_link_id', $specific_link->id);                   
                if ($this->set_link($specific_link->id, $specific_link))                                
                    delete_post_meta($post->ID, '_mainwp_kwl_specific_link'); // delete the source meta              
            }                         
        }

        if ($post && $post->ID)
            $links = $this->get_available_links($post->ID);   
        else
            $links = $this->get_available_links();   
        
        // print_r($this->keyword_links);
       // echo "======";
       // print_r($links);
        
        if (empty($links))
            return $content;
        
        $replace_max = intval($this->get_option('replace_max', -1));
        $replace_max_keyword = intval($this->get_option('replace_max_keyword', -1));        
        // start create links for keywords (terms) in post content
        $this->link_count_temp = $replace_max;
        $not_allow_keywords = get_post_meta($post->ID, 'mainwp_kl_not_allowed_keywords_on_this_post', true);        
        $not_allow_keywords = unserialize($not_allow_keywords); 
        foreach ($links as $link) {
            if (!$link)
                continue;
            
            global $current_user;
            
            $this->link_temp = $link;
            $this->link_count_each_temp = $replace_max_keyword;
            //$keywords = explode(',', $link->keyword);
            $keywords = $this->explode_multi($link->keyword);
            usort($keywords, create_function('$a,$b', 'return strlen($a)<strlen($b);'));            
            foreach ($keywords as $keyword) {
                $keyword = trim($keyword);
                if (empty($keyword))
                    continue;
                if (in_array(array("keyword" => $keyword, "link" => $link->destination_url), (array) $not_allow_keywords)) {
                    continue;
                }
                $keyword = preg_replace('/([$^\/?+.*\]\[)(}{])/is', '\\\\\1', $keyword);
                
                if (strpos($content, $keyword) !== false) {
                    //Replace keyword in H tag
                    if ($this->get_option('replace_keyword_in_h_tag')) {
                        //$content = preg_replace_callback('/(<a[^>]*>.*?'.$keyword.'.*?<\/a>|<[^>]*'.$keyword.'[^>]*>|\{[^}]*'.$keyword.'[^}]*\}|\w*('.$keyword.')\w*)/is', array(&$this, 'keyword_mark'), $content);
                        $content = preg_replace_callback("/(<a[^>]*>[^<]*?" . $keyword . "[^<]*?<\/a>|<[^>]*" . $keyword . "[^>]*>|\{[^\}]*" . $keyword . "[^\}]*\}|\w*(" . $keyword . ")\w*)/is", array(&$this, 'keyword_mark'), $content);
                    } else {
                        //$content = preg_replace_callback('/(<h[123456][^>]*>.*?'.$keyword.'.*?<\/h[123456]>|<a[^>]*>.*?'.$keyword.'.*?<\/a>|<[^>]*'.$keyword.'[^>]*>|\{[^}]*'.$keyword.'[^}]*\}|\w*('.$keyword.')\w*)/is', array(&$this, 'keyword_mark'), $content);
                        $content = preg_replace_callback("/(<h[123456][^>]*>[^<]*?" . $keyword . "[^<]*?<\/h[123456]>|<a[^>]*>[^<]*?" . $keyword . "[^<]*?<\/a>|<[^>]*" . $keyword . "[^>]*>|\{[^\}]*" . $keyword . "[^\}]*\}|\w*(" . $keyword . ")\w*)/is", array(&$this, 'keyword_mark'), $content);
                    }       
                }
            }            
        }
        $content = preg_replace_callback('/\{MAINWP_LINK +HREF="(.*?)" +TARGET="(.*?)" +REL="(.*?)" +LINK-ID="(.*?)" +CLASS="(.*?)" +TEXT="(.*?)" *\}/is', array(&$this, 'keyword_replace'), $content);
        return $content;
    }
    
    public function keyword_mark($matches) {
        
        if (preg_match('/^[<{].*?[>}]$/is', $matches[1]))
            return $matches[1];
        
        if ($this->link_count_temp === 0 || $this->link_count_each_temp === 0)
            return $matches[1];
        
        if ($matches[1] != $matches[2])
            return $matches[1];
        
        if ($this->link_count_temp != -1)
            $this->link_count_temp--;
        
        if ($this->link_count_temp != -1)
            $this->link_count_each_temp--;
        
//        if (isset($this->link_temp->type) && $this->link_temp->type == 'post_type') {
////            $post = get_post($this->link_temp->id);
////            if ($post) {
////                $disable_linking = $this->get_option('disable_linking_automatically', array());
////                if (in_array($post->post_name, $disable_linking[$post->post_type]))
////                    return $matches[1]; // do not link to this post    
////            }
//            $link_target = get_post_meta($this->link_temp->id, '_mainwp_kl_link_newtab', true);
//            $this->link_temp->link_target = ( $link_target != -1 && $link_target == 1 ? '_blank' : '' );
//            $link_rel = get_post_meta($this->link_temp->id, '_mainwp_kl_link_nofollow', true);
//            $this->link_temp->link_rel = ( $link_rel != -1 && $link_rel == 1 ? 'nofollow' : '' );
//            $this->link_temp->link_class = get_post_meta($this->link_temp->id, '_mainwp_kl_link_class', true);
//        }
        if ($this->link_temp->link_target != '-1') {
            $target = $this->link_temp->link_target;            
        } else
            $target = $this->get_option('default_link_newtab') ? '_blank' : '';
        
        
        if ($this->link_temp->link_rel != '-1')
            $rel = $this->link_temp->link_rel;
        else
            $rel = $this->get_option('default_link_nofollow') ? 'nofollow' : '';
        if ($this->link_temp->link_class != '')
            $class = $this->link_temp->link_class;
        else
            $class = $this->get_option('default_link_class');
        $redirection_folder = $this->get_option('redirection_folder', 'goto');            
        if (empty($redirection_folder))
                $redirection_folder = "goto";
        if (!empty($redirection_folder))
            $redirection_folder = "/" . $redirection_folder;
        
//        if (empty($redirection_folder))
//            $redirection_folder = 'goto';
        
        $regular_link = false;
        if (empty($this->link_temp->cloak_path)) {
            $regular_link = true;
            $class .= " kwl-regular-link"; 
        }
        
        return '{MAINWP_LINK HREF="' . ( $this->link_temp->cloak_path ? $this->siteurl . $redirection_folder . '/' . $this->link_temp->cloak_path : $this->link_temp->destination_url) . '" TARGET="' . $target . '" REL="' . $rel . '" LINK-ID="' . $this->link_temp->id . '" CLASS="' . $class . '" TEXT="' . $matches[1] . '"}';
    }
    
    public function keyword_replace( $matches )
    {
        $a = '<a href="'.$matches[1].'"';
        $a .= ( $matches[2] ) ? ' target="'.$matches[2].'"' : '';
        $a .= ( $matches[3] ) ? ' rel="'.$matches[3].'"' : '';        
        $a .= ( $matches[4] ) ? ' link-id="'.$matches[4].'"' : '';
        $a .= ( $matches[5] ) ? ' class="'.$matches[5].'"' : '';
        $a .= '>'.$matches[6].'</a>';
        return $a;
    }
     
    public function get_available_links($post_id = null) {
        global $post, $wpdb;
        if ($post_id !== null)
            $post = get_post($post_id);
        $links = array();        
    //        $disable_add_links = $this->get_option('disable_add_links_automatically');
    //        // if disabled add links automatically in this post, avoid
    //        if (in_array($post->post_name, (array) $disable_add_links[$post->post_type])) {
    //            return $links;
    //        }
         
        // Check if this post was disabled with this function, come back
//        $disable = get_post_meta($post->ID, '_mainwp_kl_disable', true);        
//        if ($disable == 1)
//            return $links;
        // count replace max and max keyword allowed.
        $replace_max = intval($this->get_option('replace_max'));
        $replace_max_keyword = intval($this->get_option('replace_max_keyword'));
        if ($replace_max === 0 || $replace_max_keyword === 0)
            return $links;
        // Post types enabled to create links
        $post_types = (array) $this->get_option('enable_post_type_link');        
        foreach ($post_types as $post_type) {
            if ($post_type == $post->post_type) {
                $categories = get_the_terms($post->ID, 'category');
                $cats = array();
                if (is_array($categories)) {
                    foreach ($categories as $category)
                        $cats[] = $category->term_id;
                }
                $links_post_type = (array) $this->get_post_keywords($post_type, $cats);
            } else {
                $links_post_type = (array) $this->get_post_keywords($post_type);
            }
            //print_r($links_post_type);            
            if (count($links_post_type) > 0)
                $links = array_merge($links, $links_post_type);
        }
        
        if ($post && $post->ID > 0) 
            $spec_link_id = get_post_meta($post->ID, '_mainwp_kwl_specific_link_id', true); 
        
        foreach($this->keyword_links as $link) {            
            if ($link->type == 1 || $link->type == 3)
                $links[] = $link;
            else if ($spec_link_id && $spec_link_id == $link->id){
                $links[] = $link;
            }
        }        
        return $links;
    }
    
   
     public function get_post_keywords($post_type, $cats = null) {
        global $wpdb, $post;
        $join = '';
        $where = '';
        if (is_array($cats) && count($cats) > 0) {
            $join = "JOIN $wpdb->term_relationships tr ON tr.object_id = p.ID";
            $where = " AND (tr.term_taxonomy_id = '" . implode("' OR tr.term_taxonomy_id = '", $cats) . "')";
        }
        //$results = $wpdb->get_results(sprintf("SELECT * FROM $wpdb->posts as p LEFT JOIN $wpdb->postmeta as pm ON p.ID=pm.post_id $join WHERE p.post_status='publish' AND p.post_type='%s' AND pm.meta_key='_mainwp_kl_post_keyword' $where", $post_type));
        $results = $wpdb->get_results(sprintf("SELECT * FROM $wpdb->posts as p $join WHERE p.post_status='publish' AND p.post_type='%s' $where", $post_type));
        $links = array();
        if (!is_array($results))
            return array();
        $paths_blocked = $this->get_option('mainwp_kwl_do_not_link_paths_blocked', array());
        foreach ($results as $result) {
			if ($result->ID == $post->ID)
                continue; // do not link to myself
            if (in_array($result->post_name, (array) $paths_blocked))
                continue;
            $link = new stdClass;
            // This is on-fly link so have not ID
            //$link->id = $result->ID;
            $link->name = $result->post_title;
            //if ($result->post_type == 'page')
            //    $link->destination_url = get_permalink($result->ID);
            //else
            //    $link->destination_url = $result->guid;
            $link->destination_url = get_permalink($result->ID);
            $link->cloak_path = '';
            $link->keyword = ( $this->get_option('post_match_title') == 1 ? $result->post_title . ',' : '' ) . $result->meta_value;
            $link->link_target = '';
            $link->link_rel = '';
            $link->link_class = '';
            $link->type = 1;
            $links[] = $link;
        }
        return $links;
    }
    
    public function explode_multi($str) {
        $delimiters = array(",", ";", "|");
        $str = str_replace($delimiters, ",", $str);
        return explode(',', $str);
    }
    
    public function redirect_cloak() {
        global $wpdb;
        
        if ($this->get_option('mainwp_kwl_do_not_link_site_blocked', false))
            return;
            
        $request = $_SERVER['REQUEST_URI'];
        // Check if the request is correct
        if (!preg_match('|^[a-z0-9-~+_.?#=!&;,/:%@$\|*\'()\\x80-\\xff]+$|i', $request))
            return;
        // Check to see if Wordpress is installed in sub folder
        $siteurl = parse_url($this->siteurl);
        $sitepath = ( isset($siteurl['path']) ) ? $siteurl['path'] : '';
        $filter_request = preg_replace('|^' . $sitepath . '/?|i', '', $request);
        $filter_request = preg_replace('|/?$|i', '', $filter_request);
        
        $redirection_folder = $this->get_option('redirection_folder', 'goto');        
        $redirection_folder = empty($redirection_folder) ? "goto" : $redirection_folder;
        
        //user use redirection_folder (or not set it - we use by default)
        if ($redirection_folder != '') {
            //if the request doesn't' containt the redirection folder we will return immediately
            if (strpos($filter_request, $redirection_folder . '/') === false) {
                return;
            }
            $filter_request = str_replace($redirection_folder . '/', '', $filter_request);
        }
        
        if (empty($filter_request))
            return;
        
        if (substr($filter_request, -1) == "/") {             
            $filter_request = substr($filter_request, 0, -1);
        }       
        
        $link_id = 0;
        foreach($this->keyword_links as $link) {
            if ($link->cloak_path == $filter_request) {
                $destination_url = $link->destination_url;
                $link_id = $link->id;
                break;
            }   
        }            
        
        if (!empty($destination_url)){
			if (get_option('mainwp_kwl_enable_statistic'))		
				$this->add_statistic($link_id, $_SERVER['REMOTE_ADDR'], $_SERVER['HTTP_REFERER']);                         
            wp_redirect($destination_url);
            die();
        }            
    }
    
    public function add_statistic($link_id, $addr, $referer, $type = 'click') {
        if ($link_id > 0) {
            $storeData = get_option('mainwp_kwl_click_statistic_data');
            if ( ! is_array($storeData) )
                    $storeData = array();                
            $storeData[] = array(
                'timestamp' => time(),
                'link_id' => $link_id,                
                'ip' => $addr,
                'referer' => $referer                                            
            );                
            update_option('mainwp_kwl_click_statistic_data', $storeData);         
            // Customize when we need to send the data
            $this->sendClick();
        }
    }
    
//    public function get_statistic() {
//        global $wpdb;        
//        $link_id = $_POST['link_id'];        
//        if ($link_id) {
//            $stat_data = get_option('mainwp_kwl_statistic_data_' . $link_id, array());             
//            if ($stat_data) {
//                $return['stat_data'] = $stat_data;                
//                //$wpdb->query("UPDATE {$wpdb->prefix}options SET option_name = 'mainwp_kwl_statistic_data_done_" . $link_id . "' WHERE option_name = 'mainwp_kwl_statistic_data_" . $link_id . "'");
//                update_option('mainwp_kwl_statistic_data_' . $link_id, '');             
//            } else 
//                $return['stat_data'] = 'EMPTY';
//            $return['status'] = 'SUCCESS';
//        }
//        return $return;
//    }
   
    public function action() {
        $result = array();
        switch ($_POST['action']) {
			case 'enable_stats':
                $result = $this->enable_stats();
			break;
			case 'refresh_data':
                $result = $this->refresh_data();
			break;
            case 'import_link':
            case 'add_link':
                $result = $this->edit_link();
                break;
            case 'delete_link':
                $result = $this->delete_link();
                break;
            case 'clear_link':
                $result = $this->clear_link();
                break;            
            case 'update_config':
                $result = $this->update_config();
                break;        
            case 'donotlink_site_blocks':
                $result = $this->donotlink_site_blocks();
                break;        
            case 'donotlink_path_blocks':
                $result = $this->donotlink_path_blocks();
                break;        
            case 'donotlink_clear':
                $result = $this->donotlink_clear();
                break;        
        }        
        MainWPHelper::write($result);
    }
	
	public function enable_stats()
    {
		$result = array();
        $enable_stats = intval($_POST['enablestats']);
        if (update_option('mainwp_kwl_enable_statistic', $enable_stats))
			$return['status'] = 'SUCCESS';                      
        return $return;
    }
	
	public function refresh_data()
    {
        $result = array();
        if (isset($_POST['clear_all'])) {
            $cleared1 = update_option('mainwp_kwl_keyword_links', '');   
            $cleared2 = update_option('mainwp_kwl_options', '');    
            if ($cleared1 || $cleared2)
                $return['status'] = 'SUCCESS';             
        }    
        return $return;
    }
	
    public function delete_link() {
        $result = array();
        if (!empty($_POST['link_id'])) {
            $del_link = $this->get_link($_POST['link_id'], false);
            if ($del_link) {
                if ($del_link->type == 2 || $del_link->type == 3)                     
                    $deleted = delete_post_meta($del_link->post_id, '_mainwp_kwl_specific_link_id'); 
                if ($this->set_link($del_link->id, '')) 
                    $return['status'] = 'SUCCESS';             
            }
            else 
                $return['status'] = 'SUCCESS';
        }
        return $return;
    }

    public function clear_link() {
        $return = array();
        $cleared = false;
        if (!empty($_POST['link_id'])) {
            $clear_link = $this->get_link($_POST['link_id'], false);
            if ($clear_link) {                
                if ($clear_link->type == 3) {            
                    $clear_link->type = 2;
                    $cleared = $this->set_link($clear_link->id, $clear_link);
                } else if ($clear_link->type == 1) {
                    $cleared = $this->set_link($clear_link->id, ''); // delete link                  
                }                          
            }
            else 
                $cleared = true;
        }
        
        if ($cleared)
            $return['status'] = 'SUCCESS';   
        return $return;
    }
   
    
    public function edit_link() {
        $return = array();
        $link_id = $_POST['id'];
        if (!empty($link_id)) {   
                $old = $this->get_link($link_id);
                $link = new stdClass;
                $link->id = intval($link_id);
                $link->name = sanitize_text_field($_POST['name']);                
                $link->destination_url = sanitize_text_field($_POST['destination_url']);
                $link->cloak_path = sanitize_text_field($_POST['cloak_path']);
                $link->keyword = sanitize_text_field($_POST['keyword']);
                $link->link_target = $_POST['link_target'];  // number or text
                $link->link_rel = $_POST['link_rel']; // number or text
                $link->link_class = sanitize_text_field($_POST['link_class']);
                $link->type = intval($_POST['type']);  
                
                 if ($link->type == 2 || $link->type == 3) {
                    if (intval($_POST['post_id'])) {                       
                        $link->post_id = intval($_POST['post_id']);
                    } else if ($old && $old->post_id) {
                        $link->post_id = $old->post_id;
                    }                    
                    if ($link->post_id) {
                         update_post_meta($link->post_id, '_mainwp_kwl_specific_link_id', $link_id);                   
                    }
                } 
                
                if ($this->set_link($link->id, $link))
                    $return['status'] = 'SUCCESS';                
            }               
            update_option('mainwpKeywordLinks', 1); // enable extension functions            
            return $return;
    }
     
    public function update_config() {
            $return = array();
            $this->config = array(
                    'replace_max' => intval($_POST['replace_max']),
                    'replace_max_keyword' => intval($_POST['replace_max_keyword']),
                    'default_link_nofollow' => intval($_POST['default_link_nofollow']),
                    'default_link_newtab' => intval($_POST['default_link_newtab']),
                    'replace_keyword_in_h_tag' => intval($_POST['replace_keyword_in_h_tag']),
                    'default_link_class' => sanitize_text_field($_POST['default_link_class']),                
                    'post_match_title' => intval($_POST['post_match_title']),
                    'redirection_folder' => sanitize_text_field($_POST['redirection_folder']),
                    'enable_post_type' => $_POST['enable_post_type'],
                    'enable_post_type_link' => $_POST['enable_post_type_link']
            );
            update_option('mainwpKeywordLinks', 1); // enable extension functions            
            if (update_option('mainwp_kwl_options', $this->config)) {
                $return['status'] = 'SUCCESS';    
			}
			
			// force update   
            $this->update_htaccess(true);				            
            return $return;
    }
    
    public function donotlink_site_blocks()
    {
        $return = array();
        if ($this->set_option('mainwp_kwl_do_not_link_site_blocked', true))
            $return['status'] = 'SUCCESS';
        return $return;            
    }    
    
    public function donotlink_path_blocks()
    {
        $return = array();
        if ($path = $_POST['path']) {
            $paths = $this->get_option('mainwp_kwl_do_not_link_paths_blocked', array());
            $paths[] = $path; 
            if ($this->set_option('mainwp_kwl_do_not_link_paths_blocked', $paths))
                $return['status'] = 'SUCCESS';
        }
        return $return;            
    }    
        
    public function donotlink_clear()
    {
        $return = array();
        if ($this->set_option('mainwp_kwl_do_not_link_site_blocked', ''))
            $return['status'] = 'SUCCESS';
        if ($this->set_option('mainwp_kwl_do_not_link_paths_blocked', ''))
            $return['status'] = 'SUCCESS';
        return $return;            
    }    
    
}

