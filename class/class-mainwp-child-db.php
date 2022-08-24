<?php
/**
 * MainWP Child DB
 *
 * This file handles all of the Child Plugin's DB functions.
 *
 * @package MainWP\Child
 */

namespace MainWP\Child;

/**
 * Class MainWP_Child_DB
 *
 * Handles all of the Child Plugin's DB functions.
 */
class MainWP_Child_DB {

	// phpcs:disable WordPress.DB.RestrictedFunctions, WordPress.DB.PreparedSQL.NotPrepared -- unprepared SQL ok, accessing the database directly to custom database functions.

	/**
	 * Support old & new versions of WordPress (3.9+).
	 *
	 * @return bool|object Instantiated object of \mysqli.
	 */
	public static function use_mysqli() {
		if ( ! function_exists( '\mysqli_connect' ) ) {
			return false;
		}

		/**
		 * WordPress Database instance.
		 *
		 * @global object $wpdb
		 */
		global $wpdb;

		return ( $wpdb->dbh instanceof \mysqli );
	}

	/**
	 * Run a mysqli query & get a result.
	 *
	 * @param string $query An SQL query.
	 * @param string $link A link identifier.
	 *
	 * @return bool|\mysqli_result|resource For successful SELECT, SHOW, DESCRIBE or EXPLAIN queries, mysqli_query()
	 *  will return a mysqli_result object. For other successful queries mysqli_query() will return TRUE.
	 *  Returns FALSE on failure.
	 */
	public static function to_query( $query, $link ) {
		if ( self::use_mysqli() ) {
			return \mysqli_query( $link, $query );
		} else {
			return \mysql_query( $query, $link );
		}
	}

	/**
	 * Fetch an array.
	 *
	 * @param array $result A result set identifier.
	 *
	 * @return array|false|null Returns an array of strings that corresponds to the fetched row, or false if there are no more rows.
	 */
	public static function fetch_array( $result ) {
		if ( self::use_mysqli() ) {
			return \mysqli_fetch_array( $result, MYSQLI_ASSOC );
		} else {
			return \mysql_fetch_array( $result, MYSQL_ASSOC );
		}
	}

	/**
	 * Count the number of rows.
	 *
	 * @param array $result A result set identifier returned.
	 *
	 * @return false|int Returns number of rows in the result set.
	 */
	public static function num_rows( $result ) {
		if ( self::use_mysqli() ) {
			return \mysqli_num_rows( $result );
		} else {
			return \mysql_num_rows( $result );
		}
	}

	/**
	 * Connect to Child Site Database.
	 *
	 * @param string $host Can be either a host name or an IP address.
	 * @param string $user The MySQL user name.
	 * @param string $pass The MySQL user password.
	 *
	 * @return false|\mysqli|resource object which represents the connection to a MySQL Server or false if an error occurred.
	 */
	public static function connect( $host, $user, $pass ) {
		if ( self::use_mysqli() ) {
			return \mysqli_connect( $host, $user, $pass );
		} else {
			return \mysql_connect( $host, $user, $pass );
		}
	}

	/**
	 * Select Child Site DB.
	 *
	 * @param string $db Database name.
	 *
	 * @return bool true on success or false on failure.
	 */
	public static function select_db( $db ) {
		if ( self::use_mysqli() ) {

			/**
			 * WordPress Database instance.
			 *
			 * @global object $wpdb
			 */
			global $wpdb;

			return \mysqli_select_db( $wpdb->dbh, $db );
		} else {
			return \mysql_select_db( $db );
		}
	}

	/**
	 * Get any mysqli errors.
	 *
	 * @return string the error text from the last MySQL function, or '' (empty string) if no error occurred.
	 */
	public static function error() {
		if ( self::use_mysqli() ) {

			/**
			 * WordPress Database instance.
			 *
			 * @global object $wpdb
			 */
			global $wpdb;

			return \mysqli_error( $wpdb->dbh );
		} else {
			return \mysql_error();
		}
	}

	/**
	 * Escape a given string.
	 *
	 * @param string $value The string to be escaped. Characters encoded are NUL (ASCII 0), \n, \r, \, ', ", and Control-Z.
	 *
	 * @return false|string the escaped string, or false on error.
	 */
	public static function real_escape_string( $value ) {

		/**
		 * WordPress Database instance.
		 *
		 * @global object $wpdb
		 */
		global $wpdb;

		if ( self::use_mysqli() ) {
			return \mysqli_real_escape_string( $wpdb->dbh, $value );
		} else {
			return \mysql_real_escape_string( $value, $wpdb->dbh );
		}
	}

	/**
	 * Check if $result is an Instantiated object of \mysqli.
	 *
	 * @param resource $result Instantiated object of \mysqli.
	 *
	 * @return resource|bool Instantiated object of \mysqli, true if var is a resource, false otherwise.
	 */
	public static function is_result( $result ) {
		if ( self::use_mysqli() ) {
			return ( $result instanceof \mysqli_result );
		} else {
			return is_resource( $result );
		}
	}

	/**
	 * Get the size of the DB.
	 *
	 * @return int|mixed Size of the DB or false on failure.
	 */
	public static function get_size() {

		/**
		 * WordPress Database instance.
		 *
		 * @global object $wpdb
		 */
		global $wpdb;

		$rows = self::to_query( 'SHOW table STATUS', $wpdb->dbh );
		$size = 0;
		while ( $row = self::fetch_array( $rows ) ) {
			$size += $row['Data_length'];
		}

		return $size;
	}
}
