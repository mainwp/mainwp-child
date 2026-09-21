<?php
/**
 * Typed post extraction protocol tests.
 *
 * @package MainWP_Child
 */

namespace MainWP\Child;

use WP_UnitTestCase;

class Test_MainWP_Child_Posts_V2 extends WP_UnitTestCase {

	/** @var int */
	private $author;

	public function set_up(): void {
		parent::set_up();
		$this->author = self::factory()->user->create(
			array(
				'display_name' => 'Fixture Editor',
			)
		);
		self::factory()->post->create(
			array(
				'post_author' => $this->author,
				'post_title'  => 'Alpha public',
				'post_status' => 'publish',
				'post_type'   => 'post',
				'post_date'   => '2026-01-10 12:00:00',
			)
		);
		self::factory()->post->create(
			array(
				'post_author' => $this->author,
				'post_title'  => 'Beta public',
				'post_status' => 'publish',
				'post_type'   => 'page',
				'post_date'   => '2026-01-11 12:00:00',
			)
		);
		self::factory()->post->create(
			array(
				'post_author' => $this->author,
				'post_title'  => 'Private fixture',
				'post_status' => 'private',
				'post_type'   => 'post',
				'post_date'   => '2026-01-12 12:00:00',
			)
		);
	}

	public function test_paginated_records_are_typed_and_cursor_bound() {
		$first = MainWP_Child_Posts::get_instance()->get_all_posts_v2( $this->request( 1 ) );

		$this->assertTrue( $first['ok'] );
		$this->assertFalse( $first['complete'] );
		$this->assertCount( 1, $first['records'] );
		$this->assertMatchesRegularExpression( '/^[a-f0-9]{64}$/D', $first['query_generation'] );
		$this->assertIsString( $first['next_cursor'] );
		$this->assertSame( array( 'post_id', 'title', 'url', 'date', 'status', 'author' ), array_keys( $first['records'][0] ) );

		$request                 = $this->request( 1 );
		$request['query']['cursor'] = $first['next_cursor'];
		$second                  = MainWP_Child_Posts::get_instance()->get_all_posts_v2( $request );

		$this->assertTrue( $second['ok'] );
		$this->assertTrue( $second['complete'] );
		$this->assertCount( 1, $second['records'] );
		$this->assertSame( $first['query_generation'], $second['query_generation'] );
		$this->assertNotSame( $first['records'][0]['post_id'], $second['records'][0]['post_id'] );
	}

	public function test_private_status_is_returned_only_when_explicitly_requested() {
		$request                      = $this->request( 10 );
		$request['query']['post_types'] = array( 'post' );
		$request['query']['statuses']   = array( 'private' );
		$result                       = MainWP_Child_Posts::get_instance()->get_all_posts_v2( $request );

		$this->assertTrue( $result['ok'] );
		$this->assertCount( 1, $result['records'] );
		$this->assertSame( 'private', $result['records'][0]['status'] );
	}

	public function test_malformed_query_and_tampered_cursor_fail_closed() {
		$this->assertFalse( MainWP_Child_Posts::get_instance()->get_all_posts_v2( array() )['ok'] );

		$request                          = $this->request( 10 );
		$request['query']['statuses'][]   = 'inherit';
		$this->assertFalse( MainWP_Child_Posts::get_instance()->get_all_posts_v2( $request )['ok'] );

		$request                            = $this->request( 10 );
		$request['query']['cursor']         = '1.' . str_repeat( 'a', 43 );
		$this->assertFalse( MainWP_Child_Posts::get_instance()->get_all_posts_v2( $request )['ok'] );
	}

	public function test_secret_query_parameters_are_removed_from_urls() {
		$filter = static function ( $url ) {
			return add_query_arg(
				array(
					'page'     => '1',
					'_wpnonce' => 'secret',
					'token'    => 'private',
				),
				$url
			);
		};
		add_filter( 'post_link', $filter );
		$request                      = $this->request( 10 );
		$request['query']['post_types'] = array( 'post' );
		$result                       = MainWP_Child_Posts::get_instance()->get_all_posts_v2( $request );
		remove_filter( 'post_link', $filter );

		$this->assertTrue( $result['ok'] );
		$this->assertStringContainsString( 'page=1', $result['records'][0]['url'] );
		$this->assertStringNotContainsString( '_wpnonce', $result['records'][0]['url'] );
		$this->assertStringNotContainsString( 'token', $result['records'][0]['url'] );
	}

	private function request( $page_size ) {
		return array(
			'protocol'  => '2',
			'operation' => 'get_all_posts_v2',
			'query'     => array(
				'post_types' => array( 'post', 'page' ),
				'statuses'   => array( 'publish' ),
				'keyword'    => null,
				'date_from'  => '2026-01-01',
				'date_to'    => '2026-12-31',
				'post_id'    => null,
				'author_id'  => null,
				'page_size'  => $page_size,
				'cursor'     => null,
			),
		);
	}
}
