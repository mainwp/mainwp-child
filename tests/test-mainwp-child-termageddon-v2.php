<?php
/**
 * Termageddon page protocol-v2 tests.
 *
 * @package MainWP_Child
 */

namespace MainWP\Child;

use WP_UnitTestCase;

class Test_MainWP_Child_Termageddon_V2 extends WP_UnitTestCase {

	/** @var MainWP_Child_Termageddon */
	private $subject;

	/** @var string */
	private $page_ref;

	/** @var string */
	private $site_generation;

	public function set_up(): void {
		parent::set_up();
		delete_option( 'mainwp_child_termageddon_v2_receipts' );
		$this->subject         = new MainWP_Child_Termageddon();
		$this->page_ref       = str_repeat( 'a', 64 );
		$this->site_generation = str_repeat( 'b', 64 );
	}

	public function test_get_returns_only_closed_redacted_identity() {
		$post_id = $this->create_page( 'Private policy body' );
		$result  = $this->subject->get_page_v2( $this->get_request( $post_id, 'Private policy body' ) );

		$this->assertSame( array( 'contract_version', 'operation', 'ok', 'found', 'state', 'post_type', 'post_status', 'content_generation', 'content_hash' ), array_keys( $result ) );
		$this->assertTrue( $result['ok'] );
		$this->assertTrue( $result['found'] );
		$this->assertSame( 'current', $result['state'] );
		$this->assertSame( hash( 'sha256', 'Private policy body' ), $result['content_hash'] );
		$this->assertStringNotContainsString( 'Private policy body', wp_json_encode( $result ) );
	}

	public function test_content_bound_page_answers_current_then_deletes_with_truthful_readback() {
		$post_id = $this->create_page( 'Bound body' );
		$request = array_merge(
			$this->get_request( $post_id, 'Bound body' ),
			array(
				'request_ref' => '123e4567-e89b-42d3-a456-426614173010',
				'dry_run'     => false,
			)
		);

		$this->assertSame( 'current', $this->subject->get_page_v2( $this->get_request( $post_id, 'Bound body' ) )['state'] );

		$deleted = $this->subject->delete_page_v2( $request );
		$this->assertSame( 'deleted', $deleted['state'] );
		$this->assertFalse( $deleted['exists_after'] );
		$this->assertNull( get_post( $post_id ) );

		$readback = $this->subject->get_page_v2( $this->get_request( $post_id, 'Bound body' ) );
		$this->assertFalse( $readback['found'] );
		$this->assertSame( 'not_found', $readback['state'] );
	}

	public function test_get_rejects_reused_drifted_and_mistargeted_pages() {
		$reused = $this->create_page( 'Some unrelated page that now holds this id' );
		$result = $this->subject->get_page_v2( $this->get_request( $reused, 'Original' ) );
		$this->assertSame( 'content_drift', $result['state'] );
		$this->assertSame( hash( 'sha256', 'Original' ), $result['content_generation'] );
		$this->assertSame( hash( 'sha256', 'Some unrelated page that now holds this id' ), $result['content_hash'] );

		$drifted = $this->create_page( 'Original' );
		wp_update_post( array( 'ID' => $drifted, 'post_content' => 'Changed' ) );
		$this->assertSame( 'content_drift', $this->subject->get_page_v2( $this->get_request( $drifted, 'Original' ) )['state'] );

		$wrong_type = $this->create_page( 'Original', 'post' );
		$this->assertSame( 'type_mismatch', $this->subject->get_page_v2( $this->get_request( $wrong_type, 'Original' ) )['state'] );

		$wrong_status = $this->create_page( 'Original', 'page', 'draft' );
		$this->assertSame( 'status_mismatch', $this->subject->get_page_v2( $this->get_request( $wrong_status, 'Original' ) )['state'] );
	}

	public function test_delete_refuses_drifted_and_mistargeted_pages() {
		$drifted = $this->create_page( 'Original' );
		wp_update_post( array( 'ID' => $drifted, 'post_content' => 'Changed' ) );
		$request = array_merge(
			$this->get_request( $drifted, 'Original' ),
			array(
				'request_ref' => '123e4567-e89b-42d3-a456-426614173011',
				'dry_run'     => false,
			)
		);
		$this->assertSame( 'target_drift', $this->subject->delete_page_v2( $request )['code'] );
		$this->assertNotNull( get_post( $drifted ) );

		$wrong_status               = $this->create_page( 'Original', 'page', 'draft' );
		$request['post_id']         = $wrong_status;
		$request['request_ref']     = '123e4567-e89b-42d3-a456-426614173012';
		$this->assertSame( 'target_drift', $this->subject->delete_page_v2( $request )['code'] );
		$this->assertNotNull( get_post( $wrong_status ) );
	}

	public function test_delete_previews_then_deletes_with_terminal_readback_and_replay() {
		$post_id = $this->create_page( 'Delete me' );
		$request = array_merge(
			$this->get_request( $post_id, 'Delete me' ),
			array(
				'request_ref' => '123e4567-e89b-42d3-a456-426614173001',
				'dry_run'     => true,
			)
		);

		$preview = $this->subject->delete_page_v2( $request );
		$this->assertSame( 'ready', $preview['state'] );
		$this->assertTrue( $preview['exists_after'] );
		$this->assertNotNull( get_post( $post_id ) );

		$request['dry_run'] = false;
		$deleted            = $this->subject->delete_page_v2( $request );
		$this->assertSame( 'deleted', $deleted['state'] );
		$this->assertFalse( $deleted['exists_after'] );
		$this->assertNull( get_post( $post_id ) );
		$this->assertSame( $deleted, $this->subject->delete_page_v2( $request ) );
	}

	public function test_delete_rejects_uuid_id_alias_and_mismatched_replay() {
		$post_id = $this->create_page( 'Keep me' );
		$request = array_merge(
			$this->get_request( $post_id, 'Keep me' ),
			array(
				'request_id' => '123e4567-e89b-42d3-a456-426614173002',
				'dry_run'    => false,
			)
		);
		$this->assertSame( 'invalid_request', $this->subject->delete_page_v2( $request )['code'] );
		$this->assertNotNull( get_post( $post_id ) );

		unset( $request['request_id'] );
		$request['request_ref'] = '123e4567-e89b-42d3-a456-426614173002';
		$this->assertSame( 'deleted', $this->subject->delete_page_v2( $request )['state'] );

		$request['page_ref'] = str_repeat( 'd', 64 );
		$this->assertSame( 'request_conflict', $this->subject->delete_page_v2( $request )['code'] );
	}

	public function test_callable_map_exposes_both_exact_actions() {
		$reflection = new \ReflectionClass( MainWP_Child_Callable::class );
		$property   = $reflection->getProperty( 'callableFunctions' );
		$property->setAccessible( true );
		$callables = $property->getValue( MainWP_Child_Callable::get_instance() );

		$this->assertSame( 'termageddon_page_v2_get', $callables['termageddon_page_v2_get'] );
		$this->assertSame( 'termageddon_page_v2_delete', $callables['termageddon_page_v2_delete'] );
	}

	private function create_page( $content, $post_type = 'page', $post_status = 'publish' ) {
		return self::factory()->post->create(
			array(
				'post_type'    => $post_type,
				'post_status'  => $post_status,
				'post_content' => $content,
			)
		);
	}

	private function get_request( $post_id, $content ) {
		return array(
			'contract_version'   => '2',
			'post_id'            => $post_id,
			'page_ref'           => $this->page_ref,
			'page_type'          => 'privacy',
			'site_generation'    => $this->site_generation,
			'content_generation' => hash( 'sha256', $content ),
			'expected_post_type' => 'page',
			'expected_status'    => 'publish',
		);
	}
}
