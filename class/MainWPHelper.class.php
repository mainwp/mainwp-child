<?php
class MainWPHelper
{

    static function write($val)
    {
        die('<mainwp>' . base64_encode(serialize($val)) . '</mainwp>');
    }

    static function error($error)
    {
        $information['error'] = $error;
        MainWPHelper::write($information);
    }

    static function uploadImage($img_url)
    {
        include_once(ABSPATH . 'wp-admin/includes/file.php'); //Contains download_url
        //Download $img_url
        $temporary_file = download_url($img_url);

        if (is_wp_error($temporary_file))
        {
            throw new Exception('Error: ' . $temporary_file->get_error_message());
        }
        else
        {
            $upload_dir = wp_upload_dir();
            $local_img_path = $upload_dir['path'] . DIRECTORY_SEPARATOR . basename($img_url); //Local name
            $local_img_url = $upload_dir['url'] . '/' . basename($img_url);
            $moved = @rename($temporary_file, $local_img_path);
            if ($moved)
            {
                $wp_filetype = wp_check_filetype(basename($img_url), null); //Get the filetype to set the mimetype
                $attachment = array(
                    'post_mime_type' => $wp_filetype['type'],
                    'post_title' => preg_replace('/\.[^.]+$/', '', basename($img_url)),
                    'post_content' => '',
                    'post_status' => 'inherit'
                );
                $attach_id = wp_insert_attachment($attachment, $local_img_path); //Insert the image in the database
                require_once(ABSPATH . 'wp-admin/includes/image.php');
                $attach_data = wp_generate_attachment_metadata($attach_id, $local_img_path);
                wp_update_attachment_metadata($attach_id, $attach_data); //Update generated metadata

                return array('id' => $attach_id, 'url' => $local_img_url);
            }
        }
        if (file_exists($temporary_file))
        {
            unlink($temporary_file);
        }
        return null;
    }

    static function uploadFile($file_url, $path)
    {
        $file_name = basename($file_url);
        $file_name = sanitize_file_name($file_name);
        $full_file_name = $path . DIRECTORY_SEPARATOR . $file_name; //Local name
        
        $response = wp_remote_get($file_url, array( 'timeout' => 600, 'stream' => true, 'filename' => $full_file_name ) );

        if ( is_wp_error( $response ) ) {
            @unlink( $full_file_name );
            throw new Exception('Error: ' . $response->get_error_message());
        }

        if ( 200 != wp_remote_retrieve_response_code( $response ) ){
            @unlink( $full_file_name );
            throw new Exception('Error 404: ' . trim( wp_remote_retrieve_response_message( $response ) ));
        }
        if (substr($file_name, -12) == ".phpfile.txt") {
            $new_file_name = substr($file_name, 0, -12) . ".php";
            $new_file_name = $path . DIRECTORY_SEPARATOR . $new_file_name;
            $moved = @rename($full_file_name, $new_file_name);
            if ($moved) {                
                return array('path' => $new_file_name);
            } else 
            {
                @unlink( $full_file_name );
                throw new Exception('Error: Copy file.');
            }
        }
        return array('path' => $full_file_name);
    }
    
    static function createPost($new_post, $post_custom, $post_category, $post_featured_image, $upload_dir, $post_tags)
    {
        global $current_user;

        //Set up a new post (adding addition information)
        $usr = get_user_by('login', $_POST['user']);
        //$new_post['post_author'] = $current_user->ID;
        
        if (isset($new_post['custom_post_author']) && !empty($new_post['custom_post_author'])) {
            $_author = get_user_by( 'login', $new_post['custom_post_author'] );
            if (!empty($_author))
                $new_post['post_author'] = $_author->ID;
            else 
                $new_post['post_author'] = $usr->ID; 
            unset($new_post['custom_post_author']);
        } else {
            $new_post['post_author'] = $usr->ID; // to fix missing post author
        }
        
        $ezine_post = !empty($post_custom['_ezine_post_article_source']) ? true : false;
        $terms = $new_post['_ezin_post_category'];
        unset($new_post['_ezin_post_category']);
        $post_plus = isset($post_custom['_mainwp_post_plus']) ? true : false;
        
        $wp_error = null;

        //Search for all the images added to the new post
        //some images have a href tag to click to navigate to the image.. we need to replace this too
        if (!$ezine_post || $post_plus) {
            $foundMatches = preg_match_all('/(<a[^>]+href=\"(.*?)\"[^>]*>)?(<img[^>\/]*src=\"((.*?)(png|gif|jpg|jpeg))\")/ix', $new_post['post_content'], $matches, PREG_SET_ORDER);
        }
        else 
        {
            if (isset($new_post['post_date_gmt']) && !empty($new_post['post_date_gmt'])) {
                    $post_date_timestamp = strtotime($new_post['post_date_gmt']) + get_option('gmt_offset') * 60 * 60;
                    $new_post['post_date'] = date('Y-m-d H:i:s', $post_date_timestamp);
                    $new_post['post_status'] = ($post_date_timestamp <= current_time('timestamp')) ? 'publish' : 'future';
            } else {
                    $new_post['post_status'] = 'publish';
            }
            $foundMatches = 0;
        }    
                
        if ($foundMatches > 0)
        {
            //We found images, now to download them so we can start balbal
            foreach ($matches as $match)
            {
                $hrefLink = $match[2];
                $imgUrl = $match[4];

                if (!isset($upload_dir['baseurl']) || (strripos($imgUrl, $upload_dir['baseurl']) != 0)) continue;

                if (preg_match('/-\d{3}x\d{3}\.[a-zA-Z0-9]{3,4}$/', $imgUrl, $imgMatches)) {
                    $search = $imgMatches[0];
                    $replace = '.'.$match[6];
                    $originalImgUrl = str_replace($search, $replace, $imgUrl);
                } else {
                    $originalImgUrl = $imgUrl;
                }

                $downloadfile = MainWPHelper::uploadImage($originalImgUrl);
                $localUrl = $downloadfile['url'];
                $linkToReplaceWith = dirname($localUrl);
                if ($hrefLink != '')
                {
                    $lnkToReplace = dirname($hrefLink);
                    if ($lnkToReplace != 'http:' && $lnkToReplace != 'https:') $new_post['post_content'] = str_replace($lnkToReplace, $linkToReplaceWith, $new_post['post_content']);
                }

                $lnkToReplace = dirname($imgUrl);
                if ($lnkToReplace != 'http:' && $lnkToReplace != 'https:') $new_post['post_content'] = str_replace($lnkToReplace, $linkToReplaceWith, $new_post['post_content']);
            }
        }
        
        if ($post_plus) {
            $random_publish_date = isset($post_custom['_saved_draft_random_publish_date']) ? $post_custom['_saved_draft_random_publish_date'] : false;             
            $random_publish_date = is_array($random_publish_date) ? current($random_publish_date) : null;
            if (!empty($random_publish_date)) {
                $random_date_from = isset($post_custom['_saved_draft_publish_date_from']) ? $post_custom['_saved_draft_publish_date_from'] : 0;
                $random_date_from = is_array($random_date_from) ? current($random_date_from) : 0;            
                
                $random_date_to = isset($post_custom['_saved_draft_publish_date_to']) ? $post_custom['_saved_draft_publish_date_to'] : 0;
                $random_date_to = is_array($random_date_to) ? current($random_date_to) : 0;            
                
                $now = current_time('timestamp');
                
                if (empty($random_date_from))
                    $random_date_from = $now;
                
                if (empty($random_date_to))
                    $random_date_to = $now;
                
                if ($random_date_from == $now && $random_date_from  == $random_date_to)
                    $random_date_to = $now + 7 * 24 * 3600;
                
                if ($random_date_from > $random_date_to) {
                    $tmp = $random_date_from;
                    $random_date_from = $random_date_to;
                    $random_date_to = $tmp;
                }  
                
                $random_timestamp = rand($random_date_from, $random_date_to);
                $post_status = ($random_timestamp <= current_time('timestamp')) ? 'publish' : 'future';                                
                $new_post['post_status'] = $post_status;
                $new_post['post_date'] = date('Y-m-d H:i:s', $random_timestamp);                
            }            
        }
        
        if (isset($post_tags) && $post_tags != '') $new_post['tags_input'] = $post_tags;
                
        //Save the post to the wp
        remove_filter('content_save_pre', 'wp_filter_post_kses');  // to fix brake scripts or html
        $new_post_id = wp_insert_post($new_post, $wp_error);

        //Show errors if something went wrong
        if (is_wp_error($wp_error))
        {
            return $wp_error->get_error_message();
        }
        if (empty($new_post_id))
        {
            return 'Undefined error';
        }
        
        if (!empty($terms)) {                 
                 wp_set_object_terms($new_post_id, array_map( intval, $terms),  'category');
        }         
        
        $permalink = get_permalink( $new_post_id );
        
        $seo_ext_activated = false;
        if (class_exists('WPSEO_Meta') && class_exists('WPSEO_admin')) 
            $seo_ext_activated = true;
        
        //Set custom fields
        $not_allowed = array('_slug', '_tags', '_edit_lock', '_selected_sites', '_selected_groups', '_selected_by', '_categories', '_edit_last', '_sticky');
        $not_allowed[] = '_mainwp_boilerplate_sites_posts';
        $not_allowed[] = '_ezine_post_keyword';
        $not_allowed[] = '_ezine_post_display_sig';
        $not_allowed[] = '_ezine_post_remove_link';
        $not_allowed[] = '_ezine_post_grab_image';
        $not_allowed[] = '_ezine_post_grab_image_placement';
        $not_allowed[] = '_ezine_post_template_id';
        
        $not_allowed[] = '_mainwp_post_plus'; 
        $not_allowed[] = '_saved_as_draft';               
        $not_allowed[] = '_saved_draft_categories';
        $not_allowed[] = '_saved_draft_tags';
        $not_allowed[] = '_saved_draft_random_privelege';
        $not_allowed[] = '_saved_draft_random_category';
        $not_allowed[] = '_saved_draft_random_publish_date';
        $not_allowed[] = '_saved_draft_publish_date_from';
        $not_allowed[] = '_saved_draft_publish_date_to';     
        $not_allowed[] = '_post_to_only_existing_categories';
        
        $post_to_only_existing_categories = false;
        foreach ($post_custom as $meta_key => $meta_values)
        {
            if (!in_array($meta_key, $not_allowed))
            {
                foreach ($meta_values as $meta_value)
                {                    
                    
                    if (!$seo_ext_activated) {
                        // if Wordpress SEO plugin is not activated do not save yoast post meta
                        if(strpos($meta_key, "_yoast_wpseo_") === false) 
                            add_post_meta($new_post_id, $meta_key, $meta_value);        
                    } else {                        
                        add_post_meta($new_post_id, $meta_key, $meta_value);        
                    }                   
                }
            }
            else if ($meta_key == '_sticky')
            {
                foreach ($meta_values as $meta_value)
                {
                    if (base64_decode($meta_value) == 'sticky')
                    {
                        stick_post($new_post_id);
                    }
                }
            } else if ($meta_key == '_post_to_only_existing_categories') {                
                if (isset($meta_values[0]) && $meta_values[0])
                    $post_to_only_existing_categories = true;
            }
        }
        
        // yoast seo extension
        if ($seo_ext_activated) {
            $_seo_opengraph_image = isset($post_custom[WPSEO_Meta::$meta_prefix . 'opengraph-image']) ? $post_custom[WPSEO_Meta::$meta_prefix . 'opengraph-image'] : array();
            $_seo_opengraph_image = current($_seo_opengraph_image);
            $_server_domain = "";
            $_server = get_option('mainwp_child_server');            
            if (preg_match('/(https?:\/\/[^\/]+\/).+/', $_server, $matchs)) {
                $_server_domain = isset($matchs[1]) ? $matchs[1] : "";
            }          
            
            // upload image if it on the server
            if (!empty($_seo_opengraph_image) && strpos($_seo_opengraph_image, $_server_domain) !== false) {                 
                try
                {
                    $upload = MainWPHelper::uploadImage($_seo_opengraph_image); //Upload image to WP
                    if ($upload != null)
                    {
                        update_post_meta($new_post_id, WPSEO_Meta::$meta_prefix . 'opengraph-image', $upload['url']); //Add the image to the post!
                    }
                }
                catch (Exception $e)
                {

                }

            }
        }        

        //If categories exist, create them (second parameter of wp_create_categories adds the categories to the post)
        include_once(ABSPATH . 'wp-admin/includes/taxonomy.php'); //Contains wp_create_categories
        if (isset($post_category) && $post_category != '')
        {
            $categories = explode(',', $post_category);
            if (count($categories) > 0)
            {
                if (!$post_to_only_existing_categories)
                    $post_category = wp_create_categories($categories, $new_post_id);
                else {
                    $cat_ids = array ();
                    foreach($categories as $cat) {
                        if ($id = category_exists($cat))
                           $cat_ids[] = $id;                        
                    }
                    if (count($cat_ids) > 0)
                        wp_set_post_categories($new_post_id, $cat_ids);
                }
            }
        }

        //If featured image exists - set it
        if ($post_featured_image != null)
        {
            try
            {
                $upload = MainWPHelper::uploadImage($post_featured_image); //Upload image to WP

                if ($upload != null)
                {
                    update_post_meta($new_post_id, '_thumbnail_id', $upload['id']); //Add the thumbnail to the post!
                }
            }
            catch (Exception $e)
            {
                
            }
        }
        
        // post plus extension process        
        if ($post_plus) {
            $random_privelege = isset($post_custom['_saved_draft_random_privelege']) ? $post_custom['_saved_draft_random_privelege'] : null;            
            $random_privelege = is_array($random_privelege) ? current($random_privelege) : null;
            $random_privelege = unserialize(base64_decode($random_privelege));
            
            if (is_array($random_privelege) && count($random_privelege) > 0) {
                $random_post_authors = array();
                foreach($random_privelege as $role) {
                    $users = get_users(array('role' => $role));
                    foreach($users as $user) {
                        $random_post_authors[] = $user->ID;
                    }
                }                
                if (count($random_post_authors) > 0) {
                    shuffle($random_post_authors);
                    $key = array_rand($random_post_authors);
                    wp_update_post(array('ID' => $new_post_id, 'post_author' => $random_post_authors[$key]));
                }                       
            }
            
            $random_category = isset($post_custom['_saved_draft_random_category']) ? $post_custom['_saved_draft_random_category'] : false;                                    
            $random_category = is_array($random_category) ? current($random_category) : null;
            if (!empty($random_category)) {                                
                $cats = get_categories(array('type' => 'post', "hide_empty" => 0));
                $random_cats = array();
                if (is_array($cats)) {
                    foreach($cats as $cat) {
                        $random_cats[] = $cat->term_id;                       
                    }
                }                
                if (count($random_cats) > 0) {
                    shuffle($random_cats);
                    $key = array_rand($random_cats);                    
                    wp_set_post_categories($new_post_id, array($random_cats[$key]), false); 
                }
            }
        }
        // end of post plus
        
        $ret['success'] = true;
        $ret['link'] = $permalink;
        $ret['added_id'] = $new_post_id;        
        return $ret;
    }

    static function getMainWPDir($what = null, $dieOnError = true)
    {
        $upload_dir = wp_upload_dir();
        $dir = $upload_dir['basedir'] . DIRECTORY_SEPARATOR . 'mainwp' . DIRECTORY_SEPARATOR;
        self::checkDir($dir, $dieOnError);
        if (!file_exists($dir . 'index.php'))
        {
            @touch($dir . 'index.php');
        }
        $url = $upload_dir['baseurl'] . '/mainwp/';

        if ($what == 'backup')
        {
            $dir .= 'backup' . DIRECTORY_SEPARATOR;
            self::checkDir($dir, $dieOnError);
            if (!file_exists($dir . 'index.php'))
            {
                @touch($dir . 'index.php');
            }
            $url .= 'backup/';
        }

        return array($dir, $url);
    }

    static function checkDir($dir, $dieOnError)
    {
        MainWPHelper::getWPFilesystem();
        global $wp_filesystem;
        if (!file_exists($dir))
        {
            if (empty($wp_filesystem))
            {
                @mkdir($dir, 0777, true);
            }
            else
            {
                if (($wp_filesystem->method == 'ftpext') && defined('FTP_BASE'))
                {
                    $ftpBase = FTP_BASE;
                    $ftpBase = trailingslashit($ftpBase);
                    $tmpdir = str_replace(ABSPATH, $ftpBase, $dir);
                }
                else
                {
                    $tmpdir = $dir;
                }
                $wp_filesystem->mkdir($tmpdir, 0777);
            }

            if (!file_exists($dir))
            {
                $error = __('Unable to create directory ', 'mainwp-child') . str_replace(ABSPATH, '', $dir) . '.' . __(' Is its parent directory writable by the server?', 'mainwp-child');
                if ($dieOnError)
                    self::error($error);
                else
                    throw new Exception($error);
            }
        }
    }

    public static function validateMainWPDir()
    {
        $done = false;
        $dir = MainWPHelper::getMainWPDir();
        $dir = $dir[0];
        if (MainWPHelper::getWPFilesystem())
        {
            global $wp_filesystem;
            try
            {
                MainWPHelper::checkDir($dir, false);
            }
            catch (Exception $e)
            {

            }
            if (!empty($wp_filesystem))
            {
                if ($wp_filesystem->is_writable($dir)) $done = true;
            }
        }

        if (!$done)
        {
            if (!file_exists($dir)) @mkdirs($dir);
            if (is_writable($dir)) $done = true;
        }

        return $done;
    }

    static function search($array, $key)
    {
        if (is_object($array)) $array = (array)$array;
        if (is_array($array) || is_object($array))
        {
            if (isset($array[$key])) return $array[$key];

            foreach ($array as $subarray)
            {
                $result = self::search($subarray, $key);
                if ($result != null) return $result;
            }
        }

        return null;
    }

    /**
     * @return WP_Filesystem_Base
     */
    public static function getWPFilesystem()
    {
        global $wp_filesystem;

        if (empty($wp_filesystem))
        {
            ob_start();
            if (file_exists(ABSPATH . '/wp-admin/includes/deprecated.php')) include_once(ABSPATH . '/wp-admin/includes/deprecated.php');
            if (file_exists(ABSPATH . '/wp-admin/includes/screen.php')) include_once(ABSPATH . '/wp-admin/includes/screen.php');
            if (file_exists(ABSPATH . '/wp-admin/includes/template.php')) include_once(ABSPATH . '/wp-admin/includes/template.php');
            $creds = request_filesystem_credentials('test');
            ob_end_clean();
            if (empty($creds))
            {
                define('FS_METHOD', 'direct');
            }
            $init = WP_Filesystem($creds);
        }
        else
        {
            $init = true;
        }

        return $init;
    }

    public static function startsWith($haystack, $needle)
    {
        return !strncmp($haystack, $needle, strlen($needle));
    }

    public static function endsWith($haystack, $needle)
    {
        $length = strlen($needle);
        if ($length == 0) {
            return true;
        }

        return (substr($haystack, -$length) === $needle);
    }

    public static function getNiceURL($pUrl, $showHttp = false)
    {
        $url = $pUrl;

        if (self::startsWith($url, 'http://'))
        {
            if (!$showHttp) $url = substr($url, 7);
        }
        else if (self::startsWith($pUrl, 'https://'))
        {
            if (!$showHttp) $url = substr($url, 8);
        }
        else
        {
            if ($showHttp) $url = 'http://'.$url;
        }

        if (self::endsWith($url, '/'))
        {
            if (!$showHttp) $url = substr($url, 0, strlen($url) - 1);
        }
        else
        {
            $url = $url . '/';
        }
        return $url;
    }   

    public static function clean($string) {
        $string = trim($string);                  
        $string = htmlentities($string, ENT_QUOTES); 
        $string = str_replace("\n", "<br>", $string);
        if (get_magic_quotes_gpc()) {
                $string = stripslashes($string);
        } 
        return $string;
    }

    public static function endSession()
    {
        @session_write_close();
        @ob_end_flush();
    }

    static function fetchUrl($url, $postdata)
    {
        try
        {
            $tmpUrl = $url;
            if (substr($tmpUrl, -1) != '/') { $tmpUrl .= '/'; }

            return self::_fetchUrl($tmpUrl . 'wp-admin/', $postdata);
        }
        catch (Exception $e)
        {
            try
            {
                return self::_fetchUrl($url, $postdata);
            }
            catch (Exception $ex)
            {
                throw $e;
            }
        }
    }

    public static function _fetchUrl($url, $postdata)
    {
        $agent= 'Mozilla/4.0 (compatible; MSIE 5.01; Windows NT 5.0)';

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $postdata);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);
        curl_setopt($ch, CURLOPT_USERAGENT, $agent);
        $data = curl_exec($ch);
        $http_status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err = curl_error($ch);
        curl_close($ch);

        if (($data === false) && ($http_status == 0)) {
            throw new Exception('Http Error: ' . $err);
        }
        else if (preg_match('/<mainwp>(.*)<\/mainwp>/', $data, $results) > 0) {
            $result = $results[1];
            $information = unserialize(base64_decode($result));
            return $information;
        }
        else if ($data == '')
        {
            throw new Exception(__('Something went wrong while contacting the child site. Please check if there is an error on the child site. This error could also be caused by trying to clone or restore a site to large for your server settings.','mainwp-child'));
        }
        else
        {
            throw new Exception(__('Child plugin is disabled or the security key is incorrect. Please resync with your main installation.','mainwp-child'));
        }
    }


    public static function randString($length, $charset = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789')
    {
        $str = '';
        $count = strlen($charset);
        while ($length--)
        {
            $str .= $charset[mt_rand(0, $count - 1)];
        }
        return $str;
    }

    public static function return_bytes($val) {
        $val = trim($val);
        $last = strtolower($val[strlen($val)-1]);
        switch($last) {
            // The 'G' modifier is available since PHP 5.1.0
            case 'g':
                $val *= 1024;
            case 'm':
                $val *= 1024;
            case 'k':
                $val *= 1024;
        }

        return $val;
    }

    public static function human_filesize($bytes, $decimals = 2) {
        $size = array('B','kB','MB','GB','TB','PB','EB','ZB','YB');
        $factor = floor((strlen($bytes) - 1) / 3);
        return sprintf("%.{$decimals}f", $bytes / pow(1024, $factor)) . @$size[$factor];
    }

    public static function is_dir_empty($dir)
    {
      if (!is_readable($dir)) return null;
      return (count(scandir($dir)) == 2);
    }

    public static function delete_dir( $dir ) {
        $nodes = glob($dir . '*');

        if (is_array($nodes))
        {
            foreach ($nodes as $node)
            {
                if (is_dir($node))
                {
                    self::delete_dir($node . DIRECTORY_SEPARATOR);
                }
                else
                {
                    @unlink($node);
                }
            }
        }
        @rmdir($dir);
    }

    public static function function_exists($func) {
        if (!function_exists($func)) return false;

        if (extension_loaded('suhosin')) {
            $suhosin = @ini_get("suhosin.executor.func.blacklist");
            if (empty($suhosin) == false) {
                $suhosin = explode(',', $suhosin);
                $suhosin = array_map('trim', $suhosin);
                $suhosin = array_map('strtolower', $suhosin);
                return (function_exists($func) == true && array_search($func, $suhosin) === false);
            }
        }
        return true;
    }

    public static function getTimestamp($timestamp)
    {
        $gmtOffset = get_option('gmt_offset');

        return ($gmtOffset ? ($gmtOffset * HOUR_IN_SECONDS) + $timestamp : $timestamp);
    }

    public static function formatTimestamp($timestamp)
    {
        return date_i18n(get_option('date_format') . ' ' . get_option('time_format'), $timestamp);
    }
    
    public static function formatEmail($to, $body)
    {
        return '<br>
<div>
            <br>
            <div style="background:#ffffff;padding:0 1.618em;font:13px/20px Helvetica,Arial,Sans-serif;padding-bottom:50px!important">
                <div style="width:600px;background:#fff;margin-left:auto;margin-right:auto;margin-top:10px;margin-bottom:25px;padding:0!important;border:10px Solid #fff;border-radius:10px;overflow:hidden">
                    <div style="display: block; width: 100% ; background-image: url(http://mainwp.com/wp-content/uploads/2013/02/debut_light.png) ; background-repeat: repeat; border-bottom: 2px Solid #7fb100 ; overflow: hidden;">
                      <div style="display: block; width: 95% ; margin-left: auto ; margin-right: auto ; padding: .5em 0 ;">
                         <div style="float: left;"><a href="http://mainwp.com"><img src="http://mainwp.com/wp-content/uploads/2013/07/MainWP-Logo-1000-300x62.png" alt="MainWP" height="30"/></a></div>
                         <div style="float: right; margin-top: .6em ;">
                            <span style="display: inline-block; margin-right: .8em;"><a href="http://extensions.mainwp.com" style="font-family: Helvetica, Sans; color: #7fb100; text-transform: uppercase; font-size: 14px;">Extensions</a></span>
                            <span style="display: inline-block; margin-right: .8em;"><a style="font-family: Helvetica, Sans; color: #7fb100; text-transform: uppercase; font-size: 14px;" href="http://mainwp.com/forum">Support</a></span>
                            <span style="display: inline-block; margin-right: .8em;"><a style="font-family: Helvetica, Sans; color: #7fb100; text-transform: uppercase; font-size: 14px;" href="http://docs.mainwp.com">Documentation</a></span>
                            <span style="display: inline-block; margin-right: .5em;" class="mainwp-memebers-area"><a href="http://mainwp.com/member/login/index" style="padding: .6em .5em ; border-radius: 50px ; -moz-border-radius: 50px ; -webkit-border-radius: 50px ; background: #1c1d1b; border: 1px Solid #000; color: #fff !important; font-size: .9em !important; font-weight: normal ; -webkit-box-shadow:  0px 0px 0px 5px rgba(0, 0, 0, .1); box-shadow:  0px 0px 0px 5px rgba(0, 0, 0, .1);">Members Area</a></span>
                         </div><div style="clear: both;"></div>
                      </div>
                    </div>
                    <div>
                        <p>Hello MainWP User!<br></p>
                        ' . $body . '
                        <div></div>
                        <br />
                        <div>MainWP</div>
                        <div><a href="http://www.MainWP.com" target="_blank">www.MainWP.com</a></div>
                        <p></p>
                    </div>

                    <div style="display: block; width: 100% ; background: #1c1d1b;">
                      <div style="display: block; width: 95% ; margin-left: auto ; margin-right: auto ; padding: .5em 0 ;">
                        <div style="padding: .5em 0 ; float: left;"><p style="color: #fff; font-family: Helvetica, Sans; font-size: 12px ;">© 2013 MainWP. All Rights Reserved.</p></div>
                        <div style="float: right;"><a href="http://mainwp.com"><img src="http://mainwp.com/wp-content/uploads/2013/07/MainWP-Icon-300.png" height="45"/></a></div><div style="clear: both;"></div>
                      </div>
                   </div>
                </div>
                <center>
                    <br><br><br><br><br><br>
                    <table border="0" cellpadding="0" cellspacing="0" width="100%" style="background-color:#ffffff;border-top:1px solid #e5e5e5">
                        <tbody><tr>
                            <td align="center" valign="top" style="padding-top:20px;padding-bottom:20px">
                                <table border="0" cellpadding="0" cellspacing="0">
                                    <tbody><tr>
                                        <td align="center" valign="top" style="color:#606060;font-family:Helvetica,Arial,sans-serif;font-size:11px;line-height:150%;padding-right:20px;padding-bottom:5px;padding-left:20px;text-align:center">
                                            This email is sent from your MainWP Dashboard.
                                            <br>
                                            If you do not wish to receive these notices please re-check your preferences in the MainWP Settings page.
                                            <br>
                                            <br>
                                        </td>
                                    </tr>
                                </tbody></table>
                            </td>
                        </tr>
                    </tbody></table>

                </center>
            </div>
</div>
<br>';
    }
    
    static function update_option($option_name, $option_value)
    {
        $success = add_option($option_name, $option_value, '', 'no');

         if (!$success)
         {
             $success = update_option($option_name, $option_value);
         }

         return $success;
    }

    static function fix_option($option_name)
    {
        global $wpdb;

        if ( 'yes' == $wpdb->get_var( "SELECT autoload FROM $wpdb->options WHERE option_name = '" . $option_name . "'" ) )
        {
            $option_value = get_option( $option_name );
            delete_option( $option_name );
            add_option( $option_name, $option_value, null, 'no' );
        }
    }

    static function containsAll($haystack, $needle)
    {
        if (!is_array($haystack) || !is_array($needle)) return false;

        foreach ($needle as $item)
        {
            if (!in_array($item, $haystack)) return false;
        }

        return true;
    }
    
    public static function getRevisions($max_revisions)
    {
        global $wpdb;
        $sql = " SELECT	`post_parent`, COUNT(*) cnt
                FROM $wpdb->posts 
                WHERE `post_type` = 'revision'
                GROUP BY `post_parent`
                HAVING COUNT(*) > ".$max_revisions;
        return $wpdb -> get_results($sql);
    } 
    
    public static function deleteRevisions($results, $max_revisions)
    {
	global $wpdb;
        
        if (!is_array($results) || count($results) == 0)
            return;
        $count_deleted = 0;
	for($i=0; $i<count($results); $i++) {	
            $number_to_delete = $results[$i]->cnt - $max_revisions;	
            $count_deleted += $number_to_delete;
            $sql_get = "
                    SELECT `ID`, `post_modified`
                    FROM  $wpdb->posts
                    WHERE `post_parent`=".$results[$i]->post_parent."
                    AND `post_type`='revision'
                    ORDER BY `post_modified` ASC		
                ";
            $results_posts = $wpdb -> get_results($sql_get);      
            
            $delete_ids = array();
            if (is_array($results_posts) && count($results_posts) > 0) {
                for($j=0; $j< $number_to_delete; $j++)
                    $delete_ids[] = $results_posts[$j]->ID;
            }
           
            if (count($delete_ids) > 0) {
                $sql_delete = " DELETE FROM $wpdb->posts
                                WHERE `ID` IN (" . implode(",", $delete_ids) . ")
                            ";
                $wpdb -> get_results($sql_delete);
            } 
        }
        
        return $count_deleted;
    }

    public static function inExcludes($excludes, $value)
    {
        $inExcludes = false;
        if ($excludes != null)
        {
            foreach ($excludes as $exclude)
            {
                if (MainWPHelper::endsWith($exclude, '*'))
                {
                    if (MainWPHelper::startsWith($value, substr($exclude, 0, strlen($exclude) - 1)))
                    {
                        $inExcludes = true;
                        break;
                    }
                }
                else if ($value == $exclude)
                {
                    $inExcludes = true;
                    break;
                }
            }
        }
        return $inExcludes;
    }
}

?>
