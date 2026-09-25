<?php
/**
 * MainWP Child Backup Decryptor.
 *
 * The no-dashboard recovery path for an encrypted native backup. When the
 * local plaintext archive is gone and the dashboard is unreachable, an
 * operator pulls the offsite ciphertext (the archive's `{site}/{snapshot}/`
 * prefix: a manifest, its HMAC, and either a single sealed file or a directory
 * of sealed chunks) and decrypts it here with the recovery passphrase. The
 * recovered plaintext archive is then handed to the existing CLI restore path.
 *
 * This is the decrypt-only mirror of the dashboard's MainWP_Backup_Encryption
 * recovery subset: rebuild the KEK from the passphrase and the manifest's
 * recovery blob, unwrap the snapshot DEK, and open each sealed unit. It mints
 * no keys, rewraps nothing, and reads no WordPress option or database - every
 * input comes from the manifest that travels beside the archive. A change to
 * the dashboard's key derivation, wrapping, or manifest shape must be mirrored
 * here.
 *
 * @package MainWP\Child
 */

namespace MainWP\Child;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Class MainWP_Child_Backup_Decryptor
 */
class MainWP_Child_Backup_Decryptor { // phpcs:ignore Generic.Classes.OpeningBraceSameLine.ContentAfterBrace -- NOSONAR.

    /**
     * Default PBKDF2 hash when a recovery blob does not name one.
     *
     * @var string
     */
    const PBKDF2_ALGO = 'sha256';

    /**
     * Read and decode the manifest that sits beside a pulled offsite archive.
     *
     * @param string $bundle_dir Directory holding the pulled offsite prefix.
     *
     * @return array|null { @type string $raw, @type array $data } or null when absent/unreadable.
     */
    public function read_manifest( $bundle_dir ) {
        $path = rtrim( (string) $bundle_dir, '/\\' ) . DIRECTORY_SEPARATOR . 'manifest.json';
        if ( ! is_file( $path ) ) {
            return null;
        }
        $raw = $this->read_file_bytes( $path );
        if ( false === $raw || '' === $raw ) {
            return null;
        }
        $data = json_decode( $raw, true );
        if ( ! is_array( $data ) ) {
            return null;
        }
        return array(
            'raw'  => $raw,
            'data' => $data,
        );
    }

    /**
     * Whether a snapshot's decoded encryption metadata marks it as encrypted.
     *
     * @param mixed $meta Decoded encryption metadata (array) or anything else.
     *
     * @return bool
     */
    public static function meta_is_encrypted( $meta ) {
        return is_array( $meta ) && ! empty( $meta['enc'] );
    }

    /**
     * Decrypt one artifact from a pulled offsite bundle into a plaintext
     * archive in the backup directory, ready for the existing restore path.
     *
     * @param string $bundle_dir Directory holding the manifest and sealed bytes.
     * @param string $archive    Artifact file name to recover (clean basename).
     * @param string $passphrase Operator recovery passphrase.
     * @param string $dest_dir   Backup directory the plaintext is written into.
     *
     * @return string Absolute path of the written plaintext archive.
     *
     * @throws MainWP_Exception When the passphrase is wrong, the bundle is
     *                          incomplete or tampered, the snapshot needs the
     *                          dashboard, or a sealed unit fails to open.
     */
    public function decrypt_to_plaintext( $bundle_dir, $archive, $passphrase, $dest_dir ) { // phpcs:ignore Generic.Metrics.CyclomaticComplexity, Squiz.Commenting.FunctionComment -- NOSONAR - linear recovery sequence.
        if ( ! $this->phpseclib3_supported() ) {
            throw new MainWP_Exception( 'This PHP build cannot decrypt backups (phpseclib AES-GCM is unavailable). Restore on a host with a current PHP build.', null, MainWP_Backup_Error::ERR_RESTORE_DECRYPT ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- internal CLI message.
        }
        if ( ! $this->is_basename( $archive ) ) {
            throw new MainWP_Exception( 'The archive name must be a plain file name with no path separators.', null, MainWP_Backup_Error::ERR_ARTIFACT_UNKNOWN ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- internal CLI message.
        }
        if ( ! is_dir( (string) $bundle_dir ) ) {
            throw new MainWP_Exception( 'The bundle directory does not exist: ' . esc_html( (string) $bundle_dir ), null, MainWP_Backup_Error::ERR_ARTIFACT_UNKNOWN ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- internal CLI message; path escaped inline.
        }

        $manifest = $this->read_manifest( $bundle_dir );
        if ( null === $manifest ) {
            throw new MainWP_Exception( 'No readable manifest.json was found in the bundle directory.', null, MainWP_Backup_Error::ERR_ARTIFACT_CORRUPT ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- internal CLI message.
        }
        $data        = $manifest['data'];
        $snapshot_id = isset( $data['snapshot_id'] ) ? (int) $data['snapshot_id'] : 0;

        // An incremental snapshot needs its base and the increments above it,
        // which spans several snapshots. That is the dashboard's job; the
        // single-archive child path restores only self-contained snapshots.
        if ( ! empty( $data['parent_id'] ) ) {
            throw new MainWP_Exception( 'This is an incremental snapshot. Restoring it needs the full chain; use the dashboard restore.', null, MainWP_Backup_Error::ERR_RESTORE_INCREMENTAL ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- internal CLI message.
        }

        if ( ! static::meta_is_encrypted( isset( $data['encryption'] ) ? $data['encryption'] : null ) ) {
            throw new MainWP_Exception( 'This bundle is not encrypted. Restore it with the plain form, without --passphrase.', null, MainWP_Backup_Error::ERR_RESTORE_DECRYPT ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- internal CLI message.
        }

        // Rebuild the KEK from the passphrase, then the per-snapshot DEK. A
        // wrong passphrase fails the wrapped-KEK authentication tag here.
        try {
            $kek = $this->recover_kek( $passphrase, isset( $data['recovery'] ) ? $data['recovery'] : null );
            $dek = $this->dek_from_meta( $data['encryption'], $snapshot_id, $kek );
        } catch ( \RuntimeException $e ) {
            throw new MainWP_Exception( 'Could not decrypt the backup. The passphrase may be wrong, or the recovery material is incomplete.', null, MainWP_Backup_Error::ERR_RESTORE_DECRYPT ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- internal CLI message.
        }

        // With the right KEK in hand, the manifest HMAC proves the manifest
        // itself was not altered. A mismatch here means tampering, not a wrong
        // passphrase (that already failed above). The writer emits the sidecar
        // for every encrypted bundle, so an absent one is a removal and counts
        // the same as a wrong one.
        $hmac_path = rtrim( (string) $bundle_dir, '/\\' ) . DIRECTORY_SEPARATOR . 'manifest.json.hmac';
        $hmac      = is_file( $hmac_path ) ? $this->read_file_bytes( $hmac_path ) : false;
        if ( false === $hmac || ! $this->verify_manifest_hmac( $manifest['raw'], trim( (string) $hmac ), $kek ) ) {
            throw new MainWP_Exception( 'The manifest failed its integrity check; the bundle may be tampered or incomplete.', null, MainWP_Backup_Error::ERR_RESTORE_DECRYPT ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- internal CLI message.
        }

        $artifact = $this->find_artifact( $data, $archive );

        $root = rtrim( (string) $bundle_dir, '/\\' ) . DIRECTORY_SEPARATOR;
        $dest = rtrim( (string) $dest_dir, '/\\' ) . DIRECTORY_SEPARATOR . $archive;
        $tmp  = $dest . '.decrypting';

        $out = $this->open_write( $tmp );
        if ( false === $out ) {
            throw new MainWP_Exception( 'Could not open a temporary file in the backup directory. Check its permissions and free space.', null, MainWP_Backup_Error::ERR_DIR_NOT_WRITABLE ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- internal CLI message.
        }

        try {
            if ( 'chunked' === ( isset( $artifact['mode'] ) ? $artifact['mode'] : 'whole' ) ) {
                $this->open_chunks( $out, $artifact, $root, $dek, $snapshot_id );
            } else {
                $this->open_whole( $out, $artifact, $archive, $root, $dek, $snapshot_id );
            }
        } catch ( MainWP_Exception $e ) {
            fclose( $out ); // phpcs:ignore WordPress.WP.AlternativeFunctions -- raw archive write.
            $this->remove( $tmp );
            throw $e; // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- re-throwing an exception this class already built.
        } catch ( \Throwable $e ) {
            fclose( $out ); // phpcs:ignore WordPress.WP.AlternativeFunctions -- raw archive write.
            $this->remove( $tmp );
            throw new MainWP_Exception( 'A sealed unit failed to open. The bundle may be incomplete or tampered.', null, MainWP_Backup_Error::ERR_ARTIFACT_CORRUPT ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- internal CLI message.
        }
        fclose( $out ); // phpcs:ignore WordPress.WP.AlternativeFunctions -- raw archive write.

        // The whole-artifact hash is always sha256, so it verifies the
        // assembled plaintext end to end even on a PHP build that cannot
        // recompute a per-chunk xxh128.
        $expected = isset( $artifact['sha256'] ) ? (string) $artifact['sha256'] : '';
        if ( '' !== $expected && ! hash_equals( $expected, (string) hash_file( 'sha256', $tmp ) ) ) {
            $this->remove( $tmp );
            throw new MainWP_Exception( 'The decrypted archive did not match its expected checksum.', null, MainWP_Backup_Error::ERR_ARTIFACT_CORRUPT ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- internal CLI message.
        }

        if ( ! @rename( $tmp, $dest ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.rename_rename, WordPress.PHP.NoSilencedErrors -- same-dir atomic move with a copy fallback.
            if ( ! @copy( $tmp, $dest ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions, WordPress.PHP.NoSilencedErrors -- last-resort move.
                $this->remove( $tmp );
                throw new MainWP_Exception( 'Could not place the decrypted archive in the backup directory.', null, MainWP_Backup_Error::ERR_DIR_NOT_WRITABLE ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- internal CLI message.
            }
            $this->remove( $tmp );
        }

        return $dest;
    }

    /**
     * Rebuild the KEK from a recovery blob and the passphrase.
     *
     * @param string $passphrase The operator passphrase.
     * @param array  $blob       A recovery blob from the manifest.
     *
     * @return string The recovered KEK.
     *
     * @throws \RuntimeException When the blob is malformed or the passphrase is wrong.
     */
    protected function recover_kek( $passphrase, $blob ) {
        if ( ! is_array( $blob ) || empty( $blob['salt'] ) || empty( $blob['wrapped_kek'] ) || empty( $blob['iters'] ) ) {
            throw new \RuntimeException( 'Recovery material is incomplete.' );
        }
        $salt    = base64_decode( (string) $blob['salt'], true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- binary salt from the manifest.
        $wrapped = base64_decode( (string) $blob['wrapped_kek'], true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- binary key blob from the manifest.
        if ( false === $salt || false === $wrapped ) {
            throw new \RuntimeException( 'Recovery material is not valid base64.' );
        }
        $algo         = ( isset( $blob['algo'] ) && 'pbkdf2-' === substr( (string) $blob['algo'], 0, 7 ) ) ? substr( (string) $blob['algo'], 7 ) : static::PBKDF2_ALGO;
        $recovery_key = hash_pbkdf2( $algo, (string) $passphrase, $salt, (int) $blob['iters'], MainWP_Child_Backup_Archive_Cipher::KEY_LEN, true );
        return MainWP_Child_Backup_Archive_Cipher::open( $wrapped, $recovery_key, 'wrap:kek' );
    }

    /**
     * Recover the DEK from a snapshot's encryption metadata using a KEK.
     *
     * @param array  $meta        Encryption metadata from the manifest.
     * @param int    $snapshot_id The snapshot the DEK belongs to.
     * @param string $kek         The wrapping key.
     *
     * @return string The 32-byte DEK.
     *
     * @throws \RuntimeException When the metadata has no wrapped DEK or it fails to open.
     */
    protected function dek_from_meta( $meta, $snapshot_id, $kek ) {
        if ( ! is_array( $meta ) || empty( $meta['wrapped_dek'] ) ) {
            throw new \RuntimeException( 'Snapshot encryption metadata has no wrapped data key.' );
        }
        $blob = base64_decode( (string) $meta['wrapped_dek'], true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- binary key blob from the manifest.
        if ( false === $blob ) {
            throw new \RuntimeException( 'Wrapped data key is not valid base64.' );
        }
        return MainWP_Child_Backup_Archive_Cipher::open( $blob, $kek, 'wrap:dek:' . (int) $snapshot_id );
    }

    /**
     * Verify the manifest's keyed integrity tag over its exact bytes.
     *
     * @param string $manifest_bytes The raw manifest.json bytes.
     * @param string $hmac_hex       The stored hex HMAC.
     * @param string $kek            The KEK that signed it.
     *
     * @return bool
     */
    protected function verify_manifest_hmac( $manifest_bytes, $hmac_hex, $kek ) {
        return hash_equals( hash_hmac( 'sha256', (string) $manifest_bytes, $kek ), (string) $hmac_hex );
    }

    /**
     * Locate the artifact entry to recover, refusing incremental members.
     *
     * @param array  $manifest Decoded manifest.
     * @param string $archive  Artifact file name.
     *
     * @return array The matching artifact entry.
     *
     * @throws MainWP_Exception When no artifact matches or it is an increment member.
     */
    protected function find_artifact( $manifest, $archive ) {
        $names     = array();
        $artifacts = isset( $manifest['artifacts'] ) && is_array( $manifest['artifacts'] ) ? $manifest['artifacts'] : array();
        foreach ( $artifacts as $artifact ) {
            if ( ! is_array( $artifact ) || ! isset( $artifact['file'] ) ) {
                continue;
            }
            $names[] = (string) $artifact['file'];
            if ( (string) $artifact['file'] !== $archive ) {
                continue;
            }
            $role = isset( $artifact['role'] ) ? (string) $artifact['role'] : '';
            if ( 'part' === $role || 'tombstones' === $role ) {
                throw new MainWP_Exception( 'This archive is part of an incremental chain. Restoring it needs the dashboard.', null, MainWP_Backup_Error::ERR_RESTORE_INCREMENTAL ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- internal CLI message.
            }
            return $artifact;
        }
        $available = empty( $names ) ? 'none' : implode( ', ', $names );
        throw new MainWP_Exception( 'The manifest has no artifact named ' . esc_html( $archive ) . '. Available: ' . esc_html( $available ), null, MainWP_Backup_Error::ERR_ARTIFACT_UNKNOWN ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- internal CLI message; names escaped inline.
    }

    /**
     * Open a whole sealed artifact and write its plaintext to the open handle.
     *
     * @param resource $out         Open write handle for the plaintext.
     * @param array    $artifact    Artifact entry.
     * @param string   $archive     Artifact file name (the whole-unit AAD binds it).
     * @param string   $root        Bundle root with trailing separator.
     * @param string   $dek         The data key.
     * @param int      $snapshot_id Snapshot id for the AAD.
     *
     * @return void
     *
     * @throws MainWP_Exception When the sealed file is missing or unreadable.
     */
    protected function open_whole( $out, $artifact, $archive, $root, $dek, $snapshot_id ) {
        $key = isset( $artifact['key'] ) ? (string) $artifact['key'] : ( 'files/' . $archive );
        // The key is manifest-supplied and lands in a filesystem path, so it
        // must stay a relative path inside the bundle.
        if ( '' === $key || false !== strpos( $key, '..' ) || 0 === strpos( $key, '/' ) || 0 === strpos( $key, '\\' ) ) {
            throw new MainWP_Exception( 'The manifest names a sealed file outside the bundle: ' . esc_html( $key ), null, MainWP_Backup_Error::ERR_ARTIFACT_UNKNOWN ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- internal CLI message; key escaped inline.
        }
        $path = $root . str_replace( '/', DIRECTORY_SEPARATOR, $key );
        $blob = $this->read_file_bytes( $path );
        if ( false === $blob ) {
            throw new MainWP_Exception( 'The sealed archive file is missing from the bundle: ' . esc_html( $key ), null, MainWP_Backup_Error::ERR_ARTIFACT_CORRUPT ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- internal CLI message; key escaped inline.
        }
        $plain = MainWP_Child_Backup_Archive_Cipher::open( $blob, $dek, 'snapshot:' . (int) $snapshot_id . ':whole:' . $archive );
        if ( strlen( $plain ) !== fwrite( $out, $plain ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions -- raw archive write.
            throw new MainWP_Exception( 'Could not write the decrypted archive. Check free space in the backup directory.', null, MainWP_Backup_Error::ERR_DISK_FULL ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- internal CLI message.
        }
    }

    /**
     * Open each sealed chunk in order and append its plaintext to the handle.
     *
     * Each chunk's GCM tag authenticates its bytes on open. The manifest's
     * per-chunk content hash is a second integrity check verified only when
     * the runtime can compute that algorithm; the whole-artifact sha256 backs
     * it on any build.
     *
     * @param resource $out         Open write handle for the plaintext.
     * @param array    $artifact    Artifact entry with a chunks list.
     * @param string   $root        Bundle root with trailing separator.
     * @param string   $dek         The data key.
     * @param int      $snapshot_id Snapshot id for the AAD.
     *
     * @return void
     *
     * @throws MainWP_Exception When a chunk is missing, mis-hashed, or fails to open.
     */
    protected function open_chunks( $out, $artifact, $root, $dek, $snapshot_id ) {
        $chunks = isset( $artifact['chunks'] ) && is_array( $artifact['chunks'] ) ? $artifact['chunks'] : array();
        if ( empty( $chunks ) ) {
            throw new MainWP_Exception( 'The chunked artifact lists no chunks.', null, MainWP_Backup_Error::ERR_ARTIFACT_CORRUPT ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- internal CLI message.
        }
        usort(
            $chunks,
            function ( $a, $b ) {
                return ( isset( $a['offset'] ) ? (int) $a['offset'] : 0 ) <=> ( isset( $b['offset'] ) ? (int) $b['offset'] : 0 );
            }
        );

        $available = hash_algos();
        foreach ( $chunks as $chunk ) {
            $hash = isset( $chunk['hash'] ) ? (string) $chunk['hash'] : '';
            if ( ! $this->is_hex( $hash ) ) {
                throw new MainWP_Exception( 'A chunk reference in the manifest is not a valid hash.', null, MainWP_Backup_Error::ERR_ARTIFACT_CORRUPT ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- internal CLI message.
            }
            $path = $root . 'chunks' . DIRECTORY_SEPARATOR . $hash . '.bin';
            $blob = $this->read_file_bytes( $path );
            if ( false === $blob ) {
                throw new MainWP_Exception( 'A sealed chunk is missing from the bundle: ' . esc_html( $hash ) . '.bin', null, MainWP_Backup_Error::ERR_ARTIFACT_CORRUPT ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- internal CLI message; hash escaped inline.
            }
            $piece = MainWP_Child_Backup_Archive_Cipher::open( $blob, $dek, 'snapshot:' . (int) $snapshot_id . ':chunk:' . $hash );

            $algo = isset( $chunk['algo'] ) ? (string) $chunk['algo'] : '';
            if ( '' !== $algo && in_array( $algo, $available, true ) ) {
                if ( ! hash_equals( $hash, (string) hash( $algo, $piece ) ) ) {
                    throw new MainWP_Exception( 'A decrypted chunk did not match its content hash.', null, MainWP_Backup_Error::ERR_CHUNK_HASH ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- internal CLI message.
                }
            }
            if ( strlen( $piece ) !== fwrite( $out, $piece ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions -- raw archive write.
                throw new MainWP_Exception( 'Could not write the decrypted archive. Check free space in the backup directory.', null, MainWP_Backup_Error::ERR_DISK_FULL ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- internal CLI message.
            }
        }
    }

    /**
     * Whether the runtime can run phpseclib3 AES-GCM. Mirrors the keys
     * manager's gate; archive decryption has no fallback, so an unsupported
     * build must fail closed.
     *
     * @return bool
     */
    protected function phpseclib3_supported() {
        return MainWP_Child_Backup_Archive_Cipher::supported();
    }

    /**
     * Whether a name is a safe basename (no directory traversal).
     *
     * @param mixed $name Candidate name.
     *
     * @return bool
     */
    protected function is_basename( $name ) {
        return is_string( $name ) && '' !== $name
            && false === strpos( $name, '..' )
            && false === strpos( $name, '/' )
            && false === strpos( $name, '\\' );
    }

    /**
     * Whether a string is a plausible hex hash for use as a file name.
     *
     * @param string $value Candidate.
     *
     * @return bool
     */
    protected function is_hex( $value ) {
        return is_string( $value ) && 1 === preg_match( '/^[a-f0-9]{8,128}$/', $value );
    }

    /**
     * Read a whole file as a binary string.
     *
     * @param string $path File path.
     *
     * @return string|false
     */
    protected function read_file_bytes( $path ) {
        $handle = @fopen( $path, 'rb' ); // phpcs:ignore WordPress.WP.AlternativeFunctions, WordPress.PHP.NoSilencedErrors -- raw archive read; WP_Filesystem has no binary stream read.
        if ( false === $handle ) {
            return false;
        }
        $bytes = stream_get_contents( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions -- raw archive read.
        fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions -- raw archive read.
        return false === $bytes ? false : $bytes;
    }

    /**
     * Open a file for binary writing.
     *
     * @param string $path File path.
     *
     * @return resource|false
     */
    protected function open_write( $path ) {
        return @fopen( $path, 'wb' ); // phpcs:ignore WordPress.WP.AlternativeFunctions, WordPress.PHP.NoSilencedErrors -- raw archive write.
    }

    /**
     * Remove a file if it exists.
     *
     * @param string $path File path.
     *
     * @return void
     */
    protected function remove( $path ) {
        if ( is_file( $path ) ) {
            @unlink( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions, WordPress.PHP.NoSilencedErrors -- best-effort cleanup of a temp file.
        }
    }
}
