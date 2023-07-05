<?php
/**
 * MainWP Child Connect Lib
 *
 * MainWP Child Connect Lib functions.
 *
 * @package MainWP/Child
 */

namespace MainWP\Child;

use phpseclib3\Crypt\RSA;
use phpseclib3\Crypt\PublicKeyLoader;


/**
 * Class MainWP_Connect_Lib
 *
 * @package MainWP\Child
 */
class MainWP_Connect_Lib {

	/**
	 * Private static variable to hold the single instance of the class.
	 *
	 * @static
	 *
	 * @var mixed Default null
	 */
	private static $instance = null;

	/**
	 * Method instance()
	 *
	 * Create a public static instance.
	 *
	 * @static
	 * @return Instance class.
	 */
	public static function instance() {
		if ( null == self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Method get_class_name()
	 *
	 * Get Class Name.
	 *
	 * @return object Class name.
	 */
	public static function get_class_name() {
		return __CLASS__;
	}

	/**
	 * Class constructor.
	 *
	 * Run each time the class is called.
	 */
	public function __construct() {
		// constructor.
	}

	/**
	 * Method autoload_files()
	 *
	 * Handle autoload files.
	 */
	public static function autoload_files() {
		require_once MAINWP_CHILD_PLUGIN_DIR . 'libs' . DIRECTORY_SEPARATOR . 'phpseclib' . DIRECTORY_SEPARATOR . 'vendor' . DIRECTORY_SEPARATOR . 'autoload.php';
	}

	/**
	 * Method verify()
	 *
	 * Verify data.
	 *
	 * @param  mixed $data  Data to verify.
	 * @param  mixed $signature  signature to verify.
	 * @param  mixed $pubkey  pubkey to verify.
	 */
	public static function verify( $data, $signature, $pubkey ) {
		self::autoload_files();
		try {
			//phpcs:ignore -- Note.
			// RSA::useInternalEngine(); // to use PHP engine.
			$public = PublicKeyLoader::loadPublicKey( $pubkey );
			return $public->verify( $data, $signature ) ? 1 : 0;
		} catch ( \Exception $ex ) {
			// error happen.
		}
		return -1;
	}
}
