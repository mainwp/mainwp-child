<?php

// phpcs:disable Generic.Files.OneObjectStructurePerFile.MultipleFound -- Focused protocol fixture and test case intentionally share one file.
/**
 * Code Snippets Child protocol-v2 tests.
 *
 * @package Mainwp_Child
 */

use MainWP\Child\MainWP_Child_Misc;

/** Deterministic Code Snippets v2 fixture. */
class Test_MainWP_Child_Code_Snippets_V2_Fixture extends MainWP_Child_Misc {

	/** @var array<string,mixed> */
	public $options = array();

	/** @var bool */
	public $lock_busy = false;

	/** @var bool */
	public $write_fails = false;

	/** @var bool */
	public $release_fails = false;

	/** @var string|false */
	public $config_path = false;

	/** @var array<int,string|false> Every staging attempt, recorded without altering the real result. */
	public $staged = array();

	/** @var string|null Lock file probed while a replacement is in flight. */
	public $probe_lock_path = null;

	/** @var bool|null Whether the probed lock was free mid-write; null when it was never probed. */
	public $lock_free_during_write = null;

	/** @var int|false|null Inode the probed lock had mid-write. */
	public $lock_inode_during_write = null;

	/** @return mixed */
	protected function snippet_v2_get_option( $name, $fallback = false ) {
		return array_key_exists( $name, $this->options ) ? $this->options[ $name ] : $fallback;
	}

	/** @return bool */
	protected function snippet_v2_update_option( $name, $value ) {
		if ( $this->write_fails ) {
			return false;
		}
		$this->options[ $name ] = $value;
		return true;
	}

	/** @return string|false */
	protected function snippet_v2_acquire_lock( $slug ) {
		return $this->lock_busy ? false : 'fixture-owner';
	}

	/** @return bool */
	protected function snippet_v2_release_lock( $slug, $owner ) {
		return ! $this->release_fails && 'fixture-owner' === $owner;
	}

	/** @return string|false */
	protected function snippet_v2_config_path() {
		return $this->config_path;
	}

	/** @return bool */
	protected function snippet_v2_write_file_atomic( $path, $expected, $next ) {
		return ! $this->write_fails && parent::snippet_v2_write_file_atomic( $path, $expected, $next );
	}

	/** @return string|false */
	protected function snippet_v2_stage_file( $directory, $prefix, $contents, $permissions ) {
		$this->probe_configuration_lock();
		$staged         = parent::snippet_v2_stage_file( $directory, $prefix, $contents, $permissions );
		$this->staged[] = $staged;
		return $staged;
	}

	/**
	 * Try to take the configuration lock from an independent handle while a replacement is in
	 * flight. flock() conflicts between separate open file descriptions, so a lock that is really
	 * held refuses this even inside the same process.
	 *
	 * @return void
	 */
	private function probe_configuration_lock() {
		if ( ! is_string( $this->probe_lock_path ) || ! file_exists( $this->probe_lock_path ) ) {
			return;
		}
		$this->lock_inode_during_write = fileinode( $this->probe_lock_path );
		$probe                         = fopen( $this->probe_lock_path, 'c' ); // phpcs:ignore WordPress.WP.AlternativeFunctions -- Probing an advisory lock needs a real handle.
		if ( false === $probe ) {
			return;
		}
		$this->lock_free_during_write = flock( $probe, LOCK_EX | LOCK_NB );
		if ( $this->lock_free_during_write ) {
			flock( $probe, LOCK_UN );
		}
		fclose( $probe ); // phpcs:ignore WordPress.WP.AlternativeFunctions -- Pairs with the fopen above.
	}
}

/** Marker for the wire fixture, distinct from anything PHPUnit itself throws. */
class Test_MainWP_Child_Code_Snippets_V2_Wire_Probe extends Exception {}

/** Captures what the wire guard handed the protocol. */
class Test_MainWP_Child_Code_Snippets_V2_Wire_Fixture extends MainWP_Child_Misc {

	/** @var mixed Request as the guard decoded it; null when the guard refused the body. */
	public $seen = false;

	/**
	 * @param string $action  Protocol action.
	 * @param mixed  $request Decoded request.
	 * @return array<string,mixed>
	 * @throws Test_MainWP_Child_Code_Snippets_V2_Wire_Probe Always, before the response is written.
	 */
	public function snippet_v2( $action, $request ) {
		$this->seen = $request;
		// code_snippet() hands this return value to MainWP_Helper::write(), which ends the request
		// with die(). Throwing from the argument expression stops short of that.
		throw new Test_MainWP_Child_Code_Snippets_V2_Wire_Probe( 'wire-guard' );
	}
}

/** Code Snippets protocol-v2 contract tests. */
/** Exposes the real option writer, which every other fixture in this file replaces. */
class Test_MainWP_Child_Code_Snippets_V2_Option_Probe extends MainWP_Child_Misc {

	/** @return bool */
	public function store( $name, $value ) {
		return $this->snippet_v2_update_option( $name, $value );
	}
}

class Test_MainWP_Child_Code_Snippets_V2 extends WP_UnitTestCase {

	/**
	 * Name of the lock file the configuration writer is expected to hold, spelled out here rather
	 * than read back from the subject so a writer that stops locking fails instead of adapting.
	 */
	const LOCK_FILE = '.mainwp-snippets-config-lock.php';

	/** @var string */
	private $request_ref = '123e4567-e89b-42d3-a456-426614174000';

	/** @var Test_MainWP_Child_Code_Snippets_V2_Fixture */
	private $fixture;

	/** @var string */
	private $config_path;

	/** @var string|null */
	private $config_dir = null;

	/** Set up one isolated fixture. */
	public function setUp(): void {
		parent::setUp();
		$this->fixture     = new Test_MainWP_Child_Code_Snippets_V2_Fixture();
		$this->config_path = tempnam( sys_get_temp_dir(), 'mainwp-cs-v2-' );
		file_put_contents( $this->config_path, "<?php\n\$table_prefix = 'wp_';\n/* collateral */\n" );
		$this->fixture->config_path = $this->config_path;
	}

	/** Remove the temporary configuration fixture. */
	public function tearDown(): void {
		$shared_lock = rtrim( sys_get_temp_dir(), '/' ) . '/' . self::LOCK_FILE;
		if ( file_exists( $shared_lock ) ) {
			unlink( $shared_lock );
		}
		if ( is_string( $this->config_path ) && file_exists( $this->config_path ) ) {
			unlink( $this->config_path );
		}
		if ( is_string( $this->config_dir ) && is_dir( $this->config_dir ) ) {
			chmod( $this->config_dir, 0755 );
			// GLOB_BRACE is not defined on every libc, and an undefined constant is a fatal in PHP 8.
			$leftovers = array_merge(
				(array) glob( $this->config_dir . '/*' ),
				(array) glob( $this->config_dir . '/.*' )
			);
			foreach ( $leftovers as $leftover ) {
				if ( is_file( $leftover ) ) {
					unlink( $leftover );
				}
			}
			rmdir( $this->config_dir );
		}
		parent::tearDown();
	}

	/**
	 * Point the fixture at a wp-config.php that owns its directory, so directory permissions and
	 * staging leftovers can be observed without touching unrelated temporary files.
	 *
	 * @param string $contents Configuration bytes.
	 * @return string Path to the configuration file.
	 */
	private function isolated_config( $contents ) {
		$this->config_dir = $this->config_path . '-dir';
		mkdir( $this->config_dir, 0755 );
		$path = $this->config_dir . '/wp-config.php';
		file_put_contents( $path, $contents );
		chmod( $path, 0644 );
		$this->fixture->config_path     = $path;
		$this->fixture->probe_lock_path = $this->config_dir . '/' . self::LOCK_FILE;
		return $path;
	}

	/** @return array<string,mixed> */
	private function request( $type = 'S', $code = "echo 'ok';" ) {
		return array(
			'protocol_version' => 2,
			'request_ref'      => $this->request_ref,
			'slug'             => 'FixtureSlug1',
			'type'             => $type,
			'code'             => $code,
		);
	}

	/** Closed requests reject legacy UUID names and unknown fields. */
	public function test_rejects_nonclosed_and_legacy_request_id_inputs() {
		$request               = $this->request();
		$request['request_id'] = $request['request_ref'];
		unset( $request['request_ref'] );
		$this->assertSame( 'invalid_request', $this->fixture->snippet_v2( 'apply_snippet_v2', $request )['error_code'] );

		$request          = $this->request();
		$request['extra'] = true;
		$this->assertSame( 'invalid_request', $this->fixture->snippet_v2( 'apply_snippet_v2', $request )['error_code'] );
	}

	/** Run-once output is bounded and failures expose no throwable detail. */
	public function test_run_once_is_bounded_and_redacted_on_failure() {
		$result = $this->fixture->snippet_v2( 'run_snippet_v2', $this->request( 'R', "echo 'safe-output';" ) );
		$this->assertSame( 'succeeded', $result['status'] );
		$this->assertSame( 'safe-output', $result['output'] );
		$this->assertFalse( $result['output_truncated'] );
		$this->assertSame( $this->request_ref, $result['request_ref'] );

		$result = $this->fixture->snippet_v2( 'run_snippet_v2', $this->request( 'R', "throw new Exception('PRIVATE-DETAIL');" ) );
		$this->assertSame( 'failed', $result['status'] );
		$this->assertSame( '', $result['output'] );
		$this->assertStringNotContainsString( 'PRIVATE-DETAIL', wp_json_encode( $result ) );

		$result = $this->fixture->snippet_v2( 'run_snippet_v2', $this->request( 'R', "echo str_repeat('x', 70000);" ) );
		$this->assertTrue( $result['output_truncated'] );
		$this->assertSame( 65535, strlen( $result['output'] ) );
	}

	/**
	 * A run that printed and then threw has its output withheld, not lost: the reply must not claim
	 * an empty output is the complete one. A run that threw before printing anything has nothing
	 * withheld, so the same field stays false.
	 */
	public function test_output_withheld_from_a_failed_run_is_reported_as_truncated() {
		$result = $this->fixture->snippet_v2( 'run_snippet_v2', $this->request( 'R', "echo 'emitted-before-failure'; throw new Exception('PRIVATE-DETAIL');" ) );
		$this->assertSame( 'failed', $result['status'] );
		$this->assertSame( '', $result['output'], 'A thrown run still ships no output.' );
		$this->assertTrue( $result['output_truncated'], 'Output was produced and dropped; saying otherwise is the dishonest answer.' );
		$this->assertStringNotContainsString( 'PRIVATE-DETAIL', wp_json_encode( $result ) );

		$result = $this->fixture->snippet_v2( 'run_snippet_v2', $this->request( 'R', "throw new Exception('PRIVATE-DETAIL');" ) );
		$this->assertSame( 'failed', $result['status'] );
		$this->assertSame( '', $result['output'] );
		$this->assertFalse( $result['output_truncated'], 'Nothing was printed, so nothing was withheld.' );
	}

	/** Drop an option's cached copy so the next read comes from the column, as a fresh request would. */
	private function flush_option_cache( $name ) {
		wp_cache_delete( $name, 'options' );
		wp_cache_delete( 'alloptions', 'options' );
		wp_cache_delete( 'notoptions', 'options' );
	}

	/** An option already holding the requested value has been stored, not failed to store. */
	public function test_rewriting_an_option_with_its_current_value_is_not_a_storage_failure() {
		$probe = new Test_MainWP_Child_Code_Snippets_V2_Option_Probe();
		delete_option( 'mainwp_ext_snippets_enabled' );

		$this->assertTrue( $probe->store( 'mainwp_ext_snippets_enabled', true ), 'The first write creates the option.' );

		// Every apply arrives in its own request, so the value comes back from the column rather than
		// from the cache update_option() just primed with the PHP value. Dropping the cached copy is
		// what makes this the second apply instead of a continuation of the first.
		$this->flush_option_cache( 'mainwp_ext_snippets_enabled' );
		$this->assertSame( '1', get_option( 'mainwp_ext_snippets_enabled' ), 'A stored true reads back as text.' );
		$this->assertTrue( $probe->store( 'mainwp_ext_snippets_enabled', true ), 'An already-enabled flag is stored, so writing it again is not a failure.' );

		$this->assertTrue( $probe->store( 'mainwp_ext_code_snippets', array( 'Slug' => "echo 'ok';" ) ) );
		$this->flush_option_cache( 'mainwp_ext_code_snippets' );
		$this->assertTrue( $probe->store( 'mainwp_ext_code_snippets', array( 'Slug' => "echo 'ok';" ) ), 'An unchanged array value is stored too.' );

		delete_option( 'mainwp_ext_snippets_enabled' );
		$this->flush_option_cache( 'mainwp_ext_snippets_enabled' );
		$this->assertFalse( $probe->store( 'mainwp_ext_snippets_enabled', false ), 'An absent option does not count as holding false.' );
	}

	/** A snippet that leaves its own buffer open must not leak text past the encoded reply. */
	public function test_a_snippet_that_leaves_a_buffer_open_still_reports_all_of_its_output() {
		$entry  = ob_get_level();
		$result = $this->fixture->snippet_v2( 'run_snippet_v2', $this->request( 'R', "echo 'outer'; ob_start(); echo 'inner';" ) );

		$this->assertSame( 'succeeded', $result['status'] );
		$this->assertSame( 'outerinner', $result['output'], 'Text held in a buffer the snippet never closed is still its output.' );
		$this->assertSame( $entry, ob_get_level(), 'A run must hand back the buffer nesting it was given.' );
	}

	/** Truncating multibyte output keeps the text it produced instead of blanking the reply. */
	public function test_truncated_multibyte_output_survives_the_byte_cap() {
		$full   = str_repeat( 'é', 40000 );
		$result = $this->fixture->snippet_v2( 'run_snippet_v2', $this->request( 'R', "echo str_repeat('é', 40000);" ) );

		$this->assertSame( 80000, strlen( $full ), 'The run must produce more than the byte cap.' );
		$this->assertSame( 'succeeded', $result['status'] );
		$this->assertTrue( $result['output_truncated'] );
		$this->assertNotSame( '', $result['output'], 'A cap that lands mid-character must not cost the whole output.' );
		$this->assertLessThanOrEqual( 65535, strlen( $result['output'] ) );
		$this->assertGreaterThan( 65530, strlen( $result['output'] ), 'At most one character is given up to the character boundary.' );
		$this->assertSame( 1, preg_match( '//u', $result['output'] ), 'Truncated output must still be valid UTF-8.' );
		$this->assertStringStartsWith( $result['output'], $full );
	}

	/**
	 * Output carrying an invalid byte reports the text around it rather than nothing, and says so:
	 * the run is far short of the byte cap, so only the strip can account for the missing byte.
	 */
	public function test_invalid_bytes_in_output_do_not_discard_the_valid_text() {
		$result = $this->fixture->snippet_v2( 'run_snippet_v2', $this->request( 'R', 'echo "before" . chr( 0xC3 ) . "after";' ) );

		$this->assertSame( 'succeeded', $result['status'] );
		$this->assertLessThan( 65535, strlen( $result['output'] ), 'The cap cannot be what shortened this output.' );
		$this->assertTrue( $result['output_truncated'], 'Dropping bytes and reporting a complete output is the dishonest answer.' );
		$this->assertStringContainsString( 'before', $result['output'] );
		$this->assertStringContainsString( 'after', $result['output'] );
		$this->assertSame( 1, preg_match( '//u', $result['output'] ), 'What is reported must be valid UTF-8.' );
	}

	/**
	 * The Dashboard advertises a 60000-byte code field and encodes it with default JSON flags, so a
	 * maximal non-ASCII snippet reaches the Child six bytes per source byte. The wire bound has to
	 * admit a body the Dashboard is allowed to send.
	 */
	public function test_wire_bound_admits_a_maximal_non_ascii_snippet() {
		$code = str_repeat( 'é', 30000 );
		$this->assertSame( 60000, strlen( $code ), 'This is exactly the largest code the protocol accepts.' );

		$body = wp_json_encode(
			array(
				'protocol_version' => 2,
				'request_ref'      => $this->request_ref,
				'slug'             => 'FixtureSlug1',
				'type'             => 'S',
				'code'             => $code,
			)
		);
		$this->assertGreaterThan( 70000, strlen( $body ), 'Escaped non-ASCII is what the old bound refused.' );

		$wire    = new Test_MainWP_Child_Code_Snippets_V2_Wire_Fixture();
		$stopped = false;
		try {
			$_POST['action'] = 'apply_snippet_v2';
			// WordPress hands $_POST to the request already slashed.
			$_POST['request'] = wp_slash( $body );
			$wire->code_snippet();
		} catch ( Test_MainWP_Child_Code_Snippets_V2_Wire_Probe $probe ) {
			$stopped = true;
		} finally {
			unset( $_POST['action'], $_POST['request'] );
		}

		$this->assertTrue( $stopped, 'The request must reach the protocol.' );
		$this->assertIsArray( $wire->seen, 'A refused body arrives as null, which the protocol can only answer with invalid_request.' );
		$this->assertSame( $code, $wire->seen['code'] );
	}

	/** Stored-option application and removal converge with exact readback. */
	public function test_saved_snippet_apply_and_remove_converge() {
		$result = $this->fixture->snippet_v2( 'apply_snippet_v2', $this->request() );
		$this->assertSame( 'changed', $result['result'] );
		$this->assertSame( "echo 'ok';", $this->fixture->options['mainwp_ext_code_snippets']['FixtureSlug1'] );
		$this->assertTrue( $this->fixture->options['mainwp_ext_snippets_enabled'] );

		$result = $this->fixture->snippet_v2( 'apply_snippet_v2', $this->request() );
		$this->assertSame( 'unchanged', $result['result'] );

		$remove = $this->request();
		unset( $remove['code'] );
		$result = $this->fixture->snippet_v2( 'remove_snippet_v2', $remove );
		$this->assertSame( 'removed', $result['result'] );
		$this->assertArrayNotHasKey( 'FixtureSlug1', $this->fixture->options['mainwp_ext_code_snippets'] );
		$this->assertSame( 'already_absent', $this->fixture->snippet_v2( 'remove_snippet_v2', $remove )['result'] );
	}

	/** Lock and storage failures are stable and do not expose code. */
	public function test_lock_and_storage_failures_are_stable() {
		$this->fixture->lock_busy = true;
		$this->assertSame( 'lock_busy', $this->fixture->snippet_v2( 'apply_snippet_v2', $this->request() )['error_code'] );

		$this->fixture->lock_busy   = false;
		$this->fixture->write_fails = true;
		$result                     = $this->fixture->snippet_v2( 'apply_snippet_v2', $this->request( 'S', "echo 'PRIVATE-CODE';" ) );
		$this->assertSame( 'storage_failed', $result['error_code'] );
		$this->assertStringNotContainsString( 'PRIVATE-CODE', wp_json_encode( $result ) );
	}

	/** wp-config application/removal is exact and preserves collateral bytes. */
	public function test_config_apply_remove_and_duplicate_marker_failure() {
		$original = file_get_contents( $this->config_path );
		$result   = $this->fixture->snippet_v2( 'apply_snippet_v2', $this->request( 'C', "define( 'FIXTURE_VALUE', true );" ) );
		$this->assertSame( 'changed', $result['result'] );
		$applied = file_get_contents( $this->config_path );
		$this->assertStringContainsString( '/***snippet_FixtureSlug1***/', $applied );
		$this->assertStringContainsString( '/* collateral */', $applied );

		$this->assertSame( 'unchanged', $this->fixture->snippet_v2( 'apply_snippet_v2', $this->request( 'C', "define( 'FIXTURE_VALUE', true );" ) )['result'] );

		$remove = $this->request( 'C' );
		unset( $remove['code'] );
		$this->assertSame( 'removed', $this->fixture->snippet_v2( 'remove_snippet_v2', $remove )['result'] );
		$this->assertStringNotContainsString( 'FIXTURE_VALUE', file_get_contents( $this->config_path ) );
		$this->assertStringContainsString( '/* collateral */', file_get_contents( $this->config_path ) );
		$this->assertSame( 'already_absent', $this->fixture->snippet_v2( 'remove_snippet_v2', $remove )['result'] );

		file_put_contents( $this->config_path, $original . "/***snippet_FixtureSlug1***/x/***end_FixtureSlug1***/\n/***snippet_FixtureSlug1***/y/***end_FixtureSlug1***/\n" );
		$this->assertSame( 'storage_failed', $this->fixture->snippet_v2( 'remove_snippet_v2', $remove )['error_code'] );
	}

	/** A configuration write that cannot land leaves the live file untouched and stages nothing outside its directory. */
	public function test_unwritable_config_directory_leaves_original_intact_and_stages_nothing() {
		$path     = $this->isolated_config( "<?php\ndefine( 'DB_PASSWORD', 'FIXTURE-SECRET' );\n\$table_prefix = 'wp_';\n" );
		$original = file_get_contents( $path );
		$inode    = fileinode( $path );
		$fallback = rtrim( sys_get_temp_dir(), '/' );
		$before   = (array) glob( $fallback . '/.mainwp-cs-*' );

		// Steady state on a site that has written before: the lock file already exists, so the
		// writer gets past locking and the staging refusal is what stops it.
		file_put_contents( $this->config_dir . '/' . self::LOCK_FILE, '' );

		chmod( $this->config_dir, 0555 );
		clearstatcache();
		if ( is_writable( $this->config_dir ) ) {
			chmod( $this->config_dir, 0755 );
			$this->markTestSkipped( 'Directory permissions are not enforced for this user.' );
		}

		$result = $this->fixture->snippet_v2( 'apply_snippet_v2', $this->request( 'C', "define( 'FIXTURE_VALUE', true );" ) );
		chmod( $this->config_dir, 0755 );

		$this->assertSame( 'storage_failed', $result['error_code'] );
		$this->assertSame( $original, file_get_contents( $path ) );
		$this->assertSame( $inode, fileinode( $path ) );
		$this->assertSame( array( false ), $this->fixture->staged, 'Staging must refuse rather than fall back outside the configuration directory.' );
		$this->assertSame( array(), (array) glob( $this->config_dir . '/.mainwp-cs-*' ) );
		$this->assertSame( $before, (array) glob( $fallback . '/.mainwp-cs-*' ), 'Configuration bytes must not be left in the system temporary directory.' );
	}

	/** Staged configuration bytes sit beside the target, carry a .php suffix, and leave nothing behind. */
	public function test_config_staging_is_php_suffixed_beside_the_target_and_removed() {
		$path   = $this->isolated_config( "<?php\ndefine( 'DB_PASSWORD', 'FIXTURE-SECRET' );\n\$table_prefix = 'wp_';\n/* collateral */\n" );
		$result = $this->fixture->snippet_v2( 'apply_snippet_v2', $this->request( 'C', "define( 'FIXTURE_VALUE', true );" ) );

		$this->assertSame( 'changed', $result['result'] );
		$this->assertCount( 1, $this->fixture->staged );
		$staged = $this->fixture->staged[0];
		$this->assertIsString( $staged );
		$this->assertSame( $this->config_dir, dirname( $staged ), 'Staging in another directory would make the rename non-atomic.' );
		$this->assertSame( '.php', substr( $staged, -4 ) );
		$this->assertFileDoesNotExist( $staged );
		$this->assertSame( array(), (array) glob( $this->config_dir . '/.mainwp-cs-*' ) );

		$applied = file_get_contents( $path );
		$this->assertStringContainsString( 'FIXTURE_VALUE', $applied );
		$this->assertStringContainsString( '/* collateral */', $applied );
		$this->assertStringContainsString( "define( 'DB_PASSWORD', 'FIXTURE-SECRET' );", $applied );
		$this->assertSame( 0644, fileperms( $path ) & 0777 );

		$remove = $this->request( 'C' );
		unset( $remove['code'] );
		$this->assertSame( 'removed', $this->fixture->snippet_v2( 'remove_snippet_v2', $remove )['result'] );
		$this->assertStringNotContainsString( 'FIXTURE_VALUE', file_get_contents( $path ) );
		$this->assertStringContainsString( '/* collateral */', file_get_contents( $path ) );
		$this->assertSame( array(), (array) glob( $this->config_dir . '/.mainwp-cs-*' ) );
	}

	/** A committed write is reported as it happened when only the lock release failed. */
	public function test_committed_write_survives_a_failed_lock_release() {
		$this->fixture->release_fails = true;

		$result = $this->fixture->snippet_v2( 'apply_snippet_v2', $this->request() );

		$this->assertTrue( $result['success'] );
		$this->assertSame( 'changed', $result['result'] );
		$this->assertSame( 'confirmed', $result['installed_state'] );
		$this->assertNull( $result['error_code'] );
		$this->assertSame( array( 'lock_release_failed' ), $result['warning_codes'] );
		$this->assertSame( "echo 'ok';", $this->fixture->options['mainwp_ext_code_snippets']['FixtureSlug1'] );

		$remove = $this->request();
		unset( $remove['code'] );
		$removal = $this->fixture->snippet_v2( 'remove_snippet_v2', $remove );
		$this->assertSame( 'removed', $removal['result'] );
		$this->assertSame( array( 'lock_release_failed' ), $removal['warning_codes'] );

		$this->fixture->write_fails = true;
		$failed                     = $this->fixture->snippet_v2( 'apply_snippet_v2', $this->request() );
		$this->assertFalse( $failed['success'], 'A write that really failed still reports the failure.' );
		$this->assertSame( 'storage_failed', $failed['error_code'] );
		$this->assertSame( array( 'lock_release_failed' ), $failed['warning_codes'] );
	}

	/** Every reply carries one envelope and correlates to the request that produced it. */
	public function test_every_response_shares_one_envelope() {
		$responses                  = array();
		$responses['run_succeeded'] = $this->fixture->snippet_v2( 'run_snippet_v2', $this->request( 'R', "echo 'ok';" ) );
		$responses['run_failed']    = $this->fixture->snippet_v2( 'run_snippet_v2', $this->request( 'R', "throw new Exception('x');" ) );
		$responses['applied']       = $this->fixture->snippet_v2( 'apply_snippet_v2', $this->request() );
		$responses['invalid_type']  = $this->fixture->snippet_v2( 'apply_snippet_v2', $this->request( 'X' ) );

		$this->fixture->lock_busy = true;
		$responses['lock_busy']   = $this->fixture->snippet_v2( 'apply_snippet_v2', $this->request() );
		$this->fixture->lock_busy = false;

		foreach ( $responses as $label => $response ) {
			foreach ( array( 'success', 'request_ref', 'error_code', 'warning_codes' ) as $key ) {
				$this->assertArrayHasKey( $key, $response, $label . ' is missing ' . $key );
			}
			$this->assertIsBool( $response['success'], $label );
			$this->assertIsArray( $response['warning_codes'], $label );
			$this->assertTrue( null === $response['error_code'] || is_string( $response['error_code'] ), $label );
			$this->assertSame( $this->request_ref, $response['request_ref'], $label . ' must correlate to its request' );
		}

		$this->assertNull( $responses['applied']['error_code'] );
		$this->assertSame( 'execution_failed', $responses['run_failed']['error_code'] );
		$this->assertSame( 'invalid_request', $responses['invalid_type']['error_code'] );
		$this->assertFalse( $responses['invalid_type']['success'] );
		$this->assertSame( 'lock_busy', $responses['lock_busy']['error_code'] );
		$this->assertNull(
			$this->fixture->snippet_v2( 'apply_snippet_v2', array() )['request_ref'],
			'A request carrying no usable reference must report none rather than invent one.'
		);
	}

	/** Slugs v1 accepted with a hyphen or underscore stay addressable, in both storage backends. */
	public function test_v1_style_slugs_round_trip() {
		$stored         = $this->request();
		$stored['slug'] = 'legacy-slug_01';
		$this->assertSame( 'changed', $this->fixture->snippet_v2( 'apply_snippet_v2', $stored )['result'] );
		$this->assertSame( "echo 'ok';", $this->fixture->options['mainwp_ext_code_snippets']['legacy-slug_01'] );

		$remove = $stored;
		unset( $remove['code'] );
		$this->assertSame( 'removed', $this->fixture->snippet_v2( 'remove_snippet_v2', $remove )['result'] );
		$this->assertArrayNotHasKey( 'legacy-slug_01', $this->fixture->options['mainwp_ext_code_snippets'] );

		$config         = $this->request( 'C', "define( 'LEGACY_SLUG', true );" );
		$config['slug'] = 'legacy-slug_01';
		$this->assertSame( 'changed', $this->fixture->snippet_v2( 'apply_snippet_v2', $config )['result'] );
		$this->assertStringContainsString( '/***snippet_legacy-slug_01***/', file_get_contents( $this->config_path ) );

		$config_remove = $config;
		unset( $config_remove['code'] );
		$this->assertSame( 'removed', $this->fixture->snippet_v2( 'remove_snippet_v2', $config_remove )['result'] );
		$this->assertStringNotContainsString( 'LEGACY_SLUG', file_get_contents( $this->config_path ) );
		$this->assertStringContainsString( '/* collateral */', file_get_contents( $this->config_path ) );
	}

	/** Validation reads the key set, not the order the Dashboard happened to serialize it in. */
	public function test_request_key_order_does_not_decide_validity() {
		$reordered = array_reverse( $this->request(), true );
		$this->assertSame( array( 'code', 'type', 'slug', 'request_ref', 'protocol_version' ), array_keys( $reordered ) );

		$result = $this->fixture->snippet_v2( 'apply_snippet_v2', $reordered );
		$this->assertTrue( $result['success'] );
		$this->assertSame( 'changed', $result['result'] );

		$missing = $this->request();
		unset( $missing['type'] );
		$this->assertSame( 'invalid_request', $this->fixture->snippet_v2( 'apply_snippet_v2', $missing )['error_code'] );
	}

	/** The configuration lock is held across the replace and is not the inode the rename swaps out. */
	public function test_configuration_lock_is_held_across_the_replace_and_outlives_it() {
		$path         = $this->isolated_config( "<?php\n\$table_prefix = 'wp_';\n/* collateral */\n" );
		$lock         = $this->config_dir . '/' . self::LOCK_FILE;
		$target_inode = fileinode( $path );

		$result = $this->fixture->snippet_v2( 'apply_snippet_v2', $this->request( 'C', "define( 'FIXTURE_VALUE', true );" ) );

		$this->assertSame( 'changed', $result['result'] );
		$this->assertFileExists( $lock );
		$this->assertFalse( $this->fixture->lock_free_during_write, 'The lock must be held while the replacement is staged and renamed.' );
		$this->assertNotSame( $target_inode, fileinode( $path ), 'The replacement must arrive by rename.' );
		$this->assertSame( $this->fixture->lock_inode_during_write, fileinode( $lock ), 'The lock must not sit on the inode the rename replaces.' );
	}
}
