<?php
/**
 * MainWP Child Plugin Debug.
 *
 * @package MainWP\Child
 */

namespace MainWP\Child;

/**
 * Class MainWP_Debug
 */
class MainWP_Debug {

	/**
	 * Render MainWP Backup & test debugging output.
	 *
	 * @param object $mainWPChild MainWP_Child class instance.
	 */
	public static function process( &$mainWPChild ) {
		if ( ! isset( $_GET['mainwpdebug'] ) ) {
			return;
		}

		echo '<pre>';
		$start = microtime( true );

		if ( 'fullbackup' == $_GET['mainwpdebug'] ) {
			$_POST['type']          = 'full';
			$_POST['excludebackup'] = '1';
			$_POST['excludecache']  = '1';
			$_POST['excludezip']    = '1';
			$_POST['excludenonwp']  = '1';
			$_POST['ext']           = 'tar.gz';
			print_r( $mainWPChild->backup( false ) ); // phpcs:ignore -- debug feature.
		} elseif ( 'test' == $_GET['mainwpdebug'] ) {
			print_r( get_included_files() ); // phpcs:ignore -- debug feature.
		} else {
			print_r( MainWP_Child_Stats::get_instance()->get_site_stats( array(), false ) ); // phpcs:ignore -- debug feature.
		}

		$stop = microtime( true );
		die( "\n\n\n" . 'duration: ' . ( $stop - $start ) . 's</pre>' );
	}
}
