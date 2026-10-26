<?php
/**
 * Optional expected post type checks on legacy content calls.
 *
 * @package MainWP_Child
 */

namespace MainWP\Child;

use WP_UnitTestCase;

/** Captures the helper's terminal error response without ending PHPUnit. */
class Test_MainWP_Expected_Post_Type_Response extends \RuntimeException {
    public $response;

    public function __construct( $response ) {
        parent::__construct( 'Content response' );
        $this->response = $response;
    }
}

/** Keep the real error sanitization and replace only its terminal write. */
class Test_MainWP_Expected_Post_Type_Helper extends MainWP_Helper {
    public static function write( $value ) {
        throw new Test_MainWP_Expected_Post_Type_Response( $value );
    }
}

/** Exercise the complete callable bodies and their real WordPress side effects. */
class Test_MainWP_Child_Expected_Post_Type extends WP_UnitTestCase {
    private $original_post;
    private $original_user;
    private $original_helper;
    private $original_hooks;
    private $fixtures = array();
    private $before_updates = 0;

    public function set_up() {
        parent::set_up();
        $this->original_post   = $_POST;
        $this->original_user   = get_current_user_id();
        $this->original_helper = MainWP_Helper::$instance;
        MainWP_Helper::$instance = new Test_MainWP_Expected_Post_Type_Helper();
        wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
        $this->original_hooks = array();
        foreach ( array( 'trash_post', 'delete_post' ) as $hook ) {
            $this->original_hooks[ $hook ] = has_action( $hook, array( MainWP_Child_Links_Checker::get_class_name(), 'hook_post_deleted' ) );
        }
        add_action( 'mainwp_before_post_update', array( $this, 'count_before_update' ) );
    }

    public function tear_down() {
        remove_action( 'mainwp_before_post_update', array( $this, 'count_before_update' ) );
        foreach ( $this->original_hooks as $hook => $priority ) {
            if ( false === $priority ) {
                remove_action( $hook, array( MainWP_Child_Links_Checker::get_class_name(), 'hook_post_deleted' ) );
            }
        }
        foreach ( $this->fixtures as $fixture ) {
            unlink( $fixture );
        }
        MainWP_Helper::$instance = $this->original_helper;
        $_POST = $this->original_post;
        wp_set_current_user( $this->original_user );
        parent::tear_down();
    }

    public function count_before_update() {
        ++$this->before_updates;
    }

    /** @dataProvider action_refusals */
    public function test_post_action_refuses_without_changing_the_item( $action, $type, $expected ) {
        $id = $this->item( $action, $type );
        $before = $this->snapshot( $id );
        $_POST = $this->action_request( $action, $id );
        $_POST['expected_post_type'] = $expected;
        $this->assert_refused( $this->dispatch( 'post_action' ) );
        $this->assertSame( $before, $this->snapshot( $id ), 'Refusal must preserve the complete post and its metadata, including its edit lock.' );
    }

    public static function action_refusals() {
        $cases = array();
        foreach ( self::actions() as $action ) {
            foreach ( array( 'post', 'page' ) as $type ) {
                foreach ( array( 'mismatch' => 'post' === $type ? 'page' : 'post', 'unknown' => 'attachment' ) as $label => $expected ) {
                    $cases[ "$action-$type-$label" ] = array( $action, $type, $expected );
                }
            }
        }
        return $cases;
    }

    /** @dataProvider action_successes */
    public function test_post_action_matching_and_omitted_parameters_keep_legacy_results( $action, $type, $send ) {
        $id = $this->item( $action, $type );
        $_POST = $this->action_request( $action, $id );
        if ( $send ) {
            $_POST['expected_post_type'] = $type;
        }
        $response = $this->dispatch( 'post_action' );
        $this->assertSame( array( 'status', 'other_data', 'my_post' ), array_keys( $response ) );
        $this->assertSame( 'SUCCESS', $response['status'] );
        if ( 'delete' === $action ) {
            $this->assertNull( get_post( $id ) );
        } elseif ( 'get_edit' === $action ) {
            $edit = json_decode( base64_decode( $response['my_post']['new_post'] ), true );
            $this->assertSame( $type, $edit['post_type'] );
            $this->assertSame( 'Original content', $edit['post_content'] );
            $this->assertNotEmpty( get_post_meta( $id, '_edit_lock', true ) );
        } elseif ( 'update' === $action ) {
            $this->assertSame( 'Changed content', get_post( $id )->post_content );
        } elseif ( 'update_meta' === $action ) {
            $this->assertSame( 'changed', get_post_meta( $id, 'fixture_meta', true ) );
        } else {
            $statuses = array( 'publish' => 'publish', 'unpublish' => 'draft', 'trash' => 'trash', 'restore' => 'draft' );
            $this->assertSame( $statuses[ $action ], get_post_status( $id ) );
        }
    }

    public static function action_successes() {
        $cases = array();
        foreach ( self::actions() as $action ) {
            foreach ( array( 'post', 'page' ) as $type ) {
                foreach ( array( false, true ) as $send ) {
                    $cases[ "$action-$type-" . ( $send ? 'matching' : 'omitted' ) ] = array( $action, $type, $send );
                }
            }
        }
        return $cases;
    }

    /** @dataProvider page_edit_cases */
    public function test_page_edit_selector_preserves_payload_or_refuses_without_an_edit_lock( $expected, $refused ) {
        $id = $this->item( 'get_edit', 'page' );
        $before = $this->snapshot( $id );
        $_POST = $this->action_request( 'get_edit', $id );
        $_POST['post_type'] = 'page';
        if ( null !== $expected ) {
            $_POST['expected_post_type'] = $expected;
        }
        $response = $this->dispatch( 'post_action' );
        if ( $refused ) {
            $this->assert_refused( $response );
            $this->assertSame( $before, $this->snapshot( $id ) );
            $this->assertSame( '', get_post_meta( $id, '_edit_lock', true ) );
        } else {
            $this->assertSame( 'SUCCESS', $response['status'] );
            $edit = json_decode( base64_decode( $response['my_post']['new_post'] ), true );
            $this->assertSame( 'page', $edit['post_type'] );
            $this->assertSame( 'Original content', $edit['post_content'] );
            $this->assertArrayNotHasKey( 'post_category', $response['my_post'] );
            $this->assertNotEmpty( get_post_meta( $id, '_edit_lock', true ) );
        }
    }

    public static function page_edit_cases() {
        return array( 'matching' => array( 'page', false ), 'omitted' => array( null, false ), 'mismatch' => array( 'post', true ), 'unknown' => array( 'attachment', true ) );
    }

    /** @dataProvider missing_actions */
    public function test_missing_action_target_refuses_with_a_message( $action ) {
        $_POST = $this->action_request( $action, 999999999 );
        $_POST['expected_post_type'] = 'post';
        $this->assert_refused( $this->dispatch( 'post_action' ) );
    }

    public static function missing_actions() {
        return array_map( static function ( $action ) { return array( $action ); }, self::actions() );
    }

    /** @dataProvider invalid_types */
    public function test_invalid_parameter_shapes_refuse( $expected ) {
        $id = $this->item( 'trash', 'post' );
        $before = $this->snapshot( $id );
        $_POST = $this->action_request( 'trash', $id );
        $_POST['expected_post_type'] = $expected;
        $this->assert_refused( $this->dispatch( 'post_action' ) );
        $this->assertSame( $before, $this->snapshot( $id ) );
    }

    public static function invalid_types() {
        return array( 'empty' => array( '' ), 'null' => array( null ), 'array' => array( array( 'post' ) ), 'html' => array( '<b>post</b>' ), 'whitespace' => array( ' post ' ), 'percent' => array( 'po%20st' ) );
    }

    /** @dataProvider newpost_malformed_types */
    public function test_newpost_edit_refuses_normalized_unknown_types( $expected, $custom ) {
        $id = $this->item( 'update', 'post' );
        $before = $this->snapshot( $id );
        $_POST = $this->newpost_request( $id, 'post', $custom );
        $_POST['expected_post_type'] = $expected;
        $this->assert_refused( $this->dispatch( 'new_post' ) );
        $this->assertSame( $before, $this->snapshot( $id ) );
        $this->assertSame( 0, $this->before_updates );
    }

    public static function newpost_malformed_types() {
        $cases = array();
        foreach ( array( '<b>post</b>', ' post ', 'po%20st' ) as $expected ) {
            foreach ( array( false, true ) as $custom ) {
                $cases[] = array( $expected, $custom );
            }
        }
        return $cases;
    }

    public function test_update_checks_post_data_id_instead_of_the_outer_id() {
        $post = $this->item( 'update', 'post' );
        $page = $this->item( 'update', 'page' );
        $before_post = $this->snapshot( $post );
        $before_page = $this->snapshot( $page );
        $_POST = $this->action_request( 'update', $page );
        $_POST['id'] = (string) $post;
        $_POST['expected_post_type'] = 'post';
        $this->assert_refused( $this->dispatch( 'post_action' ) );
        $this->assertSame( $before_post, $this->snapshot( $post ) );
        $this->assertSame( $before_page, $this->snapshot( $page ) );
        $_POST['expected_post_type'] = 'page';
        $this->assertSame( 'SUCCESS', $this->dispatch( 'post_action' )['status'] );
        $this->assertSame( 'Changed content', get_post( $page )->post_content );
        $this->assertSame( $before_post, $this->snapshot( $post ) );
    }

    public function test_update_without_a_post_data_id_refuses_even_when_the_outer_id_exists() {
        $id = $this->item( 'update', 'post' );
        $before = $this->snapshot( $id );
        $_POST = $this->action_request( 'update', $id );
        $_POST['post_data'] = array( 'post_content' => 'Changed content' );
        $_POST['expected_post_type'] = 'post';
        $this->assert_refused( $this->dispatch( 'post_action' ) );
        $this->assertSame( $before, $this->snapshot( $id ) );
    }

    /** @dataProvider newpost_refusals */
    public function test_newpost_edit_refuses_before_hooks_or_changes( $type, $custom, $expected ) {
        $id = $this->item( 'update', $type );
        $before = $this->snapshot( $id );
        $_POST = $this->newpost_request( $id, $type, $custom );
        $_POST['expected_post_type'] = $expected;
        $this->assert_refused( $this->dispatch( 'new_post' ) );
        $this->assertSame( $before, $this->snapshot( $id ) );
        $this->assertSame( 0, $this->before_updates );
    }

    public static function newpost_refusals() {
        $cases = array();
        foreach ( array( 'post', 'page' ) as $type ) {
            foreach ( array( false, true ) as $custom ) {
                foreach ( array( 'mismatch' => 'post' === $type ? 'page' : 'post', 'unknown' => 'attachment' ) as $label => $expected ) {
                    $cases[ "$type-" . ( $custom ? 'custom' : 'ID' ) . "-$label" ] = array( $type, $custom, $expected );
                }
            }
        }
        return $cases;
    }

    /** @dataProvider newpost_successes */
    public function test_newpost_matching_and_omitted_parameters_keep_legacy_results( $type, $custom, $send ) {
        $id = $this->item( 'update', $type );
        $_POST = $this->newpost_request( $id, $type, $custom );
        if ( $send ) {
            $_POST['expected_post_type'] = $type;
        }
        $response = $this->dispatch( 'new_post' );
        $this->assertSame( array( 'added', 'added_id', 'link', 'other_data' ), array_keys( $response ) );
        $this->assertTrue( $response['added'] );
        $this->assertSame( $id, $response['added_id'] );
        $this->assertSame( 'Changed content', get_post( $id )->post_content );
        $this->assertSame( 'changed', get_post_meta( $id, 'fixture_meta', true ) );
        $this->assertSame( 1, $this->before_updates );
    }

    public static function newpost_successes() {
        $cases = array();
        foreach ( array( 'post', 'page' ) as $type ) {
            foreach ( array( false, true ) as $custom ) {
                foreach ( array( false, true ) as $send ) {
                    $cases[ "$type-" . ( $custom ? 'custom' : 'ID' ) . '-' . ( $send ? 'matching' : 'omitted' ) ] = array( $type, $custom, $send );
                }
            }
        }
        return $cases;
    }

    /** @dataProvider newpost_conflicts */
    public function test_newpost_checks_both_update_and_unlock_targets( $type, $expected ) {
        $other = 'post' === $type ? 'page' : 'post';
        $direct_id = $this->item( 'update', $type );
        $custom_id = $this->item( 'update', $other );
        $before_direct = $this->snapshot( $direct_id );
        $before_custom = $this->snapshot( $custom_id );
        $_POST = $this->newpost_request( $direct_id, $type, false );
        $_POST['post_custom'] = base64_encode( wp_json_encode( array( '_mainwp_edit_post_id' => array( $custom_id ), 'fixture_meta' => array( 'changed' ) ) ) );
        $_POST['expected_post_type'] = $expected;
        $this->assert_refused( $this->dispatch( 'new_post' ) );
        $this->assertSame( $before_direct, $this->snapshot( $direct_id ) );
        $this->assertSame( $before_custom, $this->snapshot( $custom_id ) );
        $this->assertSame( 0, $this->before_updates );
    }

    public static function newpost_conflicts() {
        return array( array( 'post', 'post' ), array( 'post', 'page' ), array( 'page', 'post' ), array( 'page', 'page' ) );
    }

    /** @dataProvider creation_types */
    public function test_newpost_ignores_a_direct_id_that_the_selected_edit_will_replace( $type ) {
        $other = 'post' === $type ? 'page' : 'post';
        $unused_id = $this->item( 'update', $other );
        $selected_id = $this->item( 'update', $type );
        $before_unused = $this->snapshot( $unused_id );
        $_POST = $this->newpost_request( $unused_id, $type, false );
        $_POST['post_custom'] = base64_encode( wp_json_encode( array( '_mainwp_edit_post_id' => array( $selected_id ) ) ) );
        $_POST['expected_post_type'] = $type;
        $response = $this->dispatch( 'new_post' );
        $this->assertTrue( $response['added'] );
        $this->assertSame( $selected_id, $response['added_id'] );
        $this->assertSame( 'Changed content', get_post( $selected_id )->post_content );
        $this->assertSame( $before_unused, $this->snapshot( $unused_id ) );
    }

    public function test_newpost_missing_edit_target_refuses() {
        $_POST = $this->newpost_request( 999999999, 'post', false );
        $_POST['expected_post_type'] = 'post';
        $this->assert_refused( $this->dispatch( 'new_post' ) );
        $this->assertSame( 0, $this->before_updates );
    }

    /** @dataProvider creation_types */
    public function test_newpost_creation_is_unchanged_with_an_unknown_optional_type( $type ) {
        $_POST = $this->newpost_request( 0, $type, false );
        $_POST['expected_post_type'] = 'attachment';
        $response = $this->dispatch( 'new_post' );
        $this->assertTrue( $response['added'] );
        $this->assertSame( $type, get_post_type( $response['added_id'] ) );
        $this->assertSame( 'Changed content', get_post( $response['added_id'] )->post_content );
    }

    public static function creation_types() {
        return array( array( 'post' ), array( 'page' ) );
    }

    private static function actions() {
        return array( 'publish', 'unpublish', 'update', 'trash', 'delete', 'restore', 'update_meta', 'get_edit' );
    }

    private function item( $action, $type ) {
        $id = self::factory()->post->create( array( 'post_type' => $type, 'post_status' => 'publish' === $action ? 'draft' : 'publish', 'post_title' => 'Fixture item', 'post_content' => 'Original content' ) );
        update_post_meta( $id, 'fixture_meta', 'original' );
        if ( 'restore' === $action ) {
            wp_trash_post( $id );
        }
        return $id;
    }

    private function snapshot( $id ) {
        $post = get_post( $id );
        return array( $post ? $post->to_array() : null, get_post_meta( $id ) );
    }

    private function assert_refused( $response ) {
        $this->assertSame( 'FAIL', $response['status'] ?? null );
        $this->assertNotEmpty( $response['error'] ?? '' );
        $this->assertIsString( $response['error'] );
    }

    private function action_request( $action, $id ) {
        return array( 'action' => $action, 'id' => (string) $id, 'post_type' => 'post', 'post_data' => array( 'ID' => $id, 'post_content' => 'Changed content' ), 'values' => base64_encode( wp_json_encode( array( 'meta_key' => array( 'fixture_meta' ), 'meta_value' => array( 'changed' ), 'check_prev' => array( 0 ) ) ) ) );
    }

    private function newpost_request( $id, $type, $custom ) {
        $post = array( 'post_type' => $type, 'post_title' => 'Fixture item', 'post_content' => 'Changed content', 'post_status' => 'publish' );
        $meta = array( 'fixture_meta' => array( 'changed' ) );
        if ( $id && $custom ) {
            $meta['_mainwp_edit_post_id'] = array( $id );
        } elseif ( $id ) {
            $post['ID'] = $id;
        }
        return array( 'new_post' => base64_encode( wp_json_encode( $post ) ), 'post_custom' => base64_encode( wp_json_encode( $meta ) ), 'mainwp_upload_dir' => base64_encode( wp_json_encode( wp_upload_dir() ) ) );
    }

    /** Replace only terminal response writes; execute the original method and private helpers. */
    private function dispatch( $name ) {
        $method = new \ReflectionMethod( MainWP_Child_Posts::class, $name );
        $lines = file( $method->getFileName() );
        $source = implode( '', array_slice( $lines, $method->getStartLine() - 1, $method->getEndLine() - $method->getStartLine() + 1 ) );
        $source = str_replace( 'public function ' . $name . '()', 'return function()', $source, $declarations );
        $source = str_replace( 'MainWP_Helper::write( $information );', 'return $information;', $source, $writes );
        $this->assertSame( 1, $declarations );
        $this->assertGreaterThanOrEqual( 1, $writes );
        $fixture = tempnam( get_temp_dir(), 'mwp2020-' );
        $this->fixtures[] = $fixture;
        $this->assertNotFalse( file_put_contents( $fixture, '<?php namespace MainWP\\Child;' . "\n" . $source . ';' ) );
        $callable = require $fixture;
        $callable = $callable->bindTo( MainWP_Child_Posts::get_instance(), MainWP_Child_Posts::class );
        try {
            return $callable();
        } catch ( Test_MainWP_Expected_Post_Type_Response $response ) {
            return $response->response;
        }
    }
}
