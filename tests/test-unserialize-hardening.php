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
use MainWP\Child\MainWP_Custom_Post_Type;

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
	 * instantiate the class, and the object payload must be dropped rather than
	 * re-serialized back into the row. Round-tripping the __PHP_Incomplete_Class
	 * would re-emit the original class string for a later get_option() read to
	 * rehydrate into a gadget, so the whole value is neutralized to empty.
	 *
	 * @return void
	 */
	public function test_clone_replacer_drops_object_payload() {
		$payload = serialize( new MainWP_Unserialize_Canary() ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions -- building a hostile fixture.

		MainWP_Unserialize_Canary::$woke = false;
		$clone                           = new MainWP_Clone_Install();
		$out                             = $clone->recursive_unserialize_replace( 'http://old.example', 'http://new.example', $payload );

		$this->assertFalse( MainWP_Unserialize_Canary::$woke, 'clone replacer instantiated a live object via unserialize()' );
		$this->assertSame( '', $out, 'a clone-source object payload must be dropped, not round-tripped back into the row' );
		$this->assertFalse( is_serialized( $out ), 'the neutralized value must carry no serialized payload a later unserialize() could rehydrate' );
	}

	/**
	 * The same protection must hold when the object is nested inside a
	 * serialized array (the common shape of wp_options / postmeta rows): the
	 * whole cell is dropped so no forged class string survives the rewrite.
	 *
	 * @return void
	 */
	public function test_clone_replacer_drops_object_nested_in_array() {
		$payload = serialize( array( 'canary' => new MainWP_Unserialize_Canary() ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions -- building a hostile fixture.

		MainWP_Unserialize_Canary::$woke = false;
		$clone                           = new MainWP_Clone_Install();
		$out                             = $clone->recursive_unserialize_replace( 'http://old.example', 'http://new.example', $payload );

		$this->assertFalse( MainWP_Unserialize_Canary::$woke, 'nested object was instantiated via unserialize()' );
		$this->assertSame( '', $out, 'a nested object payload must be dropped, not round-tripped back into the row' );
		$this->assertFalse( is_serialized( $out ), 'the neutralized value must carry no serialized payload a later unserialize() could rehydrate' );
	}

	/**
	 * A legitimately serialized array of scalars must still round-trip through
	 * the clone replacer with its replacement applied, so the object-drop guard
	 * does not regress the normal array rewrite path.
	 *
	 * @return void
	 */
	public function test_clone_replacer_still_rewrites_serialized_array() {
		$payload  = serialize( array( 'link' => 'http://old.example/page' ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions -- fixture.
		$expected = serialize( array( 'link' => 'http://new.example/page' ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions -- fixture.

		$clone = new MainWP_Clone_Install();
		$out   = $clone->recursive_unserialize_replace( 'http://old.example', 'http://new.example', $payload );

		$this->assertSame( $expected, $out, 'serialized array of scalars was not rewritten' );
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

	/**
	 * The dashboard-fed custom-post-type import (insert_postmeta) must never
	 * persist a serialized PHP object. A bare maybe_unserialize() instantiates
	 * the object at import time (firing __wakeup); even a restricted unserialize
	 * is not enough on its own, because add_post_meta() re-serializes the
	 * __PHP_Incomplete_Class back to the ORIGINAL class string, which WordPress
	 * core then re-instantiates on the next get_post_meta() read. The fix both
	 * restricts unserialize AND skips storing any object payload, so the canary
	 * is never woken at import and nothing reconstructable is left in the DB.
	 *
	 * @return void
	 */
	public function test_insert_postmeta_does_not_persist_object() {
		$post_id = self::factory()->post->create();
		$data    = array(
			'postmeta' => array(
				array(
					'meta_key'   => 'mainwp_canary_meta',
					'meta_value' => serialize( new MainWP_Unserialize_Canary() ), // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions -- hostile fixture.
				),
			),
		);

		MainWP_Unserialize_Canary::$woke = false;

		$cpt    = MainWP_Custom_Post_Type::instance();
		$method = new ReflectionMethod( MainWP_Custom_Post_Type::class, 'insert_postmeta' );
		$method->setAccessible( true );
		$method->invokeArgs( $cpt, array( $post_id, $data, false, false ) );

		// No live object may be instantiated at import time.
		$this->assertFalse( MainWP_Unserialize_Canary::$woke, 'insert_postmeta instantiated a live object via unserialize() at import time' );

		// And nothing reconstructable may be persisted: reading the meta back
		// (WordPress core runs maybe_unserialize on read) must not yield an object
		// nor wake the canary.
		$stored = get_post_meta( $post_id, 'mainwp_canary_meta', true );
		$this->assertFalse( MainWP_Unserialize_Canary::$woke, 'a persisted object payload was re-instantiated on get_post_meta()' );
		$this->assertNotInstanceOf( MainWP_Unserialize_Canary::class, $stored, 'insert_postmeta persisted a reconstructable object' );
		$this->assertSame( '', $stored, 'object payload should be skipped, leaving no stored meta' );
	}

	/**
	 * Regression: a legitimately serialized array must still round-trip through
	 * insert_postmeta unchanged, so the object-payload guard does not break the
	 * normal pass-through behaviour maybe_unserialize() provided.
	 *
	 * @return void
	 */
	public function test_insert_postmeta_preserves_serialized_array() {
		$post_id  = self::factory()->post->create();
		$expected = array(
			'a' => 1,
			'b' => array( 2, 3 ),
		);
		$data     = array(
			'postmeta' => array(
				array(
					'meta_key'   => 'mainwp_array_meta',
					'meta_value' => serialize( $expected ), // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions -- fixture.
				),
			),
		);

		$cpt    = MainWP_Custom_Post_Type::instance();
		$method = new ReflectionMethod( MainWP_Custom_Post_Type::class, 'insert_postmeta' );
		$method->setAccessible( true );
		$method->invokeArgs( $cpt, array( $post_id, $data, false, false ) );

		$this->assertSame( $expected, get_post_meta( $post_id, 'mainwp_array_meta', true ), 'serialized array did not round-trip through insert_postmeta' );
	}

	/**
	 * A malformed string that still passes is_serialized() (here an array whose
	 * declared element count overshoots its body) must not leak an unserialize()
	 * warning into the response. WordPress core's maybe_unserialize() suppresses
	 * this with @unserialize(); the fix keeps that. Under the suite's
	 * convertWarningsToExceptions an unsuppressed warning would throw here.
	 *
	 * @return void
	 */
	public function test_insert_postmeta_tolerates_malformed_serialized_string() {
		$post_id = self::factory()->post->create();
		$data    = array(
			'postmeta' => array(
				array(
					'meta_key'   => 'mainwp_malformed_meta',
					'meta_value' => 'a:2:{i:0;i:1;}', // passes is_serialized() but unserialize() would warn.
				),
			),
		);

		MainWP_Unserialize_Canary::$woke = false;

		$cpt    = MainWP_Custom_Post_Type::instance();
		$method = new ReflectionMethod( MainWP_Custom_Post_Type::class, 'insert_postmeta' );
		$method->setAccessible( true );
		$result = $method->invokeArgs( $cpt, array( $post_id, $data, false, false ) );

		$this->assertTrue( $result, 'insert_postmeta did not complete on a malformed serialized meta value' );
		$this->assertFalse( MainWP_Unserialize_Canary::$woke, 'malformed payload still instantiated an object' );
	}

	/**
	 * Static guard for the wrapper the bare-unserialize sweep missed.
	 * maybe_unserialize() calls unserialize() WITHOUT allowed_classes, so a
	 * dashboard-fed payload reaching one is PHP object injection. Every site in
	 * the child class surface is counted and matched against a reviewed
	 * allowlist; a NEW maybe_unserialize() (an unreviewed file, or an extra one
	 * in a listed file) fails this test, so the next person hardens it like
	 * insert_postmeta() or reviews it and adds it here with a rationale.
	 *
	 * @return void
	 */
	public function test_no_unreviewed_maybe_unserialize_in_class_surface() {
		$class_dir = dirname( __DIR__ ) . '/class';
		$this->assertDirectoryExists( $class_dir, 'child class directory not found' );

		$actual = array();
		$files  = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $class_dir, FilesystemIterator::SKIP_DOTS ) );
		foreach ( $files as $file ) {
			if ( 'php' !== strtolower( $file->getExtension() ) ) {
				continue;
			}
			$count = substr_count( (string) file_get_contents( $file->getPathname() ), 'maybe_unserialize(' );
			if ( $count > 0 ) {
				$actual[ $file->getFilename() ] = $count;
			}
		}

		$allowed = array(
			'class-mainwp-child.php'               => 1, // local wp_options row; mirrors WP core; not dashboard-fed.
			'class-mainwp-client-report-base.php'  => 1, // local activity-log author_meta; not dashboard-fed.
			'class-mainwp-child-plugins-check.php' => 1, // third-party update-API body; needs the live object; external trust boundary.
			'class-mainwp-child-themes-check.php'  => 1, // third-party update-API body; needs the live object; external trust boundary.
			'class-mainwp-child-back-up-buddy.php' => 1, // BackupBuddy's own scan result; not dashboard-fed.
		);

		ksort( $actual );
		ksort( $allowed );

		$this->assertSame( $allowed, $actual, 'unreviewed maybe_unserialize() in child class surface — harden it like insert_postmeta() (dashboard-fed) or add the file to the reviewed allowlist with a rationale' );
	}
}
