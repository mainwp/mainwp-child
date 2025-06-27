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
        if ( null === static::$instance ) {
            static::$instance = new self();
        }
        MainWP_Connect_Lib::autoload_files(); // to fix.
        return static::$instance;
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
     * @param string $scheme scheme key.
     * @param int    $length length of key.
     *
     * @return string Decrypt value.
     */
    public function get_key_val( $scheme = 'auth', $length = 16 ) {
        if ( ! in_array( $scheme, array( 'auth', 'nonce' ), true ) ) {
            $scheme = 'auth';
        }
        return substr( wp_salt( $scheme ), 0, $length );
    }

    /**
     * Method get_sodium_key()
     *
     * Get key value for sodium.
     *
     * @return string key value.
     */
    public function get_sodium_key() {
        $keybytes     = defined( 'SODIUM_CRYPTO_SECRETBOX_KEYBYTES' ) ? SODIUM_CRYPTO_SECRETBOX_KEYBYTES : 32; //phpcs:ignore -- ok.
        return $this->get_key_val( 'auth', $keybytes ); // must be CRYPTO_SECRETBOX_KEYBYTES long.
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
        MainWP_Connect_Lib::autoload_files(); // to fix.
        if ( ! $this->valid_phpseclib3_supported() ) {
            if ( is_callable( 'sodium_crypto_secretbox' ) ) {
                $nonce        = $this->get_key_val( 'nonce', 24 ); // $nonce A Number to be used Once; must be 24 bytes.
                $key          = $this->get_sodium_key();
                $encodedValue = sodium_crypto_secretbox( $keypass, $nonce, $key ); //phpcs:ignore -- ok.
                $encodedValue = base64_encode( $encodedValue ); //phpcs:ignore -- safe.
            } else {
                $encodedValue = MainWP_Utility::encrypt_decrypt( $keypass );
            }
            return $encodedValue;
        } else {
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
                return base64_encode( $encryptedValue ); //phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions -- safe values.
            } catch ( MainWP_Exception $ex ) {
                // error.
            }
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

        $result = '';

        if ( ! $this->valid_phpseclib3_supported() ) {
            if ( is_callable( 'sodium_crypto_secretbox_open' ) ) {
                $encodedValue = base64_decode( $encodedValue ); //phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions -- safe.
                $nonce        = $this->get_key_val( 'nonce', 24 ); // $nonce A Number to be used Once; must be 24 bytes.
                $key          = $this->get_sodium_key();
                $decoded      = sodium_crypto_secretbox_open( $encodedValue, $nonce, $key ); //phpcs:ignore -- ok.
            } else {
                $decoded = MainWP_Utility::encrypt_decrypt( $encodedValue, false );
            }
            $result = $decoded;
        } else {

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
                $result = $aes->decrypt( $ciphertext );

            } catch ( MainWP_Exception $ex ) {
                // error.
            } catch ( \Exception $e ) { // NOSONAR - ok.
                // error.
            }
        }
        return $result;
    }

    /**
     * Method get_encrypted_option()
     *
     * Handle get encrypted value.
     *
     * @param string $option option name.
     * @param mixed  $def_value default value (option), default: false.
     *
     * @return string Decrypt value.
     */
    public static function get_encrypted_option( $option, $def_value = false ) {

        $val = get_option( $option, $def_value );

        if ( empty( $val ) ) {
            return $val;
        }

        $dec_val = static::instance()->decrypt_string( $val );
        if ( empty( $dec_val ) ) {
            return $val; // it's not encrypted or error happen.
        }

        return $dec_val;
    }

    /**
     * Method hook_get_encrypted_option()
     *
     * Handle get encrypted option value.
     *
     * @param string $empty_value empty input value.
     * @param string $option option name.
     * @param mixed  $def_value default value (option), default: false.
     *
     * @return string Decrypt value.
     */
    public static function hook_get_encrypted_option( $empty_value, $option, $def_value = false ) {
        unset( $empty_value );
        return static::get_encrypted_option( $option, $def_value );
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

        $enc_val = static::instance()->encrypt_string( $value );

        if ( empty( $enc_val ) ) {
            $enc_val = $value; // error happen.
        }

        return MainWP_Helper::update_option( $option, $enc_val );
    }

    /**
     * Method valid_phpseclib3_supported()
     *
     * @return bool true|false valid supported phpseclib3 or not.
     */
    public function valid_phpseclib3_supported() {
        if ( is_callable( 'php_uname' ) ) {
            return true;
        }
        return false;
    }
}
