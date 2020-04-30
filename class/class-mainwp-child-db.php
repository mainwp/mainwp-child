<?php

class MainWP_Child_DB {
	//Support old & new versions of wordpress (3.9+)
	public static function use_mysqli() {
		/** @var $wpdb wpdb */
		if ( ! function_exists( 'mysqli_connect' ) ) {
			return false;
		}

		global $wpdb;

		return ( $wpdb->dbh instanceof mysqli );
	}

	public static function _query( $query, $link ) {
		if ( self::use_mysqli() ) {
			return mysqli_query( $link, $query );
		} else {
			return mysql_query( $query, $link );
		}
	}

	public static function fetch_array( $result ) {
		if ( self::use_mysqli() ) {
			return mysqli_fetch_array( $result, MYSQLI_ASSOC );
		} else {
			return mysql_fetch_array( $result, MYSQL_ASSOC );
		}
	}

	public static function num_rows( $result ) {
		if ( self::use_mysqli() ) {
			return mysqli_num_rows( $result );
		} else {
			return mysql_num_rows( $result );
		}
	}

	public static function connect( $host, $user, $pass ) {
		if ( self::use_mysqli() ) {
			return mysqli_connect( $host, $user, $pass );
		} else {
			return mysql_connect( $host, $user, $pass );
		}
	}

	public static function select_db( $db ) {
		if ( self::use_mysqli() ) {
			/** @var $wpdb wpdb */
			global $wpdb;

			return mysqli_select_db( $wpdb->dbh, $db );
		} else {
			return mysql_select_db( $db );
		}
	}

	public static function error() {
		if ( self::use_mysqli() ) {
			/** @var $wpdb wpdb */
			global $wpdb;

			return mysqli_error( $wpdb->dbh );
		} else {
			return mysql_error();
		}
	}

	public static function real_escape_string( $value ) {
		/** @var $wpdb wpdb */
		global $wpdb;

		if ( self::use_mysqli() ) {
			return mysqli_real_escape_string( $wpdb->dbh, $value );
		} else {
			return mysql_real_escape_string( $value, $wpdb->dbh );
		}
	}

	public static function is_result( $result ) {
		if ( self::use_mysqli() ) {
			return ( $result instanceof mysqli_result );
		} else {
			return is_resource( $result );
		}
	}

	static function get_size() {
		/** @var $wpdb wpdb */
		global $wpdb;

		$rows = MainWP_Child_DB::_query( 'SHOW table STATUS', $wpdb->dbh );
		$size = 0;
		while ( $row = MainWP_Child_DB::fetch_array( $rows ) ) {
			$size += $row['Data_length'];
		}

		return $size;
	}
}
