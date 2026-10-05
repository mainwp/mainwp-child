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
	 * Serialized objects must not be instantiated or retained.
	 */
	public function test_object_payload_is_rejected_without_instantiation() {
		$payload = serialize( new MainWP_Clone_Unserialize_Canary() ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions -- Hostile test fixture.
		$clone   = new MainWP_Clone_Install();

		$result = $clone->recursive_unserialize_replace( 'http://old.example', 'http://new.example', $payload );

		$this->assertFalse( MainWP_Clone_Unserialize_Canary::$woke );
		$this->assertSame( '', $result );
	}

	/**
	 * Objects nested inside serialized arrays must also be rejected.
	 */
	public function test_nested_object_payload_is_rejected_without_instantiation() {
		$payload = serialize( // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions -- Hostile test fixture.
			array( 'canary' => new MainWP_Clone_Unserialize_Canary() )
		);
		$clone = new MainWP_Clone_Install();

		$result = $clone->recursive_unserialize_replace( 'http://old.example', 'http://new.example', $payload );

		$this->assertFalse( MainWP_Clone_Unserialize_Canary::$woke );
		$this->assertSame( '', $result );
	}

	/**
	 * Enum payloads must be rejected before PHP can invoke an autoloader.
	 */
	public function test_nested_enum_payload_is_rejected_before_autoload() {
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

			$this->assertSame( '', $clone->recursive_unserialize_replace( 'old', 'new', $payload ) );
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
	 * Cyclic serialized arrays must not recurse without a bound.
	 */
	public function test_cyclic_array_payload_is_rejected() {
		$value    = array();
		$value[0] = &$value;
		$payload  = serialize( $value ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions -- Cyclic test fixture.
		$clone    = new MainWP_Clone_Install();

		$this->assertSame( '', $clone->recursive_unserialize_replace( 'old', 'new', $payload ) );
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
