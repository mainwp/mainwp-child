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
	 * Cyclic arrays need the same refusal as objects because decoding preserves references.
	 */
	public function test_clone_value_contains_object_rejects_self_referencing_array() {
		$a      = array();
		$a[0]   = &$a;
		$target = new MainWP_Clone_Install();
		$method = new ReflectionMethod( MainWP_Clone_Install::class, 'value_contains_object' );
		$method->setAccessible( true );

		$visits = 0;
		$this->assertTrue( $method->invokeArgs( $target, array( $a, 0, &$visits ) ) );
		$visits = 0;
		$this->assertTrue( $method->invokeArgs( $target, array( unserialize( serialize( $a ), array( 'allowed_classes' => false ) ), 0, &$visits ) ) );
		$this->assertSame( serialize( $a ), $target->recursive_unserialize_replace( 'old', 'new', serialize( $a ) ), 'a cyclic clone-source value must be returned as the original bytes' );
	}

	/**
	 * Ordinary nested arrays must remain eligible for import.
	 */
	public function test_clone_value_contains_object_accepts_five_deep_array() {
		$value = 'plain';
		for ( $depth = 0; $depth < 5; ++$depth ) {
			$value = array( $value );
		}
		$target = new MainWP_Clone_Install();
		$method = new ReflectionMethod( MainWP_Clone_Install::class, 'value_contains_object' );
		$method->setAccessible( true );

		$visits = 0;
		$this->assertFalse( $method->invokeArgs( $target, array( $value, 0, &$visits ) ) );
		$this->assertSame( serialize( $value ), $target->recursive_unserialize_replace( 'old', 'new', serialize( $value ) ) );
	}

	/**
	 * Cyclic arrays need the same refusal as objects because decoding preserves references.
	 */
	public function test_custom_post_type_value_contains_object_rejects_self_referencing_array() {
		$a      = array();
		$a[0]   = &$a;
		$target = MainWP_Custom_Post_Type::instance();
		$method = new ReflectionMethod( MainWP_Custom_Post_Type::class, 'value_contains_object' );
		$method->setAccessible( true );

		$this->assertTrue( $method->invoke( $target, $a ) );
		$this->assertTrue( $method->invoke( $target, unserialize( serialize( $a ), array( 'allowed_classes' => false ) ) ) );
	}

	/**
	 * Ordinary nested arrays must remain eligible for import.
	 */
	public function test_custom_post_type_value_contains_object_accepts_five_deep_array() {
		$value = 'plain';
		for ( $depth = 0; $depth < 5; ++$depth ) {
			$value = array( $value );
		}
		$target = MainWP_Custom_Post_Type::instance();
		$method = new ReflectionMethod( MainWP_Custom_Post_Type::class, 'value_contains_object' );
		$method->setAccessible( true );

		$this->assertFalse( $method->invoke( $target, $value ) );
	}

	public function test_custom_post_type_rejects_shared_reference_graph_quickly() {
		$levels = array( array( 'plain' ) );
		for ( $depth = 1; $depth <= 30; ++$depth ) {
			$levels[ $depth ] = array();
			$levels[ $depth ][0] = &$levels[ $depth - 1 ];
			$levels[ $depth ][1] = &$levels[ $depth - 1 ];
		}
		$payload = serialize( $levels[30] );
		$this->assertStringContainsString( 'R:', $payload );
		$this->assertLessThan( 4096, strlen( $payload ) );
		$script = 'define("ABSPATH", "/"); require ' . var_export( dirname( __DIR__ ) . '/class/class-mainwp-custom-post-type.php', true ) . ';'
			. '$class = new ReflectionClass("MainWP\\\\Child\\\\MainWP_Custom_Post_Type");'
			. '$target = $class->newInstanceWithoutConstructor();'
			. '$method = $class->getMethod("value_contains_object"); $method->setAccessible(true);'
			. '$value = unserialize(base64_decode(' . var_export( base64_encode( $payload ), true ) . '), array("allowed_classes" => false, "max_depth" => 32));'
			. 'echo $method->invoke($target, $value) ? "unsafe" : "safe";';
		// Bound the failing run too: an unbudgeted walk expands billions of visits.
		$process = proc_open( array( PHP_BINARY, '-r', $script ), array( 0 => array( 'pipe', 'r' ), 1 => array( 'pipe', 'w' ), 2 => array( 'pipe', 'w' ) ), $pipes );
		$this->assertIsResource( $process );
		fclose( $pipes[0] );
		$deadline = microtime( true ) + 2;
		try {
			do {
				$status = proc_get_status( $process );
				if ( ! $status['running'] ) {
					break;
				}
				usleep( 10000 );
			} while ( microtime( true ) < $deadline );
			$this->assertFalse( $status['running'], 'Shared-reference traversal exceeded 2 seconds.' );
			$this->assertSame( '', stream_get_contents( $pipes[2] ) );
			$this->assertSame( 'unsafe', stream_get_contents( $pipes[1] ), 'A 30-level shared subtree must exhaust the visit budget.' );
		} finally {
			if ( $status['running'] ) {
				proc_terminate( $process, 9 );
			}
			fclose( $pipes[1] );
			fclose( $pipes[2] );
			proc_close( $process );
		}
	}

	public function test_custom_post_type_shared_graph_within_budget_is_safe() {
		$levels = array( array( 'plain' ) );
		for ( $depth = 1; $depth <= 8; ++$depth ) {
			$levels[ $depth ] = array();
			$levels[ $depth ][0] = &$levels[ $depth - 1 ];
			$levels[ $depth ][1] = &$levels[ $depth - 1 ];
		}
		$value = unserialize( serialize( $levels[8] ), array( 'allowed_classes' => false ) );
		$method = new ReflectionMethod( MainWP_Custom_Post_Type::class, 'value_contains_object' );
		$method->setAccessible( true );
		$this->assertFalse( $method->invoke( MainWP_Custom_Post_Type::instance(), $value ) );
	}

	public function test_insert_postmeta_refuses_shared_reference_graph() {
		$levels = array( array( 'plain' ) );
		for ( $depth = 1; $depth <= 18; ++$depth ) {
			$levels[ $depth ] = array();
			$levels[ $depth ][0] = &$levels[ $depth - 1 ];
			$levels[ $depth ][1] = &$levels[ $depth - 1 ];
		}
		$post_id = self::factory()->post->create();
		$data = array( 'postmeta' => array( array( 'meta_key' => 'mainwp_shared_meta', 'meta_value' => serialize( $levels[18] ) ) ) );
		$method = new ReflectionMethod( MainWP_Custom_Post_Type::class, 'insert_postmeta' );
		$method->setAccessible( true );
		$this->assertTrue( $method->invokeArgs( MainWP_Custom_Post_Type::instance(), array( $post_id, $data, false, false ) ) );
		$this->assertFalse( metadata_exists( 'post', $post_id, 'mainwp_shared_meta' ), 'An over-budget graph must not reach WordPress meta storage.' );
	}

	public function test_insert_postmeta_refuses_over_depth_array() {
		$value = 'plain';
		for ( $depth = 0; $depth < 34; ++$depth ) {
			$value = array( $value );
		}
		$post_id = self::factory()->post->create();
		$data = array( 'postmeta' => array( array( 'meta_key' => 'mainwp_deep_meta', 'meta_value' => serialize( $value ) ) ) );
		$method = new ReflectionMethod( MainWP_Custom_Post_Type::class, 'insert_postmeta' );
		$method->setAccessible( true );
		$this->assertTrue( $method->invokeArgs( MainWP_Custom_Post_Type::instance(), array( $post_id, $data, false, false ) ) );
		$this->assertFalse( metadata_exists( 'post', $post_id, 'mainwp_deep_meta' ) );
	}

	public function test_insert_postmeta_preserves_serialized_false() {
		$post_id = self::factory()->post->create();
		$data = array( 'postmeta' => array( array( 'meta_key' => 'mainwp_false_meta', 'meta_value' => 'b:0;' ) ) );
		$method = new ReflectionMethod( MainWP_Custom_Post_Type::class, 'insert_postmeta' );
		$method->setAccessible( true );
		$this->assertTrue( $method->invokeArgs( MainWP_Custom_Post_Type::instance(), array( $post_id, $data, false, false ) ) );
		$this->assertTrue( metadata_exists( 'post', $post_id, 'mainwp_false_meta' ) );
		$this->assertSame( '', get_post_meta( $post_id, 'mainwp_false_meta', true ) );
	}

	/**
	 * A serialized object string fed to the legacy clone replacer must not
	 * instantiate the class, and the original bytes must come back untouched.
	 * The cell is never decoded and re-serialized, so no __PHP_Incomplete_Class
	 * round trip can rewrite the payload.
	 *
	 * @return void
	 */
	public function test_clone_replacer_preserves_object_payload() {
		$payload = serialize( new MainWP_Unserialize_Canary() ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions -- building a hostile fixture.

		MainWP_Unserialize_Canary::$woke = false;
		$clone                           = new MainWP_Clone_Install();
		$out                             = $clone->recursive_unserialize_replace( 'http://old.example', 'http://new.example', $payload );

		$this->assertFalse( MainWP_Unserialize_Canary::$woke, 'clone replacer instantiated a live object via unserialize()' );
		$this->assertSame( $payload, $out, 'a clone-source object payload must be returned as the original bytes' );
	}

	/**
	 * The same protection must hold when the object is nested inside a
	 * serialized array (the common shape of wp_options / postmeta rows): the
	 * whole cell is returned as the original bytes, with no replacement applied.
	 *
	 * @return void
	 */
	public function test_clone_replacer_preserves_object_nested_in_array() {
		$payload = serialize( array( 'canary' => new MainWP_Unserialize_Canary() ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions -- building a hostile fixture.

		MainWP_Unserialize_Canary::$woke = false;
		$clone                           = new MainWP_Clone_Install();
		$out                             = $clone->recursive_unserialize_replace( 'http://old.example', 'http://new.example', $payload );

		$this->assertFalse( MainWP_Unserialize_Canary::$woke, 'nested object was instantiated via unserialize()' );
		$this->assertSame( $payload, $out, 'a nested object payload must be returned as the original bytes' );
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
			foreach ( $this->find_bare_unserialize_lines( implode( '', $lines ) ) as $number ) {
				$offenders[] = $file->getFilename() . ':' . $number . ' -> ' . trim( $lines[ $number - 1 ] );
			}
		}

		$this->assertSame( array(), $offenders, "bare unserialize() found in child class surface:\n" . implode( "\n", $offenders ) );
	}

	/**
	 * A serialized array that references itself (an R: back-reference) unserializes
	 * to a cyclic array. The object walk must refuse it instead of recursing until
	 * memory or time runs out, and the cell must not be persisted.
	 *
	 * @return void
	 */
	public function test_insert_postmeta_refuses_cyclic_serialized_array() {
		$post_id = self::factory()->post->create();
		$data    = array(
			'postmeta' => array(
				array(
					'meta_key'   => 'mainwp_cyclic_meta',
					'meta_value' => 'a:1:{i:0;R:1;}',
				),
			),
		);

		$cpt    = MainWP_Custom_Post_Type::instance();
		$method = new ReflectionMethod( MainWP_Custom_Post_Type::class, 'insert_postmeta' );
		$method->setAccessible( true );
		$result = $method->invokeArgs( $cpt, array( $post_id, $data, false, false ) );

		$this->assertArrayNotHasKey( 'error', (array) $result );
		$this->assertSame( array(), get_post_meta( $post_id, 'mainwp_cyclic_meta' ), 'A cyclic serialized array must not be persisted.' );
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
			'class-mainwp-child-back-up-buddy.php' => 1, // BackupBuddy's own scan result; not dashboard-fed.
		);

		ksort( $actual );
		ksort( $allowed );

		$this->assertSame( $allowed, $actual, 'unreviewed maybe_unserialize() in child class surface — harden it like insert_postmeta() (dashboard-fed) or add the file to the reviewed allowlist with a rationale' );
	}
	/**
	 * Report the line of every unserialize() call whose own options argument is
	 * not an array literal with plain keys ending up with 'allowed_classes' => false.
	 *
	 * Runs on PHP tokens so comments and string contents never count as code, and
	 * judges each call on its own arguments: a restricted outer call cannot vouch
	 * for an unrestricted call nested inside it, and a marker in the first
	 * argument or a neighboring call does not count.
	 *
	 * @param string $source PHP source, with or without the opening tag.
	 *
	 * @return int[] Line numbers of unrestricted calls.
	 */
	private function find_bare_unserialize_lines( $source ) {
		if ( 0 !== strpos( ltrim( $source ), '<?php' ) ) {
			// No newline after the tag, so line numbers match the fragment's own.
			$source = '<?php ' . $source;
		}

		$tokens = array();
		$line   = 1;
		foreach ( token_get_all( $source ) as $token ) {
			$type = is_array( $token ) ? $token[0] : null;
			$text = is_array( $token ) ? $token[1] : $token;
			if ( is_array( $token ) ) {
				$line = $token[2];
			}
			if ( ! in_array( $type, array( T_WHITESPACE, T_COMMENT, T_DOC_COMMENT, T_OPEN_TAG, T_INLINE_HTML ), true ) ) {
				$tokens[] = array( $type, $text, $line );
			}
			$line += substr_count( $text, "\n" );
		}

		// PHP 8 reads namespace\unserialize and Foo\unserialize as single name tokens; PHP 7.4
		// splits them, leaving a plain T_STRING. Any name ending in unserialize counts, so
		// both versions flag the same calls.
		$names = array( T_STRING );
		foreach ( array( 'T_NAME_FULLY_QUALIFIED', 'T_NAME_RELATIVE', 'T_NAME_QUALIFIED' ) as $name_token ) {
			if ( defined( $name_token ) ) {
				$names[] = constant( $name_token );
			}
		}
		$not_a_call = array( T_OBJECT_OPERATOR, T_DOUBLE_COLON, T_FUNCTION, T_NEW, T_CONST );
		if ( defined( 'T_NULLSAFE_OBJECT_OPERATOR' ) ) {
			$not_a_call[] = T_NULLSAFE_OBJECT_OPERATOR;
		}

		// Keep aliases file-wide so a namespace boundary cannot hide an unsafe call.
		$aliases = array();
		foreach ( $tokens as $index => $token ) {
			if ( T_USE !== $token[0] || ! isset( $tokens[ $index + 1 ] ) || '(' === $tokens[ $index + 1 ][1] ) {
				continue;
			}
			$function_import = T_FUNCTION === $tokens[ $index + 1 ][0];
			$prefix = '';
			$imports = array( array() );
			for ( $i = $index + ( $function_import ? 2 : 1 ); isset( $tokens[ $i ] ) && ';' !== $tokens[ $i ][1]; ++$i ) {
				$text = $tokens[ $i ][1];
				if ( '{' === $text ) {
					foreach ( $imports[0] as $part ) { $prefix .= $part[1]; }
					$imports = array( array() );
				} elseif ( ',' === $text ) {
					$imports[] = array();
				} elseif ( '}' !== $text ) {
					$imports[ count( $imports ) - 1 ][] = $tokens[ $i ];
				}
			}
			foreach ( $imports as $parts ) {
				$target = $prefix;
				$is_function = $function_import;
				foreach ( $parts as $position => $part ) {
					if ( T_FUNCTION === $part[0] ) {
						$is_function = true;
						continue;
					}
					if ( T_AS === $part[0] ) {
						if ( $is_function && 'unserialize' === strtolower( substr( (string) strrchr( '\\' . $target, '\\' ), 1 ) ) && 2 === count( $parts ) - $position ) {
							$aliases[] = strtolower( $parts[ $position + 1 ][1] );
						}
						break;
					}
					$target .= $part[1];
				}
			}
		}

		$found = array();
		foreach ( $tokens as $index => $token ) {
			$is_alias = T_STRING === $token[0] && in_array( strtolower( $token[1] ), $aliases, true )
				&& ( 0 === $index || T_NS_SEPARATOR !== $tokens[ $index - 1 ][0] );
			if ( ! $is_alias && ( ! in_array( $token[0], $names, true ) || 'unserialize' !== strtolower( substr( (string) strrchr( '\\' . $token[1], '\\' ), 1 ) ) ) ) {
				continue;
			}
			if ( ! isset( $tokens[ $index + 1 ] ) || '(' !== $tokens[ $index + 1 ][1] ) {
				continue;
			}
			if ( $index > 0 && in_array( $tokens[ $index - 1 ][0], $not_a_call, true ) ) {
				continue;
			}
			if ( ! $this->unserialize_call_is_restricted( $tokens, $index + 1 ) ) {
				$found[] = $token[2];
			}
		}
		return array_values( array_unique( $found ) );
	}

	/**
	 * Whether the call opening at $open passes an array literal as its second
	 * argument, every key in it is a plain literal, and its last allowed_classes
	 * key is set to false.
	 *
	 * @param array $tokens Significant tokens as array( type, text, line ).
	 * @param int   $open   Index of the call's opening parenthesis.
	 *
	 * @return bool
	 */
	private function unserialize_call_is_restricted( $tokens, $open ) {
		$call = $this->split_bracketed( $tokens, $open );
		if ( null === $call || ! isset( $call[0][1] ) ) {
			return false;
		}

		$options = $call[0][1];
		$start   = ( isset( $options[0] ) && T_ARRAY === $options[0][0] ) ? 1 : 0;
		$literal = $this->split_bracketed( $options, $start );
		// Anything after the literal's closing bracket (a "+ $more" union) means the options are not this literal.
		if ( null === $literal || count( $options ) - 1 !== $literal[1] ) {
			return false;
		}

		// Every key must be a literal whose value is visible in the source. A computed,
		// variable, escaped or interpolated key, a spread, or a bare value could resolve
		// to allowed_classes and overwrite the restriction, so any of them refuses the call.
		$allowed = null;
		foreach ( $literal[0] as $element ) {
			if ( array() === $element ) {
				continue;
			}
			if ( ! isset( $element[1] ) || T_DOUBLE_ARROW !== $element[1][0] || ! $this->is_plain_literal_key( $element[0] ) ) {
				return false;
			}
			if ( 'allowed_classes' === substr( $element[0][1], 1, -1 ) ) {
				// A repeated key overwrites the earlier one, so only the last occurrence decides.
				$allowed = 3 === count( $element ) && 'false' === strtolower( $element[2][1] );
			}
		}
		return true === $allowed;
	}

	/**
	 * Whether a token is an array key whose value the source spells out: an integer,
	 * or a quoted string with no escape sequence or interpolation.
	 *
	 * @param array $token Token as array( type, text, line ).
	 *
	 * @return bool
	 */
	private function is_plain_literal_key( $token ) {
		if ( T_LNUMBER === $token[0] ) {
			return true;
		}
		return T_CONSTANT_ENCAPSED_STRING === $token[0] && false === strpbrk( $token[1], '\\$' );
	}

	/**
	 * Split a bracketed token run at its top-level commas.
	 *
	 * @param array $tokens Significant tokens as array( type, text, line ).
	 * @param int   $start  Index of the opening ( or [.
	 *
	 * @return array|null array( parts, closing index ), or null when $start is not an opener or the brackets never close.
	 */
	private function split_bracketed( $tokens, $start ) {
		if ( ! isset( $tokens[ $start ] ) || ! in_array( $tokens[ $start ][1], array( '(', '[' ), true ) ) {
			return null;
		}

		$parts = array( array() );
		$depth = 0;
		$count = count( $tokens );
		for ( $i = $start; $i < $count; ++$i ) {
			$text = $tokens[ $i ][1];
			if ( in_array( $text, array( '(', '[', '{', '${' ), true ) ) {
				++$depth;
				if ( 1 === $depth ) {
					continue;
				}
			} elseif ( in_array( $text, array( ')', ']', '}' ), true ) ) {
				--$depth;
				if ( 0 === $depth ) {
					return array( $parts, $i );
				}
			} elseif ( 1 === $depth && ',' === $text ) {
				$parts[] = array();
				continue;
			}
			$parts[ count( $parts ) - 1 ][] = $tokens[ $i ];
		}
		return null;
	}

	public static function namespaced_import_alias_cases() {
		$cases = array();
		$imports = array(
			'qualified' => 'use function Foo\\Unserialize as x;',
			'function group' => 'use function Foo\\{strlen, unserialize as x};',
			'mixed group' => 'use Foo\\{Bar, function unserialize as x, const VALUE};',
		);
		foreach ( $imports as $name => $import ) {
			$cases[ $name . ' missing options' ] = array( $import . "\n" . 'x( $v );', array( 2 ) );
			$cases[ $name . ' allowed classes' ] = array( $import . "\n" . 'x( $v, array( "allowed_classes" => true ) );', array( 2 ) );
			$cases[ $name . ' restricted' ] = array( $import . "\n" . 'x( $v, array( "allowed_classes" => false ) );', array() );
		}
		return $cases;
	}

	/** @dataProvider namespaced_import_alias_cases */
	public function test_namespaced_unserialize_import_aliases( $source, $expected ) {
		$this->assertSame( $expected, $this->find_bare_unserialize_lines( $source ) );
	}

	public function test_every_class_unserialize_restriction_is_detected_when_flipped() {
		$flipped = 0;
		$files = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( dirname( __DIR__ ) . '/class', FilesystemIterator::SKIP_DOTS ) );
		foreach ( $files as $file ) {
			if ( 'php' !== strtolower( $file->getExtension() ) ) { continue; }
			$source = file_get_contents( $file->getPathname() );
			preg_match_all( '/([\'"])allowed_classes\1\s*=>\s*false\b/i', $source, $matches, PREG_OFFSET_CAPTURE );
			foreach ( $matches[0] as $match ) {
				$line = 1 + substr_count( substr( $source, 0, $match[1] ), "\n" );
				$unsafe = substr_replace( $source, preg_replace( '/false$/i', 'true', $match[0] ), $match[1], strlen( $match[0] ) );
				$this->assertNotEmpty( $this->find_bare_unserialize_lines( $unsafe ), $file->getFilename() . ':' . $line );
				++$flipped;
			}
		}
		$this->assertGreaterThan( 0, $flipped, 'The mutation probe must exercise real restrictions.' );
	}

	/**
	 * Every shape the bare-unserialize guard must flag or accept, with the
	 * exact lines it must report.
	 *
	 * @return void
	 */
	public function test_bare_unserialize_guard_matrix() {
		$cases = array(
			'bare call beside an is_serialized() wrapper'      => array( 'if ( is_serialized( $v ) ) { $v = unserialize( $v ); }', array( 1 ) ),
			'bare call beside maybe_unserialize()'             => array( '$a = maybe_unserialize( $v ); $b = unserialize( $v );', array( 1 ) ),
			'bare call beside a restricted call'               => array( '$a = unserialize( $v, array( "allowed_classes" => false ) ); $b = unserialize( $v );', array( 1 ) ),
			'marker only in a trailing comment'                => array( '$a = unserialize( $v ); // allowed_classes', array( 1 ) ),
			'maybe_unserialize() alone'                        => array( '$a = maybe_unserialize( $v );', array() ),
			'restricted call with a nested first argument'     => array( '$a = unserialize( trim( $v ), array( "allowed_classes" => false ) );', array() ),
			'restricted call across lines'                     => array( "\$a = @unserialize(\n\t\$v,\n\tarray(\n\t\t'allowed_classes' => false,\n\t)\n);", array() ),
			'options without allowed_classes across lines'     => array( "\$a = @unserialize(\n\t\$v,\n\tarray(\n\t\t'max_depth' => 4,\n\t)\n);", array( 1 ) ),
			'bare call after a multiline restricted call'      => array( "\$a = @unserialize(\n\t\$v,\n\tarray( 'allowed_classes' => false )\n);\n\$b = unserialize( \$v );", array( 5 ) ),
			'bare call nested inside a restricted call'        => array( "\$a = unserialize(\n    unserialize( \$v ),\n    array( \"allowed_classes\" => false )\n);", array( 2 ) ),
			'marker inside the first argument'                 => array( "\$a = unserialize(\n    get_value( array( \"allowed_classes\" => false ) )\n);", array( 1 ) ),
			'marker inside a comment within the call'          => array( "\$a = unserialize(\n    \$v /* \"allowed_classes\" => false */\n);", array( 1 ) ),
			'paren in a string reaching a neighboring call'    => array( "\$a = unserialize(\n    \$v . \"(\"\n);\n\$b = foo( array( \"allowed_classes\" => false ), \" )\" );", array( 1 ) ),
			'wrapper split across lines keeps line numbers'    => array( "is_serialized\n( \$v );\n\$b = unserialize( \$v );", array( 3 ) ),
			'options passed as a variable'                     => array( '$a = unserialize( $v, $opts );', array( 1 ) ),
			'allowed_classes true'                             => array( '$a = unserialize( $v, array( "allowed_classes" => true ) );', array( 1 ) ),
			'allowed_classes as a class list'                  => array( '$a = unserialize( $v, array( "allowed_classes" => array( "Foo" ) ) );', array( 1 ) ),
			'marker nested below the options array'            => array( '$a = unserialize( $v, array( "x" => array( "allowed_classes" => false ) ) );', array( 1 ) ),
			'options literal unioned with more options'        => array( '$a = unserialize( $v, array( "allowed_classes" => false ) + $more );', array( 1 ) ),
			'later duplicate key re-allows classes'            => array( '$a = unserialize( $v, array( "allowed_classes" => false, "allowed_classes" => true ) );', array( 1 ) ),
			'empty second argument'                            => array( '$a = unserialize( $v, );', array( 1 ) ),
			'short array with another key first'               => array( '$a = unserialize( $v, [ "max_depth" => 4, "allowed_classes" => false ] );', array() ),
			'uppercase function name and FALSE'                => array( '$a = UNSERIALIZE( $v, array( "allowed_classes" => FALSE ) );', array() ),
			'uppercase bare call'                              => array( '$a = UNSERIALIZE( $v );', array( 1 ) ),
			'fully qualified bare call'                        => array( '$a = \unserialize( $v );', array( 1 ) ),
			'silenced bare call'                               => array( '$a = @unserialize( $v );', array( 1 ) ),
			'method call, static call and method definition'   => array( '$o->unserialize( $v ); Foo::unserialize( $v ); function unserialize( $data ) {}', array() ),
			'call text inside a string literal'                => array( '$s = \'unserialize( $v )\';', array() ),
			'call text inside a docblock'                      => array( "/**\n * unserialize( \$v )\n */\n\$a = 1;", array() ),
			'full file with an opening tag'                    => array( "<?php\n\n\$a = unserialize( \$v );", array( 3 ) ),
			'computed key overwrites the restriction'          => array( '$a = unserialize( $v, [ "allowed_classes" => false, "allowed_" . "classes" => true ] );', array( 1 ) ),
			'variable key overwrites the restriction'          => array( '$a = unserialize( $v, [ "allowed_classes" => false, $key => true ] );', array( 1 ) ),
			'escaped key overwrites the restriction'           => array( '$a = unserialize( $v, [ "allowed_classes" => false, "allowed_\\x63lasses" => true ] );', array( 1 ) ),
			'interpolated key overwrites the restriction'      => array( '$a = unserialize( $v, [ "allowed_classes" => false, "{$key}" => true ] );', array( 1 ) ),
			'spread overwrites the restriction'                => array( '$a = unserialize( $v, [ "allowed_classes" => false, ...$more ] );', array( 1 ) ),
			'variable key before the final false'              => array( '$a = unserialize( $v, [ $key => true, "allowed_classes" => false ] );', array( 1 ) ),
			'value without a key'                              => array( '$a = unserialize( $v, [ "allowed_classes" => false, $more ] );', array( 1 ) ),
			'integer key beside the restriction'               => array( '$a = unserialize( $v, array( 0 => 1, \'allowed_classes\' => false ) );', array() ),
			'namespace-relative bare call'                     => array( 'namespace\\unserialize( $v );', array( 1 ) ),
			'namespace-qualified bare call'                    => array( 'Foo\\unserialize( $v );', array( 1 ) ),
			'namespace-relative restricted call'               => array( 'namespace\\unserialize( $v, array( \'allowed_classes\' => false ) );', array() ),
			'bare call through a fully qualified import alias' => array( "use function \\unserialize as decode;\n\$a = decode( \$v );", array( 2 ) ),
			'bare call through an unqualified import alias'    => array( "use function unserialize as decode;\n\$a = decode( \$v );", array( 2 ) ),
			'import alias with a restricted second argument'   => array( "use function \\unserialize as decode;\n\$a = decode( \$v, array( 'allowed_classes' => false ) );", array() ),
			'import alias beside a restricted direct call'     => array( "use function \\unserialize as decode;\n\$a = unserialize( \$v, array( 'allowed_classes' => false ) );\n\$b = decode( \$v );", array( 3 ) ),
			'import alias in a comma-separated list'           => array( "use function strlen, \\unserialize as decode;\n\$a = decode( \$v );", array( 2 ) ),
			'import alias called in another case'              => array( "use function \\unserialize as decode;\n\$a = DECODE( \$v );", array( 2 ) ),
			'import alias of another function'                 => array( "use function \\strlen as decode;\n\$a = decode( \$v );", array() ),
			'decode without an unserialize import'             => array( '$a = decode( $v );', array() ),
			'alias name as a method and a qualified call'      => array( "use function \\unserialize as decode;\n\$o->decode( \$v ); Foo\\decode( \$v );", array() ),
		);

		foreach ( array_merge( $cases, self::namespaced_import_alias_cases() ) as $name => $case ) {
			$this->assertSame( $case[1], $this->find_bare_unserialize_lines( $case[0] ), $name );
		}
	}

}
