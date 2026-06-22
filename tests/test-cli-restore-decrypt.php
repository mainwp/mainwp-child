<?php
/**
 * Class CLIRestoreDecryptTest
 *
 * Tests the no-dashboard recovery of an encrypted native backup: the cipher
 * port, the passphrase-driven KEK/DEK recovery, the whole and chunked
 * decrypt-and-assemble paths, and how the CLI runner adopts the decrypted
 * plaintext. Sealed fixtures are built with the child's own cipher so the
 * tests are self-contained; one cross-implementation vector is sealed with
 * PHP's native openssl to prove the ported open() reads standard AES-256-GCM.
 *
 * @package Mainwp_Child
 */

use MainWP\Child\MainWP_Child_Backup_Archive_Cipher;
use MainWP\Child\MainWP_Child_Backup_Decryptor;
use MainWP\Child\MainWP_Child_CLI_Restore_Runner;
use MainWP\Child\MainWP_Child_Backup_Task;
use MainWP\Child\MainWP_Backup_Error;
use MainWP\Child\MainWP_Exception;
use MainWP\Child\MainWP_Helper;

// The Fake_CLI_Restore_Engine and Fake_CLI_Spine doubles live with the Stage 1
// runner tests; load them so this file also runs in isolation.
require_once __DIR__ . '/test-cli-restore-runner.php';

/**
 * Tests for MainWP_Child_Backup_Decryptor and the runner's encrypted path.
 */
class CLIRestoreDecryptTest extends WP_UnitTestCase {

	/**
	 * Directories to remove recursively in tear_down.
	 *
	 * @var string[]
	 */
	private $dirs = array();

	/**
	 * Files to remove in tear_down.
	 *
	 * @var string[]
	 */
	private $files = array();

	/**
	 * The resolved backup directory (where the runner writes plaintext).
	 *
	 * @var string
	 */
	private $backup_dir;

	/**
	 * Resolve the backup directory once.
	 */
	public function set_up() {
		parent::set_up();
		$dirs             = MainWP_Helper::get_mainwp_dir( 'backup' );
		$this->backup_dir = rtrim( $dirs[0], '/' ) . '/';
	}

	/**
	 * Remove fixture directories and files.
	 */
	public function tear_down() {
		foreach ( $this->files as $file ) {
			if ( is_file( $file ) ) {
				unlink( $file ); // phpcs:ignore WordPress.WP.AlternativeFunctions -- test cleanup.
			}
		}
		foreach ( $this->dirs as $dir ) {
			$this->rrmdir( $dir );
		}
		$this->files = array();
		$this->dirs  = array();
		parent::tear_down();
	}

	/**
	 * Recursively remove a directory.
	 *
	 * @param string $dir Directory.
	 */
	private function rrmdir( $dir ) {
		if ( ! is_dir( $dir ) ) {
			return;
		}
		$items = array_diff( scandir( $dir ), array( '.', '..' ) );
		foreach ( $items as $item ) {
			$path = $dir . DIRECTORY_SEPARATOR . $item;
			if ( is_dir( $path ) ) {
				$this->rrmdir( $path );
			} else {
				unlink( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions -- test cleanup.
			}
		}
		rmdir( $dir ); // phpcs:ignore WordPress.WP.AlternativeFunctions -- test cleanup.
	}

	/**
	 * Build a sealed offsite bundle on disk, mirroring what the dashboard
	 * uploads. Returns the bundle directory and the facts a test asserts on.
	 *
	 * @param array $opts Overrides: passphrase, plaintext, mode, file, role,
	 *                    snapshot_id, parent_id, chunk_size, algo, iters,
	 *                    with_hmac, encrypt.
	 *
	 * @return array
	 */
	private function build_bundle( $opts = array() ) {
		$o = array_merge(
			array(
				'passphrase'  => 'correct horse battery staple',
				'plaintext'   => 'plaintext archive payload',
				'mode'        => 'whole',
				'file'        => 'snap.bin',
				'role'        => 'full',
				'snapshot_id' => 7,
				'parent_id'   => null,
				'chunk_size'  => 8,
				'algo'        => 'sha256',
				'iters'       => 1000,
				'with_hmac'   => true,
				'encrypt'     => true,
			),
			$opts
		);

		$dir = rtrim( get_temp_dir(), '/' ) . '/mwp-bundle-' . substr( md5( uniqid( '', true ) ), 0, 10 );
		wp_mkdir_p( $dir );
		$this->dirs[] = $dir;

		$kek = random_bytes( 32 );
		$dek = random_bytes( 32 );

		$snapshot_id = (int) $o['snapshot_id'];
		$plaintext   = (string) $o['plaintext'];

		$artifact = array(
			'file' => $o['file'],
			'role' => $o['role'],
			'size' => strlen( $plaintext ),
		);

		if ( 'chunked' === $o['mode'] ) {
			wp_mkdir_p( $dir . '/chunks' );
			$chunks = array();
			$offset = 0;
			$step   = max( 1, (int) $o['chunk_size'] );
			for ( $i = 0; $i < strlen( $plaintext ); $i += $step ) {
				$piece = substr( $plaintext, $i, $step );
				$hash  = hash( 'sha256', $piece );
				$blob  = MainWP_Child_Backup_Archive_Cipher::seal( $piece, $dek, 'snapshot:' . $snapshot_id . ':chunk:' . $hash );
				file_put_contents( $dir . '/chunks/' . $hash . '.bin', $blob ); // phpcs:ignore WordPress.WP.AlternativeFunctions -- test fixture.
				$chunks[] = array(
					'hash'   => $hash,
					'algo'   => $o['algo'],
					'offset' => $offset,
					'length' => strlen( $piece ),
				);
				$offset  += strlen( $piece );
			}
			$artifact['mode']   = 'chunked';
			$artifact['sha256'] = hash( 'sha256', $plaintext );
			$artifact['chunks'] = $chunks;
		} else {
			wp_mkdir_p( $dir . '/files' );
			$blob = MainWP_Child_Backup_Archive_Cipher::seal( $plaintext, $dek, 'snapshot:' . $snapshot_id . ':whole:' . $o['file'] );
			file_put_contents( $dir . '/files/' . $o['file'], $blob ); // phpcs:ignore WordPress.WP.AlternativeFunctions -- test fixture.
			$artifact['mode']   = 'whole';
			$artifact['sha256'] = hash( 'sha256', $plaintext );
			$artifact['key']    = 'files/' . $o['file'];
		}

		$manifest = array(
			'format'      => 1,
			'snapshot_id' => $snapshot_id,
			'task_id'     => 1,
			'site_id'     => 4,
			'type'        => 'db' === $o['role'] ? 'db' : 'full',
			'created'     => time(),
			'parent_id'   => $o['parent_id'],
			'artifacts'   => array( $artifact ),
			'site_url'    => 'https://example.test',
		);

		if ( $o['encrypt'] ) {
			$salt         = random_bytes( 16 );
			$recovery_key = hash_pbkdf2( 'sha256', (string) $o['passphrase'], $salt, (int) $o['iters'], 32, true );
			$wrapped_kek  = base64_encode( MainWP_Child_Backup_Archive_Cipher::seal( $kek, $recovery_key, 'wrap:kek' ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions -- test fixture.
			$wrapped_dek  = base64_encode( MainWP_Child_Backup_Archive_Cipher::seal( $dek, $kek, 'wrap:dek:' . $snapshot_id ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions -- test fixture.

			$manifest['encryption'] = array(
				'enc'            => 1,
				'cipher'         => 'aes-256-gcm',
				'overhead'       => 28,
				'wrapped_dek'    => $wrapped_dek,
				'kek_version_id' => 'deadbeefdeadbeef',
			);
			$manifest['recovery']   = array(
				'algo'           => 'pbkdf2-sha256',
				'iters'          => (int) $o['iters'],
				'salt'           => base64_encode( $salt ), // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions -- test fixture.
				'wrapped_kek'    => $wrapped_kek,
				'kek_version_id' => 'deadbeefdeadbeef',
			);
		} else {
			$manifest['encryption'] = array( 'enc' => 0 );
		}

		$raw = wp_json_encode( $manifest );
		file_put_contents( $dir . '/manifest.json', $raw ); // phpcs:ignore WordPress.WP.AlternativeFunctions -- test fixture.
		if ( $o['with_hmac'] ) {
			file_put_contents( $dir . '/manifest.json.hmac', hash_hmac( 'sha256', $raw, $kek ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions -- test fixture.
		}

		return array(
			'dir'       => $dir,
			'file'      => $o['file'],
			'sha256'    => hash( 'sha256', $plaintext ),
			'plaintext' => $plaintext,
			'kek'       => $kek,
		);
	}

	/**
	 * A destination directory the decryptor can write into.
	 *
	 * @return string
	 */
	private function dest_dir() {
		$dir = rtrim( get_temp_dir(), '/' ) . '/mwp-dest-' . substr( md5( uniqid( '', true ) ), 0, 10 );
		wp_mkdir_p( $dir );
		$this->dirs[] = $dir;
		return $dir;
	}

	/**
	 * The ported cipher opens a unit sealed by PHP's native openssl, proving it
	 * reads standard AES-256-GCM and not just its own seal().
	 */
	public function test_cipher_opens_openssl_sealed_unit() {
		$key   = str_repeat( "\x01", 32 );
		$nonce = str_repeat( "\x02", 12 );
		$aad   = 'snapshot:7:whole:probe.bin';
		$plain = 'cross-implementation probe payload';

		$tag = '';
		$ct  = openssl_encrypt( $plain, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $nonce, $tag, $aad, 16 );
		$blob = $nonce . $ct . $tag;

		$out = MainWP_Child_Backup_Archive_Cipher::open( $blob, $key, $aad );
		$this->assertSame( $plain, $out );
	}

	/**
	 * The cipher refuses a unit opened with the wrong AAD.
	 */
	public function test_cipher_rejects_wrong_aad() {
		$key  = str_repeat( "\x03", 32 );
		$blob = MainWP_Child_Backup_Archive_Cipher::seal( 'secret', $key, 'aad-one' );

		$this->expectException( \RuntimeException::class );
		MainWP_Child_Backup_Archive_Cipher::open( $blob, $key, 'aad-two' );
	}

	/**
	 * A whole-mode bundle decrypts byte-for-byte with the right passphrase.
	 */
	public function test_whole_round_trip() {
		$b    = $this->build_bundle( array( 'mode' => 'whole' ) );
		$dest = $this->dest_dir();

		$path = ( new MainWP_Child_Backup_Decryptor() )->decrypt_to_plaintext( $b['dir'], $b['file'], 'correct horse battery staple', $dest );

		$this->assertFileExists( $path );
		$this->assertSame( $b['plaintext'], file_get_contents( $path ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions -- test read.
		$this->assertSame( $b['sha256'], hash_file( 'sha256', $path ) );
	}

	/**
	 * A chunked bundle reassembles and decrypts byte-for-byte, exercising the
	 * per-chunk content-hash verification (algo sha256 is always available).
	 */
	public function test_chunked_round_trip_verifies_each_chunk() {
		$plain = str_repeat( 'abcdefghij', 25 ); // 250 bytes over several chunks.
		$b     = $this->build_bundle(
			array(
				'mode'       => 'chunked',
				'plaintext'  => $plain,
				'chunk_size' => 32,
				'algo'       => 'sha256',
			)
		);
		$dest = $this->dest_dir();

		$path = ( new MainWP_Child_Backup_Decryptor() )->decrypt_to_plaintext( $b['dir'], $b['file'], 'correct horse battery staple', $dest );

		$this->assertSame( $plain, file_get_contents( $path ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions -- test read.
		$this->assertSame( $b['sha256'], hash_file( 'sha256', $path ) );
	}

	/**
	 * A chunk whose hash algorithm is not available on this PHP build still
	 * restores: the GCM tag authenticates the chunk and the whole-file sha256
	 * guards the assembled result. This is the PHP-7.4-with-xxh128 case.
	 */
	public function test_chunked_skips_unavailable_hash_algo() {
		$this->assertFalse( in_array( 'totally-not-an-algo', hash_algos(), true ) );

		$plain = str_repeat( 'ZY', 100 );
		$b     = $this->build_bundle(
			array(
				'mode'       => 'chunked',
				'plaintext'  => $plain,
				'chunk_size' => 16,
				'algo'       => 'totally-not-an-algo',
			)
		);
		$dest = $this->dest_dir();

		$path = ( new MainWP_Child_Backup_Decryptor() )->decrypt_to_plaintext( $b['dir'], $b['file'], 'correct horse battery staple', $dest );

		$this->assertSame( $plain, file_get_contents( $path ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions -- test read.
	}

	/**
	 * A wrong passphrase fails closed: it throws with the decrypt code and
	 * writes no plaintext.
	 */
	public function test_wrong_passphrase_fails_closed() {
		$b    = $this->build_bundle();
		$dest = $this->dest_dir();

		try {
			( new MainWP_Child_Backup_Decryptor() )->decrypt_to_plaintext( $b['dir'], $b['file'], 'wrong passphrase', $dest );
			$this->fail( 'A wrong passphrase must throw.' );
		} catch ( MainWP_Exception $e ) {
			$this->assertSame( MainWP_Backup_Error::ERR_RESTORE_DECRYPT, $e->get_message_error_code() );
		}

		$this->assertFileDoesNotExist( $dest . '/' . $b['file'] );
	}

	/**
	 * A tampered manifest fails closed even with the right passphrase: the KEK
	 * recovers, but the HMAC over the altered bytes no longer matches.
	 */
	public function test_manifest_tamper_fails_closed() {
		$b   = $this->build_bundle();
		$raw = file_get_contents( $b['dir'] . '/manifest.json' ); // phpcs:ignore WordPress.WP.AlternativeFunctions -- test read.
		// Flip bytes outside the recovery block so KEK recovery still succeeds
		// and only the HMAC catches the change. 'example' appears in site_url
		// regardless of JSON slash escaping.
		$tampered = str_replace( 'example', 'evilexx', $raw );
		$this->assertNotSame( $raw, $tampered );
		file_put_contents( $b['dir'] . '/manifest.json', $tampered ); // phpcs:ignore WordPress.WP.AlternativeFunctions -- test fixture.

		$dest = $this->dest_dir();
		try {
			( new MainWP_Child_Backup_Decryptor() )->decrypt_to_plaintext( $b['dir'], $b['file'], 'correct horse battery staple', $dest );
			$this->fail( 'A tampered manifest must throw.' );
		} catch ( MainWP_Exception $e ) {
			$this->assertSame( MainWP_Backup_Error::ERR_RESTORE_DECRYPT, $e->get_message_error_code() );
		}
		$this->assertFileDoesNotExist( $dest . '/' . $b['file'] );
	}

	/**
	 * A decrypted archive whose bytes do not match the manifest checksum is
	 * rejected as corrupt and not left in place.
	 */
	public function test_checksum_mismatch_is_rejected() {
		$b = $this->build_bundle( array( 'mode' => 'whole' ) );
		// Rewrite the manifest with a wrong sha256 for the artifact.
		$data                            = json_decode( file_get_contents( $b['dir'] . '/manifest.json' ), true ); // phpcs:ignore WordPress.WP.AlternativeFunctions -- test read.
		$data['artifacts'][0]['sha256']  = str_repeat( '0', 64 );
		$raw                             = wp_json_encode( $data );
		file_put_contents( $b['dir'] . '/manifest.json', $raw ); // phpcs:ignore WordPress.WP.AlternativeFunctions -- test fixture.
		// Re-sign so the HMAC check passes and we reach the checksum step.
		file_put_contents( $b['dir'] . '/manifest.json.hmac', hash_hmac( 'sha256', $raw, $b['kek'] ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions -- test fixture.

		$dest = $this->dest_dir();
		try {
			( new MainWP_Child_Backup_Decryptor() )->decrypt_to_plaintext( $b['dir'], $b['file'], 'correct horse battery staple', $dest );
			$this->fail( 'A checksum mismatch must throw.' );
		} catch ( MainWP_Exception $e ) {
			$this->assertSame( MainWP_Backup_Error::ERR_ARTIFACT_CORRUPT, $e->get_message_error_code() );
		}
		$this->assertFileDoesNotExist( $dest . '/' . $b['file'] );
	}

	/**
	 * An incremental snapshot (parent_id set) is refused; the chain needs the
	 * dashboard.
	 */
	public function test_incremental_is_refused() {
		$b    = $this->build_bundle( array( 'parent_id' => 5 ) );
		$dest = $this->dest_dir();

		try {
			( new MainWP_Child_Backup_Decryptor() )->decrypt_to_plaintext( $b['dir'], $b['file'], 'correct horse battery staple', $dest );
			$this->fail( 'An incremental snapshot must be refused.' );
		} catch ( MainWP_Exception $e ) {
			$this->assertSame( MainWP_Backup_Error::ERR_RESTORE_INCREMENTAL, $e->get_message_error_code() );
		}
	}

	/**
	 * Asking for an artifact the manifest does not list is reported as unknown.
	 */
	public function test_unknown_artifact_is_reported() {
		$b    = $this->build_bundle();
		$dest = $this->dest_dir();

		try {
			( new MainWP_Child_Backup_Decryptor() )->decrypt_to_plaintext( $b['dir'], 'not-listed.bin', 'correct horse battery staple', $dest );
			$this->fail( 'An unknown artifact must throw.' );
		} catch ( MainWP_Exception $e ) {
			$this->assertSame( MainWP_Backup_Error::ERR_ARTIFACT_UNKNOWN, $e->get_message_error_code() );
		}
	}

	/**
	 * A bundle that is not encrypted is refused on the passphrase path; it
	 * belongs to the plain restore form.
	 */
	public function test_unencrypted_bundle_is_refused() {
		$b    = $this->build_bundle( array( 'encrypt' => false ) );
		$dest = $this->dest_dir();

		try {
			( new MainWP_Child_Backup_Decryptor() )->decrypt_to_plaintext( $b['dir'], $b['file'], 'correct horse battery staple', $dest );
			$this->fail( 'A non-encrypted bundle must be refused on the passphrase path.' );
		} catch ( MainWP_Exception $e ) {
			$this->assertSame( MainWP_Backup_Error::ERR_RESTORE_DECRYPT, $e->get_message_error_code() );
		}
	}

	/**
	 * A path-traversal archive name is rejected before any file is touched.
	 */
	public function test_archive_name_traversal_is_rejected() {
		$b    = $this->build_bundle();
		$dest = $this->dest_dir();

		try {
			( new MainWP_Child_Backup_Decryptor() )->decrypt_to_plaintext( $b['dir'], '../escape.bin', 'correct horse battery staple', $dest );
			$this->fail( 'A traversal archive name must be rejected.' );
		} catch ( MainWP_Exception $e ) {
			$this->assertSame( MainWP_Backup_Error::ERR_ARTIFACT_UNKNOWN, $e->get_message_error_code() );
		}
	}

	/**
	 * End to end through the runner: a passphrase + bundle decrypts the archive
	 * into the backup directory and the engine then adopts and completes it.
	 */
	public function test_runner_decrypts_then_restores() {
		// The decrypted plaintext must be a real zip so the readability check
		// passes and the engine is asked to restore it.
		$zip_path = rtrim( get_temp_dir(), '/' ) . '/seed-' . substr( md5( uniqid( '', true ) ), 0, 8 ) . '.zip';
		$zip      = new ZipArchive();
		$zip->open( $zip_path, ZipArchive::CREATE );
		$zip->addFromString( 'hello.txt', 'world' );
		$zip->close();
		$this->files[] = $zip_path;
		$zip_bytes     = file_get_contents( $zip_path ); // phpcs:ignore WordPress.WP.AlternativeFunctions -- test read.

		$b = $this->build_bundle(
			array(
				'mode'      => 'whole',
				'file'      => 'recovered.zip',
				'plaintext' => $zip_bytes,
			)
		);
		$this->files[] = $this->backup_dir . 'recovered.zip';

		$engine       = new Fake_CLI_Restore_Engine();
		$engine->rows = array(
			$this->row( 'restoring_files', 2 ),
			$this->row( 'completed', 3 ),
		);
		$spine            = new Fake_CLI_Spine();
		$spine->start_row = $this->row( 'queued', 1 );
		$runner           = new MainWP_Child_CLI_Restore_Runner( $engine, $spine, 0 );

		$result = $runner->run( 'recovered.zip', 'full', true, null, 'correct horse battery staple', $b['dir'] );

		$this->assertTrue( $result['ok'], isset( $result['error'] ) ? $result['error'] : '' );
		$this->assertTrue( $engine->created );
		$this->assertFileExists( $this->backup_dir . 'recovered.zip' );
		$this->assertSame( $zip_bytes, file_get_contents( $this->backup_dir . 'recovered.zip' ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions -- test read.
	}

	/**
	 * A passphrase with no bundle is refused before any work.
	 */
	public function test_runner_passphrase_without_bundle_is_refused() {
		$engine = new Fake_CLI_Restore_Engine();
		$spine  = new Fake_CLI_Spine();
		$runner = new MainWP_Child_CLI_Restore_Runner( $engine, $spine, 0 );

		$result = $runner->run( 'recovered.zip', 'full', true, null, 'a passphrase', '' );

		$this->assertFalse( $result['ok'] );
		$this->assertSame( MainWP_Backup_Error::ERR_RESTORE_DECRYPT, $result['error_code'] );
		$this->assertFalse( $engine->created );
	}

	/**
	 * A bundle whose manifest marks it encrypted, run without a passphrase, is
	 * told to re-run with the passphrase rather than refused as corrupt.
	 */
	public function test_runner_encrypted_bundle_without_passphrase_hints() {
		$b      = $this->build_bundle();
		$engine = new Fake_CLI_Restore_Engine();
		$spine  = new Fake_CLI_Spine();
		$runner = new MainWP_Child_CLI_Restore_Runner( $engine, $spine, 0 );

		$result = $runner->run( $b['file'], 'full', true, null, '', $b['dir'] );

		$this->assertFalse( $result['ok'] );
		$this->assertSame( MainWP_Backup_Error::ERR_RESTORE_ENCRYPTED, $result['error_code'] );
		$this->assertStringContainsString( '--passphrase', $result['error'] );
		$this->assertFalse( $engine->created );
	}

	/**
	 * A wrong passphrase through the runner fails closed and creates no task.
	 */
	public function test_runner_wrong_passphrase_fails_closed() {
		$b      = $this->build_bundle();
		$engine = new Fake_CLI_Restore_Engine();
		$spine  = new Fake_CLI_Spine();
		$runner = new MainWP_Child_CLI_Restore_Runner( $engine, $spine, 0 );

		$result = $runner->run( $b['file'], 'full', true, null, 'nope nope nope', $b['dir'] );

		$this->assertFalse( $result['ok'] );
		$this->assertSame( MainWP_Backup_Error::ERR_RESTORE_DECRYPT, $result['error_code'] );
		$this->assertFalse( $engine->created );
	}

	/**
	 * A task row stub matching the runner's expectations.
	 *
	 * @param string $phase   Phase.
	 * @param int    $version Version.
	 *
	 * @return object
	 */
	private function row( $phase, $version ) {
		return (object) array(
			'task_id'   => 'cli-task-1',
			'phase'     => $phase,
			'version'   => $version,
			'sub_state' => wp_json_encode( array() ),
		);
	}
}
