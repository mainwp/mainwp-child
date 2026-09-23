<?php
/**
 * Class BackupTaskInstallTest
 *
 * @package Mainwp_Child
 */

use MainWP\Child\MainWP_Backup_Error;
use MainWP\Child\MainWP_Child_Backup_Task;
use MainWP\Child\MainWP_Exception;

class BackupTaskInstallTest extends WP_UnitTestCase {

	protected $spine;

	public function set_up() {
		parent::set_up();
		global $wpdb;
		$this->spine = MainWP_Child_Backup_Task::instance();
		remove_filter( 'query', array( $this, '_create_temporary_tables' ) );
		remove_filter( 'query', array( $this, '_drop_temporary_tables' ) );
		delete_option( MainWP_Child_Backup_Task::DB_VERSION_OPTION );
		$wpdb->query( 'DROP TABLE IF EXISTS ' . $this->spine->get_table_name() );
	}

	public function tear_down() {
		global $wpdb;
		parent::tear_down();
		remove_filter( 'query', array( $this, '_create_temporary_tables' ) );
		remove_filter( 'query', array( $this, '_drop_temporary_tables' ) );
		delete_option( MainWP_Child_Backup_Task::DB_VERSION_OPTION );
		$wpdb->query( 'DROP TABLE IF EXISTS ' . $this->spine->get_table_name() );
	}

	protected function create_old_schema() {
		global $wpdb;
		$table = $this->spine->get_table_name();
		$charset_collate = $wpdb->get_charset_collate();
		$this->assertNotFalse( $wpdb->query( "CREATE TABLE {$table} (
			task_id varchar(64) NOT NULL,
			phase varchar(20) NOT NULL DEFAULT 'queued',
			type varchar(20) NOT NULL DEFAULT 'full',
			sub_state longtext,
			started_at datetime DEFAULT NULL,
			last_progress_at datetime DEFAULT NULL,
			max_wall_clock_at datetime DEFAULT NULL,
			resume_count int(11) NOT NULL DEFAULT 0,
			max_resumes int(11) NOT NULL DEFAULT 6,
			lease_owner varchar(64) DEFAULT NULL,
			lease_expires_at datetime DEFAULT NULL,
			version bigint(20) unsigned NOT NULL DEFAULT 0,
			PRIMARY KEY (task_id),
			KEY phase (phase)
		) {$charset_collate}" ) );
		update_option( MainWP_Child_Backup_Task::DB_VERSION_OPTION, '1.0.0' );
	}

	protected function assert_active_key() {
		global $wpdb;
		$table = $this->spine->get_table_name();
		$column = $wpdb->get_row( "SHOW COLUMNS FROM {$table} LIKE 'active_slot'" );
		$this->assertNotNull( $column );
		$this->assertSame( 'YES', $column->Null );
		$this->assertNull( $column->Default );
		$this->assertStringContainsString( 'unsigned', $column->Type );
		$index = $wpdb->get_results( "SHOW INDEX FROM {$table} WHERE Key_name = 'uniq_active_task'" );
		$this->assertCount( 1, $index );
		$this->assertSame( 'active_slot', $index[0]->Column_name );
		$this->assertSame( 0, (int) $index[0]->Non_unique );
		$this->assertSame( 1, (int) $index[0]->Seq_in_index );
		$this->assertSame( '', $wpdb->last_error );
		$this->assertSame( MainWP_Child_Backup_Task::DB_VERSION, get_option( MainWP_Child_Backup_Task::DB_VERSION_OPTION ) );
	}

	protected function b14_create_110_schema() {
		global $wpdb;
		$this->create_old_schema();
		$table = $this->spine->get_table_name();
		$this->assertNotFalse( $wpdb->query( "ALTER TABLE {$table} ADD COLUMN active_slot tinyint unsigned DEFAULT NULL, ADD UNIQUE KEY uniq_active_task (active_slot)" ) );
		update_option( MainWP_Child_Backup_Task::DB_VERSION_OPTION, '1.1.0' );
	}

	protected function b14_assert_restore_key() {
		global $wpdb;
		$table = $this->spine->get_table_name();
		$column = $wpdb->get_row( "SHOW COLUMNS FROM {$table} LIKE 'restore_id'" );
		$this->assertNotNull( $column );
		$this->assertSame( 'varchar(64)', $column->Type );
		$this->assertSame( 'YES', $column->Null );
		$this->assertNull( $column->Default );
		$index = $wpdb->get_results( "SHOW INDEX FROM {$table} WHERE Key_name = 'uniq_restore_id'" );
		$this->assertCount( 1, $index );
		$this->assertSame( 'restore_id', $index[0]->Column_name );
		$this->assertSame( 0, (int) $index[0]->Non_unique );
		$this->assertNull( $index[0]->Sub_part );
		$this->assertSame( '1.2.0', get_option( MainWP_Child_Backup_Task::DB_VERSION_OPTION ) );
		$this->assert_active_key();
	}

	/** @test */
	public function b14_upgrade_110_backfills_only_unambiguous_restore_ids() {
		global $wpdb;
		$this->b14_create_110_schema();
		$table = $this->spine->get_table_name();
		foreach ( array( 'unique' => 'b14unique', 'duplicate1' => 'b14shared', 'duplicate2' => 'b14shared', 'backup' => null ) as $task_id => $restore_id ) {
			$this->assertSame( 1, $wpdb->insert( $table, array(
				'task_id' => $task_id,
				'phase' => 'completed',
				'type' => null === $restore_id ? 'full' : 'restore_db',
				'sub_state' => wp_json_encode( array( 'params' => array( 'restore_id' => $restore_id ) ) ),
			) ) );
		}
		$this->spine->install();
		$this->b14_assert_restore_key();
		$this->assertSame( 'b14unique', $this->spine->get_task( 'unique' )->restore_id );
		foreach ( array( 'duplicate1', 'duplicate2', 'backup' ) as $task_id ) {
			$this->assertNull( $this->spine->get_task( $task_id )->restore_id );
		}
		$this->assertSame( 4, (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" ) );
		$this->spine->install();
		$this->assertSame( 'unique', $this->spine->get_task_by_restore_id( 'b14unique' )->task_id );
	}

	/** @test */
	public function b14_repair_readds_column_and_key_after_dbdelta_alters_do_not_take() {
		global $wpdb;
		$this->b14_create_110_schema();
		$table = $this->spine->get_table_name();
		$blocked = array( 'column' => false, 'key' => false );
		$block = static function ( $query ) use ( $table, &$blocked ) {
			if ( 0 === strpos( $query, "ALTER TABLE {$table} ADD" ) ) {
				foreach ( array( 'column' => 'ADD COLUMN restore_id', 'key' => 'ADD UNIQUE KEY' ) as $kind => $needle ) {
					if ( ! $blocked[ $kind ] && false !== strpos( $query, $needle ) && false !== strpos( $query, 'restore_id' ) ) {
						$blocked[ $kind ] = true;
						return 'SELECT 1';
					}
				}
			}
			return $query;
		};
		add_filter( 'query', $block );
		try {
			$this->spine->install();
		} finally {
			remove_filter( 'query', $block );
		}
		$this->assertSame( array( 'column' => true, 'key' => true ), $blocked );
		$this->b14_assert_restore_key();
	}

	/** @test */
	public function b14_failed_restore_key_alter_keeps_upgrade_pending() {
		global $wpdb;
		$this->b14_create_110_schema();
		$table = $this->spine->get_table_name();
		$block = static function ( $query ) use ( $table ) {
			return 0 === strpos( $query, "ALTER TABLE {$table} ADD UNIQUE KEY" ) && false !== strpos( $query, 'uniq_restore_id' ) ? 'SELECT 1' : $query;
		};
		add_filter( 'query', $block );
		try {
			$this->spine->install();
			$this->assertSame( '1.1.0', get_option( MainWP_Child_Backup_Task::DB_VERSION_OPTION ) );
			$this->assertFalse( $this->spine->create_task( 'restore_db', array( 'params' => array( 'restore_id' => 'b14blocked' ) ) ) );
		} finally {
			remove_filter( 'query', $block );
		}
		$this->spine->install();
		$this->b14_assert_restore_key();
	}

	public function test_fresh_install_enforces_one_active_task() {
		$this->spine->install();
		$this->assert_active_key();
		foreach ( array( MainWP_Child_Backup_Task::PHASE_COMPLETED, MainWP_Child_Backup_Task::PHASE_FAILED ) as $terminal ) {
			$task_id = $this->spine->create_task( 'full' );
			$this->assertIsString( $task_id );
			$this->assertSame( 1, (int) $this->spine->get_task( $task_id )->active_slot );
			$this->assertNull( $this->spine->create_task( 'restore', array(), null, null, null, true ) );
			try {
				$this->spine->create_task( 'restore' );
				$this->fail( 'The unique slot must reject a second non-terminal task.' );
			} catch ( MainWP_Exception $error ) {
				$this->assertSame( MainWP_Backup_Error::ERR_ALREADY_RUNNING, $error->get_message_error_code() );
				$this->assertSame( MainWP_Backup_Error::message( MainWP_Backup_Error::ERR_ALREADY_RUNNING ), $error->getMessage() );
			}
			$this->assertSame( 1, $this->spine->checkpoint( $task_id, 0, array( 'phase' => $terminal ), false ) );
			$this->assertNull( $this->spine->get_task( $task_id )->active_slot );
		}
		$this->assertIsString( $this->spine->create_task( 'restore' ) );
	}

	public function test_old_schema_backfills_and_retries_failed_key_alters() {
		global $wpdb;
		$this->create_old_schema();
		$table = $this->spine->get_table_name();
		$this->assertSame( 1, $wpdb->insert( $table, array( 'task_id' => 'queued', 'phase' => 'queued' ) ) );
		$this->assertSame( 1, $wpdb->insert( $table, array( 'task_id' => 'completed', 'phase' => 'completed' ) ) );
		$block = static function ( $query ) use ( $table ) {
			if ( 0 === strpos( $query, "ALTER TABLE {$table} ADD UNIQUE KEY" ) && false !== strpos( $query, 'uniq_active_task' ) ) {
				return 'SELECT 1';
			}
			return $query;
		};
		add_filter( 'query', $block );
		try {
			$this->spine->install();
			$this->assertSame( '1.0.0', get_option( MainWP_Child_Backup_Task::DB_VERSION_OPTION ) );
			$this->assertEmpty( $wpdb->get_results( "SHOW INDEX FROM {$table} WHERE Key_name = 'uniq_active_task'" ) );
		} finally {
			remove_filter( 'query', $block );
		}
		$this->spine->install();
		$this->assert_active_key();
		$this->assertSame( 1, (int) $this->spine->get_task( 'queued' )->active_slot );
		$this->assertNull( $this->spine->get_task( 'completed' )->active_slot );
	}

	public function test_repaired_install_accepts_existing_key_and_is_a_no_op() {
		global $wpdb;
		$this->create_old_schema();
		$this->spine->install();
		$this->assert_active_key();
		$table = $this->spine->get_table_name();
		$probes = 0;
		$adds = 0;
		$race = static function ( $query ) use ( $table, &$probes, &$adds ) {
			if ( 0 === strpos( $query, "SHOW INDEX FROM {$table} WHERE Key_name = 'uniq_active_task'" ) && 1 === ++$probes ) {
				return "SHOW INDEX FROM {$table} WHERE Key_name = 'missing_fixture_key'";
			}
			if ( 0 === strpos( $query, "ALTER TABLE {$table} ADD UNIQUE KEY uniq_active_task" ) ) {
				++$adds;
			}
			return $query;
		};
		update_option( MainWP_Child_Backup_Task::DB_VERSION_OPTION, '1.0.0' );
		add_filter( 'query', $race );
		try {
			$this->spine->install();
			$this->assertSame( 1, $adds );
			$this->assertSame( 2, $probes );
			$this->assertSame( '', $wpdb->last_error );
		} finally {
			remove_filter( 'query', $race );
		}
		$this->assert_active_key();
		$queries = $wpdb->num_queries;
		$this->spine->install();
		$this->spine->install();
		$this->assertSame( $queries, $wpdb->num_queries );
		$this->assertSame( '', $wpdb->last_error );
	}

	public static function duplicate_repair_key_timings() {
		return array( 'key before backfill' => array( false ), 'key after backfill' => array( true ) );
	}

	/** @dataProvider duplicate_repair_key_timings */
	public function test_old_schema_duplicate_tasks_keep_the_newest_active( $defer_key ) {
		global $wpdb;
		$this->create_old_schema();
		$table = $this->spine->get_table_name();
		$this->assertSame( 1, $wpdb->insert( $table, array( 'task_id' => 'older', 'phase' => 'queued', 'started_at' => '2026-01-01 00:00:00' ) ) );
		$this->assertSame( 1, $wpdb->insert( $table, array( 'task_id' => 'newer', 'phase' => 'queued', 'started_at' => '2026-01-01 00:01:00' ) ) );
		$adds = 0;
		$defer = static function ( $query ) use ( $table, $defer_key, &$adds ) {
			if ( $defer_key && 0 === strpos( $query, "ALTER TABLE {$table} ADD UNIQUE KEY" ) && false !== strpos( $query, 'uniq_active_task' ) && 1 === ++$adds ) {
				return 'SELECT 1';
			}
			return $query;
		};
		add_filter( 'query', $defer );
		try {
			$this->spine->install();
		} finally {
			remove_filter( 'query', $defer );
		}
		$this->assert_active_key();
		$older = $this->spine->get_task( 'older' );
		$newer = $this->spine->get_task( 'newer' );
		$this->assertSame( MainWP_Child_Backup_Task::PHASE_FAILED, $older->phase );
		$this->assertNull( $older->active_slot );
		$this->assertSame( 1, (int) $older->version );
		$error = json_decode( $older->sub_state, true )['error'];
		$this->assertSame( MainWP_Backup_Error::ERR_ALREADY_RUNNING, $error['code'] );
		$this->assertStringContainsString( 'a newer task remains active', $error['message'] );
		$this->assertSame( MainWP_Child_Backup_Task::PHASE_QUEUED, $newer->phase );
		$this->assertSame( 1, (int) $newer->active_slot );
		$this->assertCount( 1, $this->spine->get_active_tasks() );
	}
}
