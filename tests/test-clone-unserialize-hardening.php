<?php
/**
 * Clone unserialize hardening tests.
 *
 * @package Mainwp_Child
 */

use MainWP\Child\MainWP_Clone_Install;

/**
 * Canary used to detect unsafe object construction during unserialization.
 */
class MainWP_Clone_Unserialize_Canary {

	/**
	 * Whether the canary was instantiated by unserialize().
	 *
	 * @var bool
	 */
	public static $woke = false;

	/**
	 * Fixture value containing the search URL.
	 *
	 * @var string
	 */
	public $url = 'http://old.example';

	/**
	 * Record unsafe object construction.
	 */
	public function __wakeup() {
		self::$woke = true;
	}
}

/**
 * Tests for restricted unserialization in the clone search-replace helper.
 */
class Test_Clone_Unserialize_Hardening extends WP_UnitTestCase {

	/**
	 * Reset the canary before each test.
	 */
	public function setUp(): void {
		parent::setUp();
		MainWP_Clone_Unserialize_Canary::$woke = false;
	}

	/**
	 * Serialized objects must not be instantiated or modified.
	 */
	public function test_object_payload_is_preserved_without_instantiation() {
		$payload = serialize( new MainWP_Clone_Unserialize_Canary() ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions -- Hostile test fixture.
		$clone   = new MainWP_Clone_Install();

		$result = $clone->recursive_unserialize_replace( 'http://old.example', 'http://new.example', $payload );

		$this->assertFalse( MainWP_Clone_Unserialize_Canary::$woke );
		$this->assertSame( $payload, $result );
	}

	/**
	 * Objects nested inside serialized arrays must also remain untouched.
	 */
	public function test_nested_object_payload_is_preserved_without_instantiation() {
		$payload = serialize( // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions -- Hostile test fixture.
			array( 'canary' => new MainWP_Clone_Unserialize_Canary() )
		);
		$clone = new MainWP_Clone_Install();

		$result = $clone->recursive_unserialize_replace( 'http://old.example', 'http://new.example', $payload );

		$this->assertFalse( MainWP_Clone_Unserialize_Canary::$woke );
		$this->assertSame( $payload, $result );
	}

	/**
	 * An unsafe nested serialized value must prevent partial changes to the enclosing cell.
	 */
	public function test_nested_unsafe_value_preserves_entire_serialized_cell() {
		$value = array(
			'url'     => 'http://old.example',
			'payload' => serialize( new MainWP_Clone_Unserialize_Canary() ), // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions -- Hostile test fixture.
		);
		$payload = serialize( $value ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions -- Hostile test fixture.
		$clone   = new MainWP_Clone_Install();

		$result = $clone->recursive_unserialize_replace( 'http://old.example', 'http://new.example', $payload );

		$this->assertFalse( MainWP_Clone_Unserialize_Canary::$woke );
		$this->assertSame( $payload, $result );
	}

	/**
	 * Enum payloads must be preserved before PHP can invoke an autoloader.
	 */
	public function test_nested_enum_payload_is_preserved_before_autoload() {
		$autoloaded = false;
		$autoload   = static function ( $class_name ) use ( &$autoloaded ) {
			if ( 'MainWP_Missing_Enum_Canary' === $class_name ) {
				$autoloaded = true;
			}
		};
		spl_autoload_register( $autoload );

		try {
			$enum_name    = 'MainWP_Missing_Enum_Canary:CASE';
			$enum_payload = 'E:' . strlen( $enum_name ) . ':"' . $enum_name . '";';
			$payload      = 'a:1:{s:4:"enum";' . $enum_payload . '}';
			$clone        = new MainWP_Clone_Install();

			$this->assertSame( $payload, $clone->recursive_unserialize_replace( 'old', 'new', $payload ) );
			$this->assertFalse( $autoloaded );
		} finally {
			spl_autoload_unregister( $autoload );
		}
	}

	/**
	 * Object-like text inside a serialized string must not be a false positive.
	 */
	public function test_object_token_text_inside_string_is_preserved() {
		$value   = array( 'text' => 'Documentation O:4:"Demo":0:{} remains text.' );
		$payload = serialize( $value ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions -- Test fixture.
		$clone   = new MainWP_Clone_Install();

		$this->assertSame( $payload, $clone->recursive_unserialize_replace( 'old', 'new', $payload ) );
	}

	/**
	 * Cyclic serialized arrays must remain untouched without unbounded recursion.
	 */
	public function test_cyclic_array_payload_is_preserved() {
		$value    = array();
		$value[0] = &$value;
		$payload  = serialize( $value ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions -- Cyclic test fixture.
		$clone    = new MainWP_Clone_Install();

		$this->assertSame( $payload, $clone->recursive_unserialize_replace( 'old', 'new', $payload ) );
	}

	/**
	 * Repeated references to the same child must not cause exponential traversal.
	 */
	public function test_shared_reference_graph_over_visit_limit_is_preserved() {
		$levels = array( array( 'http://old.example' ) );
		for ( $depth = 1; $depth <= 18; ++$depth ) {
			$levels[ $depth ]    = array();
			$levels[ $depth ][0] = &$levels[ $depth - 1 ];
			$levels[ $depth ][1] = &$levels[ $depth - 1 ];
		}
		$payload = serialize( $levels[18] ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions -- Shared-reference test fixture.
		$clone   = new MainWP_Clone_Install();

		$this->assertSame( $payload, $clone->recursive_unserialize_replace( 'http://old.example', 'http://new.example', $payload ) );
	}

	/**
	 * Shared-reference graphs within the visit limit must retain normal behavior.
	 */
	public function test_shared_reference_graph_within_visit_limit_is_rewritten() {
		$levels   = array( array( 'http://old.example' ) );
		$expected = array( 'http://new.example' );
		for ( $depth = 1; $depth <= 8; ++$depth ) {
			$levels[ $depth ]    = array();
			$levels[ $depth ][0] = &$levels[ $depth - 1 ];
			$levels[ $depth ][1] = &$levels[ $depth - 1 ];
			$expected            = array( $expected, $expected );
		}
		$payload  = serialize( $levels[8] ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions -- Shared-reference test fixture.
		$expected = serialize( $expected ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions -- Test fixture.
		$clone    = new MainWP_Clone_Install();

		$this->assertSame( $expected, $clone->recursive_unserialize_replace( 'http://old.example', 'http://new.example', $payload ) );
	}

	/**
	 * Over-depth nested serialized values must remain untouched.
	 */
	public function test_over_depth_nested_serialized_values_are_preserved() {
		$payload = 'leaf';
		for ( $depth = 0; $depth < 20; ++$depth ) {
			$payload = serialize( array( $payload ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions -- Deep test fixture.
		}
		$clone = new MainWP_Clone_Install();

		$this->assertSame( $payload, $clone->recursive_unserialize_replace( 'old', 'new', $payload ) );
	}

	/**
	 * Nested serialized values within the depth limit must retain normal behavior.
	 */
	public function test_nested_serialized_values_within_depth_limit_are_rewritten() {
		$payload  = 'http://old.example';
		$expected = 'http://new.example';
		for ( $depth = 0; $depth < 4; ++$depth ) {
			$payload  = serialize( array( $payload ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions -- Nested test fixture.
			$expected = serialize( array( $expected ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions -- Nested test fixture.
		}
		$clone = new MainWP_Clone_Install();

		$this->assertSame( $expected, $clone->recursive_unserialize_replace( 'http://old.example', 'http://new.example', $payload ) );
	}

	/**
	 * Native unserialize depth failures must preserve the value instead of falling back to raw replacement.
	 */
	public function test_deep_serialized_array_is_preserved() {
		$value = 'leaf';
		for ( $depth = 0; $depth < 33; ++$depth ) {
			$value = array( $value );
		}
		$payload = serialize( $value ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions -- Deep test fixture.
		$clone   = new MainWP_Clone_Install();

		$this->assertSame( $payload, $clone->recursive_unserialize_replace( 'old', 'new', $payload ) );
	}

	/**
	 * Data that passes the format check but fails to decode must remain untouched.
	 */
	public function test_malformed_serialized_data_is_preserved() {
		$payload = 'a:1:{i:0;s:99:"old";}';
		$clone   = new MainWP_Clone_Install();

		$this->assertSame( $payload, $clone->recursive_unserialize_replace( 'old', 'new', $payload ) );
	}

	/**
	 * Serialized false must not be confused with an unserialize failure.
	 */
	public function test_serialized_false_is_preserved() {
		$clone = new MainWP_Clone_Install();

		$this->assertSame( 'b:0;', $clone->recursive_unserialize_replace( 'b', 'x', 'b:0;' ) );
	}

	/**
	 * Safe serialized arrays must retain normal search-replace behavior.
	 */
	public function test_serialized_array_is_still_rewritten() {
		$payload  = serialize( array( 'url' => 'http://old.example/page' ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions -- Test fixture.
		$expected = serialize( array( 'url' => 'http://new.example/page' ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions -- Test fixture.
		$clone    = new MainWP_Clone_Install();

		$this->assertSame( $expected, $clone->recursive_unserialize_replace( 'http://old.example', 'http://new.example', $payload ) );
	}

	/**
	 * Safe serialized strings must retain normal search-replace behavior.
	 */
	public function test_serialized_string_is_still_rewritten() {
		$payload  = serialize( 'Visit http://old.example today.' ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions -- Test fixture.
		$expected = serialize( 'Visit http://new.example today.' ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions -- Test fixture.
		$clone    = new MainWP_Clone_Install();

		$this->assertSame( $expected, $clone->recursive_unserialize_replace( 'http://old.example', 'http://new.example', $payload ) );
	}
}
