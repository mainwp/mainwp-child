<?php
/**
 * Class BackupIncrementalDiffTest
 *
 * @package Mainwp_Child
 */

use MainWP\Child\MainWP_Child_Backup_Incremental_Diff;

/**
 * Tests for the incremental diff classifier.
 *
 * The diff compares the current walk's files.list against the parent
 * snapshot's files.list on size and mtime only - file contents are never
 * read or hashed. Changed and new files stream to changes.list, parent
 * entries missing from the current walk stream to tombstones.list, and a
 * parent list that should have entries but parses empty forces a full
 * backup instead of producing a silently corrupt incremental.
 */
class BackupIncrementalDiffTest extends WP_UnitTestCase {

	public static function unreadable_diff_cases() {
		return array(
			'prefixes' => array( array( 'a/b', 'Uploads/Locked' ), false, 3, array( array( 'a/bc/x' ), array( 'outside.txt' ) ) ),
			'root failure' => array( array(), true, 5, array() ),
			'overflow' => array( array( 'a/b' ), true, 5, array() ),
			'invalid relative coverage' => array( array(), true, 5, array() ),
			'pre-change snapshot' => array( array(), false, 0, array( array( 'a/b' ), array( 'a/b/x' ), array( 'a/bc/x' ), array( 'outside.txt' ), array( 'uploads/locked/f.txt' ) ) ),
		);
	}

	/** @dataProvider unreadable_diff_cases */
	public function test_unreadable_coverage_diff_algorithms_agree( $prefixes, $all, $carried, $tombstones ) {
		$parent = $this->write_list( 'parent.list', array(
			array( 'a/b', 1, 1 ), array( 'a/b/x', 2, 2 ), array( 'a/bc/x', 3, 3 ),
			array( 'outside.txt', 4, 4 ), array( 'uploads/locked/f.txt', 5, 5 ),
		) );
		$current = $this->write_list( 'current.list', array( array( 'new.txt', 6, 6 ) ) );
		$before = file_get_contents( $current );
		$map = $this->diff->load_parent_map( $parent );
		$stream = $this->diff->stream_diff( $current, $map['map'], $this->dir . '/changes-map', $this->dir . '/tombs-map', $prefixes, $all );
		$sorted = $this->diff->sorted_merge_diff( $parent, $current, $this->dir . '/changes-sorted', $this->dir . '/tombs-sorted', $prefixes, $all );
		$this->assertTrue( $sorted['valid'] );
		unset( $sorted['valid'] );
		$this->assertSame( array( 'changes' => 1, 'tombstones' => count( $tombstones ), 'carried' => $carried ), $stream );
		$this->assertSame( $stream, $sorted );
		$this->assertSame( array( array( 'new.txt', '6', '6' ) ), $this->read_list( $this->dir . '/changes-map' ) );
		$this->assertSame( $tombstones, $this->read_list( $this->dir . '/tombs-map' ) );
		$this->assertSame( file_get_contents( $this->dir . '/changes-map' ), file_get_contents( $this->dir . '/changes-sorted' ) );
		$this->assertSame( file_get_contents( $this->dir . '/tombs-map' ), file_get_contents( $this->dir . '/tombs-sorted' ) );
		$this->assertSame( $before, file_get_contents( $current ), 'Carried parent rows never enter the current manifest.' );
	}

	public function test_unreadable_backslash_prefix_diff_algorithms_agree() {
		$parent = $this->write_list( 'parent.list', array(
			array( 'outside.txt', 1, 1 ),
			array( 'uploads/locked\\set/f.txt', 2, 2 ),
			array( 'uploads/locked\\set/g.txt', 3, 3 ),
			array( 'uploads/locked\\sets/sibling.txt', 4, 4 ),
		) );
		$current = $this->write_list( 'current.list', array( array( 'new.txt', 5, 5 ) ) );
		$prefixes = array( 'uploads/locked\\set' );
		$map = $this->diff->load_parent_map( $parent );
		$stream = $this->diff->stream_diff( $current, $map['map'], $this->dir . '/changes-map', $this->dir . '/tombs-map', $prefixes );
		$sorted = $this->diff->sorted_merge_diff( $parent, $current, $this->dir . '/changes-sorted', $this->dir . '/tombs-sorted', $prefixes );
		$this->assertTrue( $sorted['valid'] );
		unset( $sorted['valid'] );
		$this->assertSame( array( 'changes' => 1, 'tombstones' => 2, 'carried' => 2 ), $stream );
		$this->assertSame( $stream, $sorted );
		$this->assertSame( array( array( 'outside.txt' ), array( 'uploads/locked\\sets/sibling.txt' ) ), $this->read_list( $this->dir . '/tombs-map' ) );
		$this->assertSame( array( array( 'new.txt', '5', '5' ) ), $this->read_list( $this->dir . '/changes-map' ) );
		$this->assertSame( file_get_contents( $this->dir . '/changes-map' ), file_get_contents( $this->dir . '/changes-sorted' ) );
		$this->assertSame( file_get_contents( $this->dir . '/tombs-map' ), file_get_contents( $this->dir . '/tombs-sorted' ) );
	}

	public function test_unreadable_suppression_covers_current_exhausted_tail_and_still_archives_changes() {
		$parent = $this->write_list( 'parent.list', array( array( 'a.txt', 1, 1 ), array( 'locked/last.txt', 2, 2 ), array( 'z.txt', 3, 3 ) ) );
		$current = $this->write_list( 'current.list', array( array( 'a.txt', 9, 1 ) ) );
		foreach ( array( false, true ) as $all ) {
			$map = $this->diff->load_parent_map( $parent );
			$a = $this->diff->stream_diff( $current, $map['map'], $this->dir . '/changes-map', $this->dir . '/tombs-map', array( 'LOCKED' ), $all );
			$b = $this->diff->sorted_merge_diff( $parent, $current, $this->dir . '/changes-sorted', $this->dir . '/tombs-sorted', array( 'LOCKED' ), $all );
			unset( $b['valid'] );
			$this->assertSame( $a, $b );
			$this->assertSame( $all ? 2 : 1, $a['carried'] );
			$this->assertSame( $all ? '' : "z.txt\n", file_get_contents( $this->dir . '/tombs-map' ) );
			$this->assertSame( "a.txt\t9\t1\n", file_get_contents( $this->dir . '/changes-map' ) );
		}
	}

	/**
	 * Scratch dir for list fixtures.
	 *
	 * @var string
	 */
	protected $dir;

	/**
	 * Diff under test.
	 *
	 * @var MainWP_Child_Backup_Incremental_Diff
	 */
	protected $diff;

	/**
	 * Build the scratch dir.
	 */
	public function set_up() {
		parent::set_up();
		$this->dir = sys_get_temp_dir() . '/mainwp-diff-test-' . uniqid();
		mkdir( $this->dir );
		$this->diff = new MainWP_Child_Backup_Incremental_Diff();
	}

	/**
	 * Remove the scratch dir.
	 */
	public function tear_down() {
		foreach ( (array) glob( $this->dir . '/*' ) as $file ) {
			unlink( $file );
		}
		if ( is_dir( $this->dir ) ) {
			rmdir( $this->dir );
		}
		parent::tear_down();
	}

	/**
	 * Write a files.list fixture.
	 *
	 * @param string $name  File name.
	 * @param array  $rows  Rows of [relpath, size, mtime].
	 *
	 * @return string Fixture path.
	 */
	protected function write_list( $name, $rows ) {
		$lines = '';
		foreach ( $rows as $row ) {
			$lines .= implode( "\t", $row ) . "\n";
		}
		$path = $this->dir . '/' . $name;
		file_put_contents( $path, $lines ); // phpcs:ignore -- test fixture.
		return $path;
	}

	/**
	 * Read a list file back as rows of tab-split fields.
	 *
	 * @param string $path List path.
	 *
	 * @return array[] Rows.
	 */
	protected function read_list( $path ) {
		if ( ! file_exists( $path ) ) {
			return array();
		}
		$rows = array();
		foreach ( file( $path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES ) as $line ) {
			$rows[] = explode( "\t", $line );
		}
		return $rows;
	}

	/**
	 * A well-formed parent list loads into a relpath map with a count.
	 */
	public function test_load_parent_map_parses_entries() {
		$path = $this->write_list(
			'parent.list',
			array(
				array( 'a.php', 10, 100 ),
				array( 'b/c.php', 20, 200 ),
			)
		);

		$result = $this->diff->load_parent_map( $path );

		$this->assertTrue( $result['valid'] );
		$this->assertSame( 2, $result['count'] );
		$this->assertArrayHasKey( 'a.php', $result['map'] );
		$this->assertArrayHasKey( 'b/c.php', $result['map'] );
	}

	/**
	 * A malformed line marks the whole parent list corrupt - a partial
	 * parent map would classify untracked files as new and silently lose
	 * deletions.
	 */
	public function test_load_parent_map_flags_corrupt_lines() {
		$path = $this->dir . '/corrupt.list';
		file_put_contents( $path, "a.php\t10\t100\nnot-a-valid-line\n" ); // phpcs:ignore -- test fixture.

		$result = $this->diff->load_parent_map( $path );

		$this->assertFalse( $result['valid'] );
	}

	/**
	 * Non-numeric size or mtime is also corrupt.
	 */
	public function test_load_parent_map_flags_non_numeric_fields() {
		$path = $this->write_list( 'badnum.list', array( array( 'a.php', 'big', 'old' ) ) );

		$result = $this->diff->load_parent_map( $path );

		$this->assertFalse( $result['valid'] );
	}

	/**
	 * A missing parent list is invalid.
	 */
	public function test_load_parent_map_missing_file_invalid() {
		$result = $this->diff->load_parent_map( $this->dir . '/never-written.list' );

		$this->assertFalse( $result['valid'] );
		$this->assertSame( 0, $result['count'] );
	}

	/**
	 * Classification: absent from the parent map is new, a size or mtime
	 * difference is changed, identical is carried forward.
	 */
	public function test_classify() {
		$map = array( 'a.php' => "10\t100" );

		$this->assertSame( 'new', $this->diff->classify( 'b.php', 10, 100, $map ) );
		$this->assertSame( 'changed', $this->diff->classify( 'a.php', 11, 100, $map ) );
		$this->assertSame( 'changed', $this->diff->classify( 'a.php', 10, 101, $map ) );
		$this->assertSame( 'carry', $this->diff->classify( 'a.php', 10, 100, $map ) );
	}

	/**
	 * The streamed diff sends new and changed files to changes.list with
	 * their current size and mtime, skips carried-forward files, and sends
	 * parent entries missing from the walk to tombstones.list.
	 */
	public function test_stream_diff_classifies_and_emits_tombstones() {
		$parent = $this->write_list(
			'parent.list',
			array(
				array( 'carried.php', 10, 100 ),
				array( 'changed-mtime.php', 20, 200 ),
				array( 'changed-size.php', 30, 300 ),
				array( 'deleted.php', 40, 400 ),
			)
		);
		$current = $this->write_list(
			'current.list',
			array(
				array( 'added.php', 5, 500 ),
				array( 'carried.php', 10, 100 ),
				array( 'changed-mtime.php', 20, 201 ),
				array( 'changed-size.php', 31, 300 ),
			)
		);

		$loaded = $this->diff->load_parent_map( $parent );
		$result = $this->diff->stream_diff(
			$current,
			$loaded['map'],
			$this->dir . '/changes.list',
			$this->dir . '/tombstones.list'
		);

		$this->assertSame( 3, $result['changes'] );
		$this->assertSame( 1, $result['tombstones'] );
		$this->assertSame( 1, $result['carried'] );

		$changes = $this->read_list( $this->dir . '/changes.list' );
		$changed_paths = array_column( $changes, 0 );
		$this->assertContains( 'added.php', $changed_paths );
		$this->assertContains( 'changed-mtime.php', $changed_paths );
		$this->assertContains( 'changed-size.php', $changed_paths );
		$this->assertNotContains( 'carried.php', $changed_paths );

		foreach ( $changes as $row ) {
			$this->assertCount( 3, $row, 'changes.list keeps the files.list relpath/size/mtime format.' );
		}

		$tombstones = $this->read_list( $this->dir . '/tombstones.list' );
		$this->assertSame( array( array( 'deleted.php' ) ), $tombstones, 'tombstones.list is one relpath per line.' );
	}

	/**
	 * An empty parent that should have entries forces a full backup; an
	 * empty parent on a first run does not; a corrupt parent always does.
	 */
	public function test_should_force_full() {
		$valid_empty = array(
			'valid' => true,
			'count' => 0,
			'map'   => array(),
		);
		$corrupt     = array(
			'valid' => false,
			'count' => 0,
			'map'   => array(),
		);
		$populated   = array(
			'valid' => true,
			'count' => 3,
			'map'   => array(),
		);

		$this->assertTrue( $this->diff->should_force_full( $valid_empty, true ) );
		$this->assertFalse( $this->diff->should_force_full( $valid_empty, false ) );
		$this->assertTrue( $this->diff->should_force_full( $corrupt, true ) );
		$this->assertTrue( $this->diff->should_force_full( $corrupt, false ) );
		$this->assertFalse( $this->diff->should_force_full( $populated, true ) );
	}

	/**
	 * The bounded-memory sorted-merge diff produces byte-identical output
	 * to the in-memory map diff for the same sorted inputs.
	 */
	public function test_sorted_merge_diff_matches_map_diff() {
		$parent_rows = array(
			array( 'a-carried.php', 10, 100 ),
			array( 'b-changed.php', 20, 200 ),
			array( 'c-deleted.php', 30, 300 ),
			array( 'x-deleted-tail.php', 70, 700 ),
		);
		$current_rows = array(
			array( 'a-carried.php', 10, 100 ),
			array( 'b-changed.php', 21, 200 ),
			array( 'd-added.php', 40, 400 ),
			array( 'z-added-tail.php', 80, 800 ),
		);

		$parent  = $this->write_list( 'parent.list', $parent_rows );
		$current = $this->write_list( 'current.list', $current_rows );

		$loaded = $this->diff->load_parent_map( $parent );
		$this->diff->stream_diff( $current, $loaded['map'], $this->dir . '/changes-map.list', $this->dir . '/tombstones-map.list' );

		$merge = $this->diff->sorted_merge_diff( $parent, $current, $this->dir . '/changes-merge.list', $this->dir . '/tombstones-merge.list' );

		$this->assertTrue( $merge['valid'] );
		$this->assertSame( 3, $merge['changes'] );
		$this->assertSame( 2, $merge['tombstones'] );

		$this->assertSame(
			file_get_contents( $this->dir . '/changes-map.list' ),
			file_get_contents( $this->dir . '/changes-merge.list' ),
			'Both diff paths must emit identical changes.'
		);
		$this->assertSame(
			file_get_contents( $this->dir . '/tombstones-map.list' ),
			file_get_contents( $this->dir . '/tombstones-merge.list' ),
			'Both diff paths must emit identical tombstones.'
		);
	}

	/**
	 * A malformed parent line in the sorted-merge path reports invalid so
	 * the caller forces a full backup.
	 */
	public function test_sorted_merge_diff_flags_corrupt_parent() {
		$parent = $this->dir . '/corrupt.list';
		file_put_contents( $parent, "a.php\t10\t100\ngarbage\n" ); // phpcs:ignore -- test fixture.
		$current = $this->write_list( 'current.list', array( array( 'a.php', 10, 100 ) ) );

		$result = $this->diff->sorted_merge_diff( $parent, $current, $this->dir . '/changes.list', $this->dir . '/tombstones.list' );

		$this->assertFalse( $result['valid'] );
	}

	/**
	 * An empty diff (nothing changed) still writes both list files, so the
	 * pack phase and the dashboard see an explicit empty result rather
	 * than a missing artifact.
	 */
	public function test_stream_diff_with_no_changes_writes_empty_lists() {
		$rows    = array( array( 'a.php', 10, 100 ) );
		$parent  = $this->write_list( 'parent.list', $rows );
		$current = $this->write_list( 'current.list', $rows );

		$loaded = $this->diff->load_parent_map( $parent );
		$result = $this->diff->stream_diff( $current, $loaded['map'], $this->dir . '/changes.list', $this->dir . '/tombstones.list' );

		$this->assertSame( 0, $result['changes'] );
		$this->assertSame( 0, $result['tombstones'] );
		$this->assertFileExists( $this->dir . '/changes.list' );
		$this->assertFileExists( $this->dir . '/tombstones.list' );
		$this->assertSame( '', file_get_contents( $this->dir . '/changes.list' ) );
	}
}
