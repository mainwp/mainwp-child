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
use MainWP\Child\MainWP_Child_Posts;

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

	/**
	 * The dashboard-fed post_custom import loop must not instantiate a live
	 * object when a meta value carries a serialized object. This exercises the
	 * private get_post_edit() path, which unserializes each serialized meta value
	 * before handing it back to the dashboard.
	 *
	 * @return void
	 */
	public function test_post_custom_import_does_not_instantiate_object() {
		global $wpdb;
		$post_id = self::factory()->post->create();

		// Plant a raw serialized object string directly in postmeta, bypassing
		// add_post_meta()'s maybe_serialize() (which double-wraps and would only
		// unserialize back to a string). get_post_custom() returns this raw string
		// untouched, so the single unserialize() inside get_post_edit() is the only
		// thing that could turn it into a live object.
		$wpdb->insert(
			$wpdb->postmeta,
			array(
				'post_id'    => $post_id,
				'meta_key'   => 'mainwp_canary_meta',
				'meta_value' => serialize( new MainWP_Unserialize_Canary() ), // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions -- hostile fixture.
			)
		); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- planting a raw serialized-object meta value for the test.
		wp_cache_delete( $post_id, 'post_meta' );

		MainWP_Unserialize_Canary::$woke = false;

		$posts  = new MainWP_Child_Posts();
		$method = new ReflectionMethod( MainWP_Child_Posts::class, 'get_post_edit' );
		$method->setAccessible( true );
		$method->invoke( $posts, $post_id );

		$this->assertFalse( MainWP_Unserialize_Canary::$woke, 'post_custom import instantiated a live object via unserialize()' );
	}

	/**
	 * Static guard: no bare unserialize() may survive anywhere in the child
	 * class surface. Every call must pass allowed_classes => false. This covers
	 * both post_custom import loops and the PageSpeed cpt_whitelist option, and
	 * catches any future regression the behavioral tests would miss. Mirrors the
	 * handoff verification grep.
	 *
	 * @return void
	 */
	public function test_no_bare_unserialize_remains_in_class_surface() {
		$class_dir = dirname( __DIR__ ) . '/class';
		$this->assertDirectoryExists( $class_dir, 'child class directory not found' );

		$offenders = array();
		$files     = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $class_dir, FilesystemIterator::SKIP_DOTS ) );
		foreach ( $files as $file ) {
			if ( 'php' !== strtolower( $file->getExtension() ) ) {
				continue;
			}
			$lines = file( $file->getPathname() );
			foreach ( $lines as $number => $line ) {
				if ( false === strpos( $line, 'unserialize(' ) ) {
					continue;
				}
				if ( false !== strpos( $line, 'maybe_unserialize' )
					|| false !== strpos( $line, 'is_serialized' )
					|| false !== strpos( $line, 'allowed_classes' ) ) {
					continue;
				}
				$offenders[] = $file->getFilename() . ':' . ( $number + 1 ) . ' -> ' . trim( $line );
			}
		}

		$this->assertSame( array(), $offenders, "bare unserialize() found in child class surface:\n" . implode( "\n", $offenders ) );
	}
}
