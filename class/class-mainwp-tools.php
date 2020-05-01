<?php

class MainWP_Tools {
	public static function execute_snippet( $code ) {
		ob_start();
		$result = eval( $code ); // phpcs:ignore Squiz.PHP.Eval -- eval() used safely.
		$output = ob_get_contents();
		ob_end_clean();
		$return = array();
		$error  = error_get_last();
		if ( ( false === $result ) && $error ) {
			$return['status'] = 'FAIL';
			$return['result'] = $error['message'];
		} else {
			$return['status'] = 'SUCCESS';
			$return['result'] = $output;
		}
		return $return;
	}
}
