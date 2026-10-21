<?php
/**
 * cPanel downloads must survive shell and filesystem protections.
 *
 * @package MainWP_Child
 */

use MainWP\Child\MainWP_Child_Api_Backups;

class ApiBackupsMysqldumpTest extends WP_UnitTestCase {

	private $root;
	private $runner;
	private $old_path;

	public function set_up() {
		parent::set_up();
		$this->root = sys_get_temp_dir() . '/mainwp-api test-\'$(printf expanded)`printf expanded`;' . bin2hex( random_bytes( 8 ) );
		mkdir( $this->root );
		mkdir( $this->root . '/bin' );
		file_put_contents( $this->root . '/bin/mysqldump', "#!/bin/sh\nprintf '%s\\n' \"\$@\"\n" );
		chmod( $this->root . '/bin/mysqldump', 0700 );
		$this->old_path = getenv( 'PATH' );
		putenv( 'PATH=' . $this->root . '/bin:' . $this->old_path );
		$this->runner = new ApiBackupsMysqldumpRunner();
	}

	public function tear_down() {
		putenv( 'PATH=' . $this->old_path );
		if ( $this->runner->defaults_path && file_exists( $this->runner->defaults_path ) ) {
			unlink( $this->runner->defaults_path );
		}
		$files = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $this->root, FilesystemIterator::SKIP_DOTS ), RecursiveIteratorIterator::CHILD_FIRST );
		foreach ( $files as $file ) {
			$file->isDir() ? rmdir( $file->getPathname() ) : unlink( $file->getPathname() );
		}
		rmdir( $this->root );
		parent::tear_down();
	}

	private function run_dump( $database = 'database', $password = 'private-password', $host = 'localhost', $user = 'db-user' ) {
		$this->assertTrue( method_exists( $this->runner, 'run_mysqldump' ), 'The dump lifecycle needs an isolated entry point.' );
		$method = new ReflectionMethod( $this->runner, 'run_mysqldump' );
		$method->setAccessible( true );
		return $method->invoke( $this->runner, $database, $user, $password, $host, $this->root . '/dumps/' );
	}

	private function dump_arguments() {
		$files = glob( $this->root . '/dumps/*.sql.gz' );
		$this->assertCount( 1, $files );
		return explode( "\n", trim( gzdecode( file_get_contents( $files[0] ) ) ) );
	}

	public function test_password_is_absent_from_command() {
		$this->assertSame( 'GOOD', $this->run_dump()['result'] );
		$this->assertStringNotContainsString( 'private-password', $this->runner->command );
		$this->assertStringNotContainsString( '--password', $this->runner->command );
		$this->assertStringStartsWith( '--defaults-extra-file=', $this->dump_arguments()[0] );
		$this->assertStringContainsString( 'password="private-password"', $this->runner->defaults_contents );
	}

	public function test_hostile_values_stay_inert() {
		$hostile = 'quotes\' " spaces $(printf EXPANDED) `printf EXPANDED` ;';
		$this->assertSame( 'GOOD', $this->run_dump( $hostile, $hostile . '\\end', $hostile . ':3306', $hostile )['result'] );
		$args = $this->dump_arguments();
		$this->assertContains( '--host=' . $hostile, $args );
		$this->assertSame( $hostile, end( $args ) );
		$this->assertSame( '[client]' . "\n" . 'user="quotes\' \\" spaces $(printf EXPANDED) `printf EXPANDED` ;"' . "\n" . 'password="quotes\' \\" spaces $(printf EXPANDED) `printf EXPANDED` ;\\\\end"' . "\n", $this->runner->defaults_contents );
	}

	public function test_ip_host_keeps_digits_and_port() {
		$this->assertSame( 'GOOD', $this->run_dump( 'database', 'password', '127.0.0.1:3306' )['result'] );
		$this->assertContains( '--host=127.0.0.1', $this->dump_arguments() );
		$this->assertContains( '--port=3306', $this->dump_arguments() );
	}

	public function test_socket_host_is_preserved() {
		$this->assertSame( 'GOOD', $this->run_dump( 'database', 'password', 'localhost:/path with spaces/mysql.sock' )['result'] );
		$this->assertContains( '--host=localhost', $this->dump_arguments() );
		$this->assertContains( '--socket=/path with spaces/mysql.sock', $this->dump_arguments() );
	}

	public function test_ipv6_host_is_preserved() {
		$this->assertSame( 'GOOD', $this->run_dump( 'database', 'password', '[::1]:3306' )['result'] );
		$this->assertContains( '--host=::1', $this->dump_arguments() );
		$this->assertContains( '--port=3306', $this->dump_arguments() );
	}

	public function test_remaining_host_forms_are_preserved() {
		foreach ( array( 'localhost', 'localhost:3307', '127.0.0.1', 'db.example.com:3306' ) as $host ) {
			$this->assertSame( 'GOOD', $this->run_dump( 'database', 'password', $host )['result'] );
			$args = $this->dump_arguments();
			$parts = explode( ':', $host );
			$this->assertContains( '--host=' . $parts[0], $args );
			if ( isset( $parts[1] ) ) {
				$this->assertContains( '--port=' . $parts[1], $args );
			}
			foreach ( glob( $this->root . '/dumps/*.sql.gz' ) as $file ) {
				unlink( $file );
			}
		}
	}

	public function test_defaults_are_private_and_removed_after_success() {
		$this->assertSame( 'GOOD', $this->run_dump()['result'] );
		$this->assertSame( 0600, $this->runner->defaults_mode );
		$this->assertSame( realpath( sys_get_temp_dir() ), dirname( $this->runner->defaults_path ) );
		$this->assertFileDoesNotExist( $this->runner->defaults_path );
	}

	public function test_defaults_are_private_and_removed_after_failed_exec() {
		$this->runner->exit_status = 9;
		$this->assertSame( 'ERROR', $this->run_dump()['result'] );
		$this->assertSame( 0600, $this->runner->defaults_mode );
		$this->assertFileDoesNotExist( $this->runner->defaults_path );
		$this->assertSame( array(), glob( $this->root . '/dumps/*.sql.gz' ) );
	}

	public function test_preexisting_folder_gets_deny_files_on_every_call() {
		mkdir( $this->root . '/dumps' );
		$this->assertSame( 'GOOD', $this->run_dump()['result'] );
		$this->assertSame( '<?php // Silence is golden.', file_get_contents( $this->root . '/dumps/index.php' ) );
		$deny = file_get_contents( $this->root . '/dumps/.htaccess' );
		$this->assertStringContainsString( 'Require all denied', $deny );
		$this->assertStringContainsString( 'Deny from all', $deny );
		$this->assertStringContainsString( 'Order allow,deny', $deny );
		unlink( $this->root . '/dumps/.htaccess' );
		unlink( $this->root . '/dumps/index.php' );
		$this->assertSame( 'GOOD', $this->run_dump()['result'] );
		$this->assertFileExists( $this->root . '/dumps/.htaccess' );
		$this->assertFileExists( $this->root . '/dumps/index.php' );
	}

	public function test_dump_name_has_random_suffix_and_gzip_extension() {
		$this->assertSame( 'GOOD', $this->run_dump()['result'] );
		$this->assertSame( 'GOOD', $this->run_dump()['result'] );
		$files = glob( $this->root . '/dumps/*.sql.gz' );
		$this->assertCount( 2, $files );
		foreach ( $files as $file ) {
			$this->assertMatchesRegularExpression( '/_[a-f0-9]{32}\.sql\.gz$/D', basename( $file ) );
			$this->assertNotEmpty( gzdecode( file_get_contents( $file ) ) );
		}
	}

	public function test_mysqldump_failure_reports_error_and_removes_partial() {
		file_put_contents( $this->root . '/bin/mysqldump', "#!/bin/sh\nprintf partial\nexit 7\n" );
		$result = $this->run_dump();
		$this->assertSame( 'ERROR', $result['result'] );
		$this->assertNotSame( 0, $result['res'] );
		$this->assertSame( array(), $result['output'] );
		$this->assertSame( array(), glob( $this->root . '/dumps/*.sql.gz' ) );
	}

	public function test_existing_dumps_survive_success_and_failure() {
		mkdir( $this->root . '/dumps' );
		$old = $this->root . '/dumps/existing.sql.gz';
		file_put_contents( $old, 'existing dump' );
		$this->assertSame( 'GOOD', $this->run_dump()['result'] );
		$this->runner->exit_status = 1;
		$this->assertSame( 'ERROR', $this->run_dump()['result'] );
		$this->assertSame( 'existing dump', file_get_contents( $old ) );
		$this->assertCount( 2, glob( $this->root . '/dumps/*.sql.gz' ) );
	}

	public function test_exception_cleans_up_without_exposing_details() {
		$this->runner->throw = true;
		$result = $this->run_dump();
		$this->assertSame( array( 'result' => 'ERROR', 'output' => array(), 'res' => 1 ), $result );
		$this->assertFileDoesNotExist( $this->runner->defaults_path );
		$this->assertSame( array(), glob( $this->root . '/dumps/*.sql.gz' ) );
	}

	public function test_option_file_rejects_line_breaks_and_nul() {
		foreach ( array( "bad\nvalue", "bad\rvalue", "bad\0value" ) as $value ) {
			$this->assertSame( 'ERROR', $this->run_dump( 'database', $value )['result'] );
			$this->assertSame( 'ERROR', $this->run_dump( 'database', 'password', 'localhost', $value )['result'] );
		}
		$this->assertSame( '', $this->runner->command );
	}

	public function test_unparseable_host_is_refused() {
		$this->assertSame( 'ERROR', $this->run_dump( 'database', 'password', 'not:an:ipv6' )['result'] );
		$this->assertSame( '', $this->runner->command );
	}

	public function test_gzip_failure_reports_error() {
		file_put_contents( $this->root . '/bin/gzip', "#!/bin/sh\ncat >/dev/null\nexit 8\n" );
		chmod( $this->root . '/bin/gzip', 0700 );
		$this->assertSame( 'ERROR', $this->run_dump()['result'] );
		$this->assertSame( array(), glob( $this->root . '/dumps/*.sql.gz' ) );
	}

	public function test_dash_detects_failed_dump() {
		if ( ! is_executable( '/bin/dash' ) ) {
			$this->markTestSkipped( 'dash is unavailable.' );
		}
		$this->runner->shell = '/bin/dash';
		file_put_contents( $this->root . '/bin/mysqldump', "#!/bin/sh\nprintf partial\nexit 7\n" );
		$this->assertSame( 'ERROR', $this->run_dump()['result'] );
		$this->assertSame( array(), glob( $this->root . '/dumps/*.sql.gz' ) );
	}

	public function test_real_mysqldump_produces_nonempty_gzip() {
		putenv( 'PATH=' . $this->old_path );
		exec( 'command -v mysqldump', $paths, $status );
		if ( 0 !== $status ) {
			$this->markTestSkipped( 'mysqldump is unavailable.' );
		}
		$result = $this->run_dump( DB_NAME, DB_PASSWORD, DB_HOST, DB_USER );
		$this->assertSame( array( 'result' => 'GOOD', 'output' => array(), 'res' => 0 ), $result );
		$files = glob( $this->root . '/dumps/*.sql.gz' );
		$this->assertCount( 1, $files );
		$sql = gzdecode( file_get_contents( $files[0] ) );
		$this->assertNotFalse( $sql );
		$this->assertGreaterThan( 0, strlen( $sql ) );
		$this->assertTrue( false !== strpos( $sql, 'CREATE TABLE' ), 'The real export must contain the test schema.' );
		$this->assertFileDoesNotExist( $this->runner->defaults_path );
	}
}

class ApiBackupsMysqldumpRunner extends MainWP_Child_Api_Backups {
	public $command = '';
	public $defaults_path = '';
	public $defaults_contents;
	public $defaults_mode;
	public $exit_status;
	public $throw = false;
	public $shell;

	protected function create_dump_defaults( $user, $password ) {
		$this->defaults_path = parent::create_dump_defaults( $user, $password );
		return $this->defaults_path;
	}

	protected function execute_dump_command( $command ) {
		$this->command = $command;
		clearstatcache();
		$this->defaults_mode = fileperms( $this->defaults_path ) & 0777;
		$this->defaults_contents = file_get_contents( $this->defaults_path );
		if ( $this->throw ) {
			throw new RuntimeException( 'private-password ' . $this->defaults_path . ' ' . $this->defaults_contents );
		}
		if ( null !== $this->exit_status ) {
			return $this->exit_status;
		}
		if ( $this->shell ) {
			$command = escapeshellarg( $this->shell ) . ' -c ' . escapeshellarg( $command );
		}
		return parent::execute_dump_command( $command );
	}
}
