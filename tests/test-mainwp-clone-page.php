<?php

use MainWP\Child\MainWP_Clone_Page;

class MainWPClonePageTest extends WP_UnitTestCase {

	public function test_normal_restore_renders_directory_error_and_continues() {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$parent = tempnam( sys_get_temp_dir(), 'mainwp-clone-page-' );
		$basedir = $parent . '/uploads';
		$filter = static function ( $uploads ) use ( $basedir ) {
			$uploads['basedir'] = $basedir;
			return $uploads;
		};
		add_filter( 'mainwp_child_get_wp_upload_dir', $filter );
		ob_start();
		try {
			@MainWP_Clone_Page::render_normal_restore();
			$output = ob_get_contents();
		} finally {
			ob_end_clean();
			remove_filter( 'mainwp_child_get_wp_upload_dir', $filter );
			unlink( $parent );
		}

		$message = 'Unable to create directory ' . $basedir . '/mainwp/. Is its parent directory writable by the server?';
		$this->assertStringContainsString( '<div class="mainwp-child_info-box-red"><strong>' . esc_html( $message ) . '</strong></div>', $output );
		$this->assertStringNotContainsString( 'id="title_03"', $output );
		$this->assertStringContainsString( 'name="file"', $output );
		$this->assertStringContainsString( 'id="mainwp-child_clone_status"', $output );
	}
}
