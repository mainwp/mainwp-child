<?php
/**
 * MainWP Child CLI Restore Runner
 *
 * The engine-agnostic core behind `wp mainwp-child backup restore`. It turns
 * a local archive file name into a restore task on the existing restore
 * engine and drives that task to a terminal phase, so an operator can recover
 * a site when wp-admin is down. It owns no restore logic of its own: it
 * builds the artifact list, calls create_restore_task(), and loops
 * advance_one_slice() until the task completes or fails. The thin WP-CLI
 * command wraps this and renders the result.
 *
 * @package MainWP/Child
 */

namespace MainWP\Child;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Class MainWP_Child_CLI_Restore_Runner
 *
 * @package MainWP\Child
 */
class MainWP_Child_CLI_Restore_Runner {

    /**
     * Lease owner recorded against slices this runner drives.
     *
     * @var string
     */
    const LEASE_OWNER = 'cli';

    /**
     * The restore task runner the CLI reuses (injected for the test seam).
     *
     * @var MainWP_Child_Restore_Task_Runner
     */
    protected $runner;

    /**
     * The task spine, used to read the starting and final task rows.
     *
     * @var MainWP_Child_Backup_Task
     */
    protected $spine;

    /**
     * Seconds the drive loop tolerates no version progress (a lease held by
     * another process) before giving up. Injectable so tests need no wait.
     *
     * @var int
     */
    protected $idle_giveup_seconds;

    /**
     * Constructor.
     *
     * @param MainWP_Child_Restore_Task_Runner $runner              Restore runner.
     * @param MainWP_Child_Backup_Task         $spine               Task spine.
     * @param int                              $idle_giveup_seconds Idle tolerance.
     */
    public function __construct( $runner, $spine, $idle_giveup_seconds = 180 ) {
        $this->runner              = $runner;
        $this->spine               = $spine;
        $this->idle_giveup_seconds = (int) $idle_giveup_seconds;
    }

    /**
     * Restore a local archive, end to end.
     *
     * @param string        $archive   Archive basename in the backup directory.
     * @param string        $sub_type  One of full, db, files.
     * @param bool          $confirmed Operator passed the destructive confirm.
     * @param callable|null $progress  Optional per-slice callback, given the task row.
     *
     * @return array {
     *     @type bool   $ok               True when the restore reached completed.
     *     @type string $phase            Final task phase.
     *     @type string $task_id          The restore task id, or ''.
     *     @type string $error            Human-readable failure message, or ''.
     *     @type string $error_code       The engine error code, or ''.
     *     @type bool   $maintenance_left The site was left in maintenance mode.
     * }
     */
    public function run( $archive, $sub_type, $confirmed, $progress = null ) {
        if ( ! in_array( $sub_type, array( 'full', 'db', 'files' ), true ) ) {
            return $this->fail( 'Unknown restore type "' . $sub_type . '". Use full, db, or files.' );
        }

        // The engine has no confirm gate; that is a dashboard UI concern. A
        // database-bearing restore (anything but files) must be confirmed
        // here so a CLI run cannot silently overwrite a live database.
        if ( 'files' !== $sub_type && ! $confirmed ) {
            return $this->fail( 'A ' . $sub_type . ' restore overwrites the live database. Re-run with --yes to confirm.' );
        }

        $path = MainWP_Helper::locate_backup_file( $archive );
        if ( false === $path ) {
            return $this->fail( 'Archive not found in the backup directory: ' . $archive );
        }

        if ( ! $this->archive_is_readable( $path, $archive ) ) {
            return $this->fail( 'The archive is not a readable backup. It may be corrupt, or an encrypted offsite copy, which CLI restore cannot decrypt yet.' );
        }

        $params = array(
            'restore_id'       => $this->new_restore_id(),
            'source_prefix'    => '',
            'artifacts'        => $this->build_artifacts( $path, $archive, $sub_type ),
            'has_foreign_keys' => null,
            'file_rollback'    => false,
        );

        try {
            $task_id = $this->runner->create_restore_task( $sub_type, $params );
        } catch ( MainWP_Exception $e ) {
            return $this->fail( $e->getMessage(), $e->get_message_error_code() );
        }

        $task = $this->spine->get_task( $task_id );
        if ( null === $task ) {
            return $this->fail( 'The restore task could not be read back after creation.' );
        }

        $task = $this->drive_to_terminal( $task, $progress );

        if ( null === $task ) {
            return $this->fail( 'The restore task vanished mid-run.' );
        }

        if ( MainWP_Child_Backup_Task::PHASE_COMPLETED === $task->phase ) {
            return array(
                'ok'               => true,
                'phase'            => $task->phase,
                'task_id'          => $task_id,
                'error'            => '',
                'error_code'       => '',
                'maintenance_left' => false,
            );
        }

        $error = $this->read_error( $task );
        return array(
            'ok'               => false,
            'phase'            => $task->phase,
            'task_id'          => $task_id,
            'error'            => '' !== $error['message'] ? $error['message'] : 'The restore failed.',
            'error_code'       => $error['code'],
            'maintenance_left' => $this->maintenance_active(),
        );
    }

    /**
     * Build the single-artifact list for a local archive. A full backup is
     * one archive with the database dump bundled inside it, so one entry
     * suffices; the role rides as full for file-bearing types and db for a
     * database-only archive.
     *
     * @param string $path     Absolute archive path.
     * @param string $basename Archive basename as the engine adopts it.
     * @param string $sub_type One of full, db, files.
     *
     * @return array[] One artifact entry.
     */
    public function build_artifacts( $path, $basename, $sub_type ) {
        $size = @filesize( $path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors -- size is advisory for the deadline.
        return array(
            array(
                'role'       => 'db' === $sub_type ? 'db' : 'full',
                'file'       => $basename,
                'size'       => false === $size ? 0 : (int) $size,
                'sha256'     => (string) hash_file( 'sha256', $path ),
                'adopt_only' => false,
            ),
        );
    }

    /**
     * Drive a task to a terminal phase by repeated slices. Progress is keyed
     * on the task version, not the phase: a long single phase yields across
     * slices with the phase unchanged, so version is the true work signal. No
     * version movement for idle_giveup_seconds means another process holds the
     * lease, and the loop gives up rather than spinning forever.
     *
     * @param object        $task     Starting task row.
     * @param callable|null $progress Optional callback given each task row.
     *
     * @return object|null Final task row, or null if it vanished.
     */
    public function drive_to_terminal( $task, $progress = null ) {
        $last_version = (int) $task->version;
        $idle_since   = null;

        while ( null !== $task && ! MainWP_Child_Backup_Task::is_terminal_phase( $task->phase ) ) {
            $task = $this->runner->advance_one_slice( $task, self::LEASE_OWNER );
            if ( null === $task ) {
                break;
            }
            if ( null !== $progress ) {
                call_user_func( $progress, $task );
            }

            if ( (int) $task->version !== $last_version ) {
                $last_version = (int) $task->version;
                $idle_since   = null;
                continue;
            }

            if ( null === $idle_since ) {
                $idle_since = time();
            }
            if ( time() - $idle_since >= $this->idle_giveup_seconds ) {
                break;
            }
            usleep( 500000 );
        }

        return $task;
    }

    /**
     * Whether an archive opens in the format its extension claims. A plaintext
     * tar.gz must never read as encrypted, so each extension is checked with
     * its own opener rather than assuming zip.
     *
     * @param string $path     Absolute archive path.
     * @param string $basename Archive basename (carries the extension).
     *
     * @return bool
     */
    protected function archive_is_readable( $path, $basename ) {
        if ( ! is_readable( $path ) ) {
            return false;
        }
        $lower = strtolower( $basename );

        if ( $this->ends_with( $lower, '.zip' ) ) {
            if ( ! class_exists( '\ZipArchive' ) ) {
                return true; // No verifier available; let the engine judge it.
            }
            $zip = new \ZipArchive();
            $ok  = ( true === $zip->open( $path ) );
            if ( $ok ) {
                $zip->close();
            }
            return $ok;
        }
        if ( $this->ends_with( $lower, '.tar.gz' ) ) {
            return "\x1f\x8b" === $this->read_magic( $path, 2 );
        }
        if ( $this->ends_with( $lower, '.tar.bz2' ) ) {
            return 'BZh' === $this->read_magic( $path, 3 );
        }
        if ( $this->ends_with( $lower, '.tar' ) ) {
            return 'ustar' === substr( (string) $this->read_magic_at( $path, 257, 5 ), 0, 5 );
        }

        return false; // Unknown extension: refuse rather than feed the engine garbage.
    }

    /**
     * Read the first $length bytes of a file.
     *
     * @param string $path   File path.
     * @param int    $length Byte count.
     *
     * @return string
     */
    protected function read_magic( $path, $length ) {
        return $this->read_magic_at( $path, 0, $length );
    }

    /**
     * Read $length bytes at $offset from a file.
     *
     * @param string $path   File path.
     * @param int    $offset Byte offset.
     * @param int    $length Byte count.
     *
     * @return string
     */
    protected function read_magic_at( $path, $offset, $length ) {
        $handle = @fopen( $path, 'rb' ); // phpcs:ignore WordPress.WP.AlternativeFunctions, WordPress.PHP.NoSilencedErrors -- raw magic-byte probe.
        if ( false === $handle ) {
            return '';
        }
        if ( $offset > 0 ) {
            fseek( $handle, $offset );
        }
        $bytes = (string) fread( $handle, $length ); // phpcs:ignore WordPress.WP.AlternativeFunctions -- raw magic-byte probe; WP_Filesystem has no partial read.
        fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions -- raw magic-byte probe.
        return $bytes;
    }

    /**
     * Whether $haystack ends with $needle.
     *
     * @param string $haystack Subject.
     * @param string $needle   Suffix.
     *
     * @return bool
     */
    protected function ends_with( $haystack, $needle ) {
        $len = strlen( $needle );
        return 0 === $len || substr( $haystack, -$len ) === $needle;
    }

    /**
     * Read the error message and code from a failed task row.
     *
     * @param object $task Task row.
     *
     * @return array { @type string $message, @type string $code }
     */
    protected function read_error( $task ) {
        $decoded = json_decode( (string) $task->sub_state, true );
        $message = '';
        $code    = '';
        if ( is_array( $decoded ) && isset( $decoded['error'] ) && is_array( $decoded['error'] ) ) {
            $message = isset( $decoded['error']['message'] ) ? (string) $decoded['error']['message'] : '';
            $code    = isset( $decoded['error']['code'] ) ? (string) $decoded['error']['code'] : '';
        }
        return array(
            'message' => $message,
            'code'    => $code,
        );
    }

    /**
     * Whether the site is currently in maintenance mode.
     *
     * @return bool
     */
    protected function maintenance_active() {
        return file_exists( ABSPATH . '.maintenance' );
    }

    /**
     * A fresh restore id matching the engine's [a-z0-9]{6,16} contract.
     *
     * @return string
     */
    protected function new_restore_id() {
        return substr( md5( uniqid( '', true ) ), 0, 12 );
    }

    /**
     * Shape a failure result.
     *
     * @param string $message Human-readable message.
     * @param string $code    Optional error code.
     *
     * @return array
     */
    protected function fail( $message, $code = '' ) {
        return array(
            'ok'               => false,
            'phase'            => '',
            'task_id'          => '',
            'error'            => $message,
            'error_code'       => $code,
            'maintenance_left' => false,
        );
    }
}
