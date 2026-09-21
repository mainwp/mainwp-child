<?php
/**
 * Custom post type v2 publication tests.
 *
 * @package Mainwp_Child
 */

use MainWP\Child\MainWP_Custom_Post_Type;

if ( ! function_exists( 'wc_product_has_unique_sku' ) ) {
	/**
	 * Provide the WooCommerce uniqueness boundary for the isolated fixture.
	 *
	 * @return bool
	 */
	function wc_product_has_unique_sku() {
		return true;
	}
}

/**
 * Custom post type v2 contract tests.
 */
class Test_Custom_Post_Type_Import_V2 extends WP_UnitTestCase {

	/**
	 * Register the disposable post type.
	 */
	public function setUp(): void {
		parent::setUp();
		register_post_type( 'codex_cpt_v2', array( 'public' => false ) );
		register_post_type( 'product_variation', array( 'public' => false ) );
		register_taxonomy( 'codex_genre', 'codex_cpt_v2' );
	}

	/**
	 * Remove disposable posts and the post type.
	 */
	public function tearDown(): void {
		$posts = get_posts(
			array(
				'post_type'      => array( 'codex_cpt_v2', 'product_variation' ),
				'post_status'    => 'any',
				'posts_per_page' => -1,
				'fields'         => 'ids',
			)
		);
		foreach ( $posts as $post_id ) {
			wp_delete_post( $post_id, true );
		}
		unregister_taxonomy( 'codex_genre' );
		unregister_post_type( 'product_variation' );
		unregister_post_type( 'codex_cpt_v2' );
		$_POST = array();
		parent::tearDown();
	}

	/**
	 * Return a valid bounded v2 payload.
	 *
	 * @return array
	 */
	private function payload() {
		$now = '2026-08-13 12:00:00';
		return array(
			'post'               => array(
				'post_date'             => $now,
				'post_date_gmt'         => $now,
				'post_content'          => 'v2 body',
				'post_title'            => 'v2 title',
				'post_excerpt'          => '',
				'post_status'           => 'draft',
				'comment_status'        => 'closed',
				'ping_status'           => 'closed',
				'post_password'         => '',
				'post_name'             => 'v2-title',
				'to_ping'               => '',
				'pinged'                => '',
				'post_modified'         => $now,
				'post_modified_gmt'     => $now,
				'post_content_filtered' => '',
				'menu_order'            => 0,
				'post_type'             => 'codex_cpt_v2',
			),
			'postmeta'           => array(),
			'terms'              => array(),
			'extras'             => array(
				'upload_dir'  => array( 'baseurl' => 'https://dashboard.example.test/uploads' ),
				'woocommerce' => array(),
			),
			'categories'         => array(),
			'post_only_existing' => 0,
		);
	}

	/**
	 * Invoke the private v2 request handler without exiting the request.
	 *
	 * @param array $payload Payload.
	 * @param int   $post_id Optional edit target.
	 * @return array
	 */
	private function import( $payload, $post_id = 0 ) {
		$_POST = array( 'data' => wp_json_encode( $payload ) );
		if ( $post_id > 0 ) {
			$_POST['post_id'] = (string) $post_id;
		}
		$method = new ReflectionMethod( MainWP_Custom_Post_Type::class, 'import_custom_post_v2' );
		$method->setAccessible( true );
		return $method->invoke( MainWP_Custom_Post_Type::instance() );
	}

	/**
	 * Invalid v2 input is rejected before a post exists.
	 */
	public function test_invalid_payload_is_rejected_before_mutation() {
		$payload               = $this->payload();
		$payload['categories'] = array( 'not-allowed-in-v2' );

		$result = $this->import( $payload );

		$this->assertSame( array( 'outcome' => 'rejected', 'reason' => 'invalid_payload' ), $result );
		$this->assertSame( 0, (int) wp_count_posts( 'codex_cpt_v2' )->draft );
	}

	/**
	 * A valid request confirms the main row and a closed complete result.
	 */
	public function test_valid_payload_creates_confirmed_post() {
		$result = $this->import( $this->payload() );

		$this->assertSame( 'complete', $result['outcome'] );
		$this->assertSame( 'complete', $result['content_status'] );
		$this->assertSame( 'none', $result['partial_stage'] );
		$this->assertSame( 'created', $result['mode'] );
		$this->assertSame( array( 'total' => 0, 'succeeded' => 0, 'failed' => 0 ), $result['variations'] );
		$this->assertGreaterThan( 0, $result['post_id'] );
		$this->assertSame( 'v2 title', get_post( $result['post_id'] )->post_title );
	}

	/**
	 * A stale edit target is recreated and truthfully classified in one request.
	 */
	public function test_missing_edit_target_is_recreated() {
		$result = $this->import( $this->payload(), 999999 );

		$this->assertSame( 'complete', $result['outcome'] );
		$this->assertSame( 'recreated_after_stale_mapping', $result['mode'] );
		$this->assertGreaterThan( 0, $result['post_id'] );
	}

	/**
	 * Every variation is validated before the main post mutation begins.
	 */
	public function test_invalid_variation_is_rejected_before_main_post_mutation() {
		$payload                      = $this->payload();
		$variation                    = $this->payload();
		$variation                    = array_intersect_key( $variation, array_flip( array( 'post', 'postmeta', 'extras' ) ) );
		$variation['post']['post_type'] = 'product_variation';
		$variation['postmeta']          = array( array( 'meta_value' => 'value' ) );
		$payload['product_variation']    = array( 77 => $variation );

		$result = $this->import( $payload );

		$this->assertSame( array( 'outcome' => 'rejected', 'reason' => 'invalid_payload' ), $result );
		$this->assertSame( 0, (int) wp_count_posts( 'codex_cpt_v2' )->draft );
	}

	/**
	 * A variation may only carry a registered non-builtin post type.
	 */
	public function test_variation_post_type_is_gated_before_any_mutation() {
		$payload                        = $this->payload();
		$variation                      = $this->payload();
		$variation                      = array_intersect_key( $variation, array_flip( array( 'post', 'postmeta', 'extras' ) ) );
		$variation['post']['post_type'] = 'post';
		$payload['product_variation']   = array( 77 => $variation );

		$result = $this->import( $payload );

		$this->assertSame( array( 'outcome' => 'rejected', 'reason' => 'unsupported_post_type' ), $result );
		$this->assertSame( 0, (int) wp_count_posts( 'codex_cpt_v2' )->draft );
		$this->assertCount(
			0,
			get_posts(
				array(
					'post_type'   => 'post',
					'post_status' => 'any',
					'name'        => 'v2-title',
					'fields'      => 'ids',
				)
			)
		);
	}

	/**
	 * A fatal after the first write may not claim the child was left untouched.
	 */
	public function test_fatal_after_the_first_write_is_not_reported_as_rejected() {
		$outcome = new ReflectionMethod( MainWP_Custom_Post_Type::class, 'v2_fatal_outcome' );
		$outcome->setAccessible( true );

		$payload               = $this->payload();
		$payload['categories'] = array( 'not-allowed-in-v2' );
		$this->assertSame( 'rejected', $this->import( $payload )['outcome'] );
		$this->assertSame( array( 'outcome' => 'rejected', 'reason' => 'child_failure' ), $outcome->invoke( null ) );

		$this->assertSame( 'complete', $this->import( $this->payload() )['outcome'] );
		$this->assertSame( array( 'outcome' => 'unknown', 'reason' => 'child_failure' ), $outcome->invoke( null ) );
	}

	/**
	 * JSON object member order does not change the closed contract.
	 */
	public function test_reordered_meta_members_remain_valid() {
		$payload             = $this->payload();
		$payload['postmeta'] = array( array( 'meta_value' => 'value', 'meta_key' => 'fixture_key' ) );

		$result = $this->import( $payload );

		$this->assertSame( 'complete', $result['outcome'] );
		$this->assertSame( 'value', get_post_meta( $result['post_id'], 'fixture_key', true ) );
	}

	/**
	 * A null meta value is accepted, and validating it emits no deprecation.
	 */
	public function test_null_meta_value_is_accepted_without_a_deprecation() {
		$payload             = $this->payload();
		$payload['postmeta'] = array( array( 'meta_key' => 'fixture_null', 'meta_value' => null ) );

		$deprecations = array();
		set_error_handler(
			static function ( $number, $message ) use ( &$deprecations ) {
				unset( $number );
				if ( false === strpos( $message, 'strlen' ) ) {
					return false;
				}
				$deprecations[] = $message;
				return true;
			},
			E_DEPRECATED
		);
		try {
			$result = $this->import( $payload );
		} finally {
			restore_error_handler();
		}

		$this->assertSame( array(), $deprecations );
		$this->assertSame( 'complete', $result['outcome'] );
	}

	/**
	 * A closed nonempty taxonomy row is created and assigned.
	 */
	public function test_nonempty_terms_are_confirmed() {
		$payload          = $this->payload();
		$payload['terms'] = array(
			array(
				'name'        => 'Science Fiction',
				'slug'        => 'science-fiction',
				'taxonomy'    => 'codex_genre',
				'description' => 'Fixture term.',
				'parent'      => '0',
				'term_group'  => '0',
				'term_order'  => '0',
			),
		);

		$result = $this->import( $payload );

		$this->assertSame( 'complete', $result['outcome'] );
		$this->assertSame( array( 'science-fiction' ), wp_get_object_terms( $result['post_id'], 'codex_genre', array( 'fields' => 'slugs' ) ) );
	}

	/**
	 * Every valid variation is attempted and reflected in the aggregate.
	 */
	public function test_valid_variations_are_aggregated() {
		$payload                        = $this->payload();
		$variation                      = $this->payload();
		$variation                      = array_intersect_key( $variation, array_flip( array( 'post', 'postmeta', 'extras' ) ) );
		$variation['post']['post_type'] = 'product_variation';
		$payload['product_variation']   = array( 77 => $variation );

		$result = $this->import( $payload );

		$this->assertSame( 'complete', $result['outcome'] );
		$this->assertSame( array( 'total' => 1, 'succeeded' => 1, 'failed' => 0 ), $result['variations'] );
		$variations = get_posts(
			array(
				'post_type'      => 'product_variation',
				'post_status'    => 'any',
				'post_parent'    => $result['post_id'],
				'posts_per_page' => -1,
			)
		);
		$this->assertCount( 1, $variations );
	}

	/**
	 * A failed featured-image download is reported after the main row exists.
	 */
	public function test_featured_image_failure_is_partial_content_media() {
		$payload                                = $this->payload();
		$payload['postmeta']                    = array( array( 'meta_key' => '_thumbnail_id', 'meta_value' => '44' ) );
		$payload['extras']['featured_image']    = 'https://dashboard.example.test/uploads/missing.jpg';
		$fail_download = static function () {
			return new WP_Error( 'fixture_download_failed', 'Sensitive provider detail.' );
		};
		add_filter( 'pre_http_request', $fail_download, 10, 3 );
		$result = $this->import( $payload );
		remove_filter( 'pre_http_request', $fail_download, 10 );

		$this->assertSame( 'partial', $result['outcome'] );
		$this->assertSame( 'partial', $result['content_status'] );
		$this->assertSame( 'content_media', $result['partial_stage'] );
		$this->assertGreaterThan( 0, $result['post_id'] );
		$this->assertSame( 0, get_post_thumbnail_id( $result['post_id'] ) );
	}

	/**
	 * A failed variation media stage is retained in the aggregate result.
	 */
	public function test_variation_media_failure_is_aggregated() {
		$payload                                                = $this->payload();
		$variation                                              = $this->payload();
		$variation                                              = array_intersect_key( $variation, array_flip( array( 'post', 'postmeta', 'extras' ) ) );
		$variation['post']['post_type']                         = 'product_variation';
		$variation['postmeta']                                  = array( array( 'meta_key' => '_product_image_gallery', 'meta_value' => '44' ) );
		$variation['extras']['woocommerce']['product_images']   = array( 'https://dashboard.example.test/uploads/missing-gallery.jpg' );
		$payload['product_variation']                           = array( 77 => $variation );
		$fail_download = static function () {
			return new WP_Error( 'fixture_download_failed', 'Sensitive provider detail.' );
		};
		add_filter( 'pre_http_request', $fail_download, 10, 3 );
		$result = $this->import( $payload );
		remove_filter( 'pre_http_request', $fail_download, 10 );

		$this->assertSame( 'partial', $result['outcome'] );
		$this->assertSame( 'variations', $result['partial_stage'] );
		$this->assertSame( array( 'total' => 1, 'succeeded' => 0, 'failed' => 1 ), $result['variations'] );
	}
}
