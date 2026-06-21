<?php
/**
 * Class UnserializeHardeningTest
 *
 * Proves that the serialization-aware search-replace paths do not
 * instantiate live objects when they unserialize untrusted database
 * content. A bare unserialize() would fire __wakeup() on a crafted
 * serialized object (PHP object injection); the hardened calls pass
 * allowed_classes => false so the payload becomes a __PHP_Incomplete_Class
 * stub and no magic method runs.
 *
 * @package Mainwp_Child
 */

use MainWP\Child\MainWP_Clone_Install;
use MainWP\Child\MainWP_Backup_Url_Rewriter;

/**
 * Canary whose __wakeup() trips a static flag. If the flag is set after a
 * value passes through a replacer, a live object was instantiated.
 */
class MainWP_Unserialize_Canary {

	/**
	 * Set true by __wakeup when a real instance is reconstructed.
	 *
	 * @var bool
	 */
	public static $woke = false;

	/**
	 * A property carrying the search string, used to confirm the byte
	 * round-trip of an untouched object.
	 *
	 * @var string
	 */
	public $url = 'http://old.example';

	/**
	 * Magic method that fires during a successful unserialize().
	 *
	 * @return void
	 */
	public function __wakeup() {
		self::$woke = true;
	}
}

/**
 * Tests for restricted unserialize on the clone and rewriter replacers.
 */
class UnserializeHardeningTest extends WP_UnitTestCase {

	/**
	 * Reset the canary before each test.
	 *
	 * @return void
	 */
	public function set_up() {
		parent::set_up();
		MainWP_Unserialize_Canary::$woke = false;
	}

	/**
	 * A serialized object string fed to the legacy clone replacer must not
	 * instantiate the class, and the untouched object round-trips unchanged.
	 *
	 * @return void
	 */
	public function test_clone_replacer_does_not_instantiate_object() {
		$payload = serialize( new MainWP_Unserialize_Canary() ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions -- building a hostile fixture.

		MainWP_Unserialize_Canary::$woke = false;
		$clone                           = new MainWP_Clone_Install();
		$out                             = $clone->recursive_unserialize_replace( 'http://old.example', 'http://new.example', $payload );

		$this->assertFalse( MainWP_Unserialize_Canary::$woke, 'clone replacer instantiated a live object via unserialize()' );
		$this->assertSame( $payload, $out, 'untouched serialized object did not round-trip byte-identical' );
	}

	/**
	 * The same protection must hold when the object is nested inside a
	 * serialized array (the common shape of wp_options / postmeta rows).
	 *
	 * @return void
	 */
	public function test_clone_replacer_handles_object_nested_in_array() {
		$payload = serialize( array( 'canary' => new MainWP_Unserialize_Canary() ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions -- building a hostile fixture.

		MainWP_Unserialize_Canary::$woke = false;
		$clone                           = new MainWP_Clone_Install();
		$out                             = $clone->recursive_unserialize_replace( 'http://old.example', 'http://new.example', $payload );

		$this->assertFalse( MainWP_Unserialize_Canary::$woke, 'nested object was instantiated via unserialize()' );
		$this->assertSame( $payload, $out, 'nested untouched object did not round-trip byte-identical' );
	}

	/**
	 * A serialized plain string still has its replacement applied, so the
	 * hardening does not regress the scalar path.
	 *
	 * @return void
	 */
	public function test_clone_replacer_still_replaces_serialized_string() {
		$payload  = serialize( 'visit http://old.example today' ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions -- fixture.
		$expected = serialize( 'visit http://new.example today' ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions -- fixture.

		$clone = new MainWP_Clone_Install();
		$out   = $clone->recursive_unserialize_replace( 'http://old.example', 'http://new.example', $payload );

		$this->assertSame( $expected, $out, 'serialized scalar string was not rewritten' );
	}

	/**
	 * Regression guard: the native backup URL rewriter (already hardened)
	 * must likewise never instantiate the canary.
	 *
	 * @return void
	 */
	public function test_backup_rewriter_does_not_instantiate_object() {
		$payload = serialize( new MainWP_Unserialize_Canary() ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions -- building a hostile fixture.

		MainWP_Unserialize_Canary::$woke = false;
		$rewriter                        = new MainWP_Backup_Url_Rewriter();
		$out                             = $rewriter->recursive_replace( 'http://old.example', 'http://new.example', $payload );

		$this->assertFalse( MainWP_Unserialize_Canary::$woke, 'backup rewriter instantiated a live object via unserialize()' );
		$this->assertSame( $payload, $out, 'untouched serialized object did not round-trip byte-identical' );
	}
}
