<?php

class MainWP_Debug {
	/**
	 * @param $mainwpChild MainWP_Child
	 */
	public static function process(&$mainwpChild) {
		if ( ! isset( $_GET['mainwpdebug'] ) || ! defined( 'MAINWP_DEBUG' ) || ( MAINWP_DEBUG !== true ) ) {
			return;
		}

		echo '<pre>';
		$start = microtime( true );

		if ( 'fullbackup' == $_GET['mainwpdebug'] ) {
			//Full backup
			$_POST['type']          = 'full';
			$_POST['excludebackup'] = '1';
			$_POST['excludecache']  = '1';
			$_POST['excludezip']    = '1';
			$_POST['excludenonwp']  = '1';
			$_POST['ext']           = 'tar.gz';
			print_r( $mainwpChild->backup( false ) );
		} else if ( 'test' == $_GET['mainwpdebug'] ) {
			print_r( get_included_files() );
		} else {
			print_r( $mainwpChild->getSiteStats( array(), false ) );
		}


		$stop = microtime( true );
		die( "\n\n\n" . 'duration: ' . ( $stop - $start ) . 's</pre>' );
	}
}