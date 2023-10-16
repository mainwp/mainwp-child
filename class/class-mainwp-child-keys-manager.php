<?php
/**
 *
 * Encrypts & Decrypts API Keys.
 *
 * @package MainWP/Child
 */

namespace MainWP\Child;

use phpseclib3\Crypt\AES;
use phpseclib3\Crypt\Random;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class MainWP_Child_Keys_Manager
 *
 * @package MainWP/Child
 */
class MainWP_Child_Keys_Manager {

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
		MainWP_Connect_Lib::autoload_files(); // to fix.
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
	 * Method get_key_val()
	 *
	 * Get salt value.
	 *
	 * @return string Decrypt value.
	 */
	public function get_key_val() {
		return substr( wp_salt( 'auth' ), 0, 16 );
	}

	/**
	 * Method encrypt_string()
	 *
	 * Handle encrypt string.
	 *
	 * @param mixed $keypass The value to encrypt.
	 *
	 * @return string Encrypted value.
	 */
	public function encrypt_string( $keypass ) {

		try {
			$key = $this->get_key_val();

			// Generate a random IV (Initialization Vector).
			$iv = Random::string( 16 );

			// Create AES instance.
			$aes = new AES( 'gcm' ); // MODE_GCM.
			$aes->setKey( $key );

			$aes->setNonce( $iv ); // Nonces are only used in GCM mode.
			$aes->setAAD( 'authentication_data' ); // only used in GCM mode.

			// Encrypt the value.
			$ciphertext = $aes->encrypt( $keypass );

			// Get the authentication tag.
			$tag = $aes->getTag();

			// Combine IV, ciphertext, and tag.
			$encryptedValue = $iv . $ciphertext . $tag;

			// Encode the encrypted value using base64 for storage.
			$encodedValue = base64_encode( $encryptedValue ); //phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions -- safe values.

			return $encodedValue;
		} catch ( \Exception $ex ) {
			// error.
		}
		return '';
	}

	/**
	 * Method decrypt_string()
	 *
	 * Handle decrypt value.
	 *
	 * @param mixed $encodedValue The value to decrypt.
	 *
	 * @return string Decrypt value.
	 */
	public function decrypt_string( $encodedValue ) {

		if ( empty( $encodedValue ) ) {
			return '';
		}

		try {
			$key = $this->get_key_val();

			// Decode the base64 encoded value.
			$encryptedValue = base64_decode( $encodedValue ); //phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions -- safe.

			// Extract the IV, ciphertext, and tag.
			$iv         = substr( $encryptedValue, 0, 16 );
			$ciphertext = substr( $encryptedValue, 16, -16 );
			$tag        = substr( $encryptedValue, -16 );

			// Create AES instance.
			$aes = new AES( 'gcm' ); // MODE_GCM.
			$aes->setKey( $key );

			$aes->setNonce( $iv );  // Nonces are only used in GCM mode.
			$aes->setAAD( 'authentication_data' ); // only used in GCM mode.

			// Set the authentication tag.
			$aes->setTag( $tag );

			// Decrypt the value.
			$keypass = $aes->decrypt( $ciphertext );

			return $keypass;
		} catch ( \Exception $ex ) {
			// error.
		}
		return '';
	}

	/**
	 * Method get_encrypted_option()
	 *
	 * Handle get encrypted value.
	 *
	 * @param string $option option name.
	 * @param mixed  $default default value (option), default: false.
	 *
	 * @return string Decrypt value.
	 */
	public static function get_encrypted_option( $option, $default = false ) {

		$val = get_option( $option, $default );

		if ( empty( $val ) ) {
			return $val;
		}

		$dec_val = self::instance()->decrypt_string( $val );
		if ( empty( $dec_val ) ) {
			return $val; // it's not encrypted or error happen.
		}

		return $dec_val;
	}



	/**
	 * Method update_encrypted_option()
	 *
	 * Handle update encrypted value.
	 *
	 * @param string $option option name.
	 * @param string $value option value.
	 *
	 * @return bool true|false updated success or failed.
	 */
	public static function update_encrypted_option( $option, $value ) {
		if ( empty( $value ) ) {
			return MainWP_Helper::update_option( $option, $value );
		}

		$enc_val = self::instance()->encrypt_string( $value );

		if ( empty( $enc_val ) ) {
			$enc_val = $value; // error happen.
		}

		return MainWP_Helper::update_option( $option, $enc_val );
	}
}
