<?php
/**
 * Safety archive requests authenticate their operation and binding.
 *
 * @package MainWP_Child
 */

namespace MainWP\Child;

class BackupSafetySignatureTest extends \WP_UnitTestCase {
    private static $key;
    private $globals;
    private $checked;

    public function set_up() {
        parent::set_up();
        $this->globals = array( $_POST, $_REQUEST );
        $this->checked = new \ReflectionProperty( MainWP_Connect::class, 'signature_checked' );
        $this->checked->setAccessible( true );
        $this->checked->setValue( null, null );
        if ( null === self::$key ) {
            self::$key = openssl_pkey_new( array( 'private_key_bits' => 2048 ) );
        }
        $details = openssl_pkey_get_details( self::$key );
        update_option( 'mainwp_child_pubkey', base64_encode( $details['key'] ) );
        delete_option( 'mainwp_child_openssl_sign_algo' );
    }

    public function tear_down() {
        list( $_POST, $_REQUEST ) = $this->globals;
        $this->checked->setValue( null, null );
        parent::tear_down();
    }

    /** @dataProvider requests */
    public function test_safety_request_requires_signed_operation_and_binding( $change, $expected ) {
        $function = 'ordinary legacy' === $change ? 'stats' : 'backup_safety_archive';
        $_POST = array( 'function' => $function, 'user' => 'admin', 'nonce' => wp_generate_uuid4(), 'remove' => '0', 'binding' => '{"task_id":"owned-task"}', 'sign_algo' => OPENSSL_ALGO_SHA256 );
        $signed = array( 'req_id' => wp_generate_uuid4(), 'base_function' => $function, 'user' => 'admin', 'nonce' => $_POST['nonce'], 'expires' => time() + 60, 'remove' => '0', 'binding' => $_POST['binding'] );
        if ( 'delete' === $change ) {
            $_POST['remove'] = '1';
            $signed['remove'] = '1';
        } elseif ( 'missing fields' === $change ) {
            unset( $signed['remove'], $signed['binding'] );
        }
        $data = wp_json_encode( $signed );
        $this->assertTrue( openssl_sign( $data, $signature, self::$key, OPENSSL_ALGO_SHA256 ) );
        $legacy = '';
        if ( in_array( $change, array( 'legacy', 'ordinary legacy' ), true ) ) {
            $this->assertTrue( openssl_sign( $function . $_POST['nonce'], $legacy_bytes, self::$key, OPENSSL_ALGO_SHA256 ) );
            $legacy = base64_encode( $legacy_bytes );
            $data = null;
        } elseif ( 'tampered remove' === $change ) {
            $_POST['remove'] = '1';
        } elseif ( 'tampered binding' === $change ) {
            $_POST['binding'] = '{"task_id":"another-task"}';
        }
        $_REQUEST = $_POST;
        if ( 'request shadow' === $change ) {
            $_POST['remove'] = '1';
        } elseif ( 'request shadow reversed' === $change ) {
            $_REQUEST['remove'] = '1';
        }
        try {
            $accepted = 1 === MainWP_Connect::instance()->auth( $legacy, $function, $_POST['nonce'], array( 'connect_sign' => $data, 'mainwpsignature_adv' => base64_encode( $signature ) ) );
        } catch ( MainWP_Exception $error ) {
            $accepted = false;
        }
        $this->assertSame( $expected, $accepted );
    }

    public static function requests() {
        return array(
            'probe' => array( 'probe', true ),
            'delete' => array( 'delete', true ),
            'tampered operation' => array( 'tampered remove', false ),
            'tampered identity' => array( 'tampered binding', false ),
            'unsigned fields' => array( 'missing fields', false ),
            'legacy downgrade' => array( 'legacy', false ),
            'POST differs from REQUEST' => array( 'request shadow', false ),
            'REQUEST cannot override signed POST' => array( 'request shadow reversed', true ),
            'ordinary legacy unaffected' => array( 'ordinary legacy', true ),
        );
    }
}
