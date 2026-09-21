<?php
/**
 * Checked post deletion result tests.
 *
 * @package MainWP_Child
 */

namespace MainWP\Child;

use WP_UnitTestCase;

class Test_MainWP_Child_Post_Delete_Result extends WP_UnitTestCase {

	public function test_delete_proves_effect_and_repeat_is_idempotent() {
		$post_id = self::factory()->post->create();
		$subject = MainWP_Child_Posts::get_instance();

		$this->assertSame(
			array( 'deleted' => true, 'already_absent' => false ),
			$subject->delete_post_with_result( $post_id )
		);
		$this->assertNull( get_post( $post_id ) );
		$this->assertSame(
			array( 'deleted' => false, 'already_absent' => true ),
			$subject->delete_post_with_result( $post_id )
		);
	}

	public function test_delete_rejects_noncanonical_identifiers_without_effect() {
		$post_id = self::factory()->post->create();
		$subject = MainWP_Child_Posts::get_instance();

		foreach ( array( 0, -1, '01', '1junk', array( 1 ), null ) as $invalid ) {
			$this->assertSame(
				array( 'deleted' => false, 'already_absent' => false ),
				$subject->delete_post_with_result( $invalid )
			);
		}
		$this->assertInstanceOf( '\\WP_Post', get_post( $post_id ) );
		wp_delete_post( $post_id, true );
	}
}
