<?php
/**
 * MainWP Post SMTP
 *
 * MainWP Post SMTP extension handler.
 *
 * @link https://mainwp.com/extension/post-smtp/
 * @package MainWP\Child
 *
 * Credits
 *
 * Plugin-Name: Post SMTP
 * Plugin-URI: https://www.postmansmtp.com/
 * Author: Post SMTP
 * Author URI: https://www.postmansmtp.com/
 */

namespace MainWP\Child;

/**
 * Class MainWP_Child_Post_SMTP
 *
 * MainWP Post SMTP extension handler.
 */
class MainWP_Child_Post_SMTP {

	/**
	 * Base URL
	 * 
	 * @var string
	 */
	private $base_url = false;


	/**
	 * Public static variable to hold the single instance of the class.
	 *
	 * @var mixed Default null
	 */
	public static $instance = null;

	/**
	 * Method instance()
	 *
	 * Create a public static instance.
	 *
	 * @return mixed Class instance.
	 */
	public static function get_instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}
	

	/**
	 * Constructor
	 * 
	 * @version 1.0
	 */
	public function __construct() {

		$server = get_option( 'mainwp_child_server' );

        if( $server ) {
            
			$this->base_url = wp_parse_url( $server, PHP_URL_SCHEME ) . "://" . wp_parse_url( $server, PHP_URL_HOST ) . '/wp-json/post-smtp-for-mainwp/v1/send-email';

			$this->base_url = parse_url( $server, PHP_URL_SCHEME ) . '://' . parse_url( $server, PHP_URL_HOST ) . '/' . 'wp-json/post-smtp-for-mainwp/v1/send-email';

		}
	}

	/**
     * Process email
     * 
     * @param string|array $to Array or comma-separated list of email addresses to send message.
     * @param string $subject Email subject.
     * @param string $message Message contents.
     * @param string|array $headers Optional. Additional headers.
     * @param string|array $attachments Optional. Files to attach.
     * @return bool Whether the email contents were sent successfully.
     * 
     * @version 1.0
     */
	public function process_email( $to, $subject, $message, $headers = '', $attachments = array() ) {
		
		$body = array();
		$pubkey = get_option( 'mainwp_child_pubkey' );
		$pubkey = $pubkey ? md5( $pubkey ) : '';
        $request_headers = array(
            'Site-URL'	=>	site_url( '/' ),
			'API-Key'	=>	$pubkey
        );
		
		//let's manage attachments.
		if( !empty( $attachments ) && $attachments ) {

			$_attachments = $attachments;
			$attachments = array();
			foreach( $_attachments as $attachment ) {
				
				$attachments[$attachment] = wp_remote_get( $attachment );
					
			}
		}

		$body = compact( 'to', 'subject', 'message', 'headers', 'attachments' );

		$response = wp_remote_post(
			$this->base_url,
			array(
				'method'    => 'POST',
				'body'      => $body,
				'headers'   => $request_headers,
			)
		);

		if ( wp_remote_retrieve_body( $response ) ) {

			return true;

		}
	}
	
	
	/**
	 * Action
	 * 
	 * @version 1.0
	 */
	public function action() {

		$mwp_action = ! empty( $_POST['mwp_action'] ) ? sanitize_text_field( wp_unslash( $_POST['mwp_action'] ) ) : '';
		switch ( $mwp_action ) {
			case 'enable_post_smtp':
				$information = $this->enable_from_main_site();
				break;
			case 'disable_post_smtp':
				$information = $this->disable_from_main_site();
				break;
		}

		MainWP_Helper::write( $information );
		
	}
	
	
	/**
	 * Enable from main site
	 * 
	 * @return bool
	 * @version 1.0
	 */
	public function enable_from_main_site() {
		
		return update_option( 'post_smtp_use_from_main_site', '1' );
		
	}

	
	/**
	 * Disable from main site
	 * 
	 * @return bool
	 * @version 1.0
	 */
	public function disable_from_main_site() {
		
		return delete_option( 'post_smtp_use_from_main_site' );
		
	}


	public function disable_from_main_site() {

		return delete_option( 'post_smtp_use_from_main_site' );
	}
}
