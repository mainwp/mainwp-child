<?php

define( 'MAINWP_TRAFFIC_ADVISER_DEVELOPMENT', false );

class MainWPChildTrafficAdviser {
	public $plugin_translate = "mainwp-child";
	public static $instance = null;
	protected $child_plugin_dir;
	protected $cache_dir;
	protected $cache_file;
	protected $settings = null;
	protected $software_version = "0.1";
	protected $log_enabled = false;

	static function Instance() {
		if ( MainWPChildTrafficAdviser::$instance == null ) {
			MainWPChildTrafficAdviser::$instance = new MainWPChildTrafficAdviser();
		}

		return MainWPChildTrafficAdviser::$instance;
	}

	public function __construct() {
		$this->child_plugin_dir = plugin_dir_path( __FILE__ ) . DIRECTORY_SEPARATOR . '..';
		$this->cache_dir        = $this->child_plugin_dir . DIRECTORY_SEPARATOR . 'traffic_adviser_cache' . DIRECTORY_SEPARATOR;
		$this->cache_file       = $this->cache_dir . 'cache.txt';
		$this->log_file         = $this->cache_dir . 'log.txt';
	}

	public function check_rules() {
		if ( ! file_exists( $this->cache_file ) ) {
			return;
		}

		$rules = file_get_contents( $this->cache_file );

		if ( $rules === false || ! is_string( $rules ) ) {
			return;
		}

		if ( MAINWP_TRAFFIC_ADVISER_DEVELOPMENT ) {
			$user_agent = ( isset( $_POST['user_agent'] ) ? trim( $_POST['user_agent'] ) : '' );
			$ip         = ( isset( $_POST['ip'] ) ? preg_replace( '/[^0-9a-fA-F:., ]/', '', trim( $_POST['ip'] ) ) : '' );
		} else {
			$user_agent = trim( $_SERVER['HTTP_USER_AGENT'] );
			$ip         = preg_replace( '/[^0-9a-fA-F:., ]/', '', trim( $_SERVER['REMOTE_ADDR'] ) );
		}

		$user_agent = strtolower( $user_agent );
		$ip         = strtolower( $ip );

		$user_agent_strlen = strlen( $user_agent );
		$ip_strlen         = strlen( $ip );

		list( $user_agent_whitelist, $user_agent_blacklist, $ip_whitelist, $ip_blacklist, $return_header, $log_enabled ) = explode( "\n", $rules );

		$user_agent_whitelist = explode( "|", $user_agent_whitelist );
		$user_agent_blacklist = explode( "|", $user_agent_blacklist );
		$ip_whitelist         = explode( "|", $ip_whitelist );
		$ip_blacklist         = explode( "|", $ip_blacklist );

		$this->log_enabled = $log_enabled;

		if ( $user_agent_strlen > 0 ) {

			foreach ( $user_agent_whitelist as $value ) {
				if ( mb_strpos( $user_agent, $value ) !== false ) {
					$this->log( __( 'USER_AGENT_WHITELIST', $this->plugin_translate ) . ': "' . $user_agent . '" ' . __( 'match', $this->plugin_translate ) . ' "' . $value . '"' );

					return;
				}
			}
		}

		if ( $ip_strlen > 0 ) {
			foreach ( $ip_whitelist as $value ) {
				if ( mb_strpos( $ip, $value ) !== false ) {
					$this->log( __( 'IP_WHITELIST', $this->plugin_translate ) . ': "' . $ip . '" ' . __( 'match', $this->plugin_translate ) . ' "' . $value . '"' );

					return;
				}
			}
		}

		if ( $user_agent_strlen > 0 ) {
			foreach ( $user_agent_blacklist as $value ) {
				if ( mb_strpos( $user_agent, $value ) !== false ) {
					$this->log( __( 'USER_AGENT_BLACKLIST', $this->plugin_translate ) . ': "' . $user_agent . '" ' . __( 'match', $this->plugin_translate ) . ' "' . $value . '"' );
					$this->return_status( $return_header );
				}
			}
		}

		if ( $ip_strlen > 0 ) {
			foreach ( $ip_blacklist as $value ) {
				if ( mb_strpos( $ip, $value ) !== false ) {
					$this->log( __( 'IP_BLACKLIST', $this->plugin_translate ) . ': "' . $ip . '" ' . __( 'match', $this->plugin_translate ) . ' "' . $value . '"' );
					$this->return_status( $return_header );
				}
			}
		}
	}

	public function action() {
		$information = array();
		switch ( $_POST['action'] ) {
			case 'traffic_adviser_update_values':
				$information = $this->update_values();
				break;

			case 'traffic_adviser_get_version':
				$information = array( 'version' => $this->software_version );
				break;

			case 'traffic_adviser_get_logs':
				$information = $this->get_logs();
				break;

			case 'traffic_adviser_get_cache_file':
				$information = $this->get_cache_file();
				break;

			case 'traffic_adviser_delete_cache_file':
				$information = $this->delete_cache_file();
				break;

			case 'traffic_adviser_update_header':
				$information = $this->update_cache_file( 'return_header' );
				break;

			case 'traffic_adviser_update_log_enabled':
				$information = $this->update_cache_file( 'log_enabled' );
				break;


		}
		MainWPHelper::write( $information );
	}

	protected function escape_values( $value ) {
		// Remove separator and use lower case because mb_stripos PHP 5 >= 5.2.0
		return str_replace( array( "\n" ), '', strtolower( $value ) );
	}

	protected function update_values() {
		$settings = $_POST['settings'];

		if ( ! is_array( $settings ) ) {
			return array( 'error' => __( 'Missing array settings', $this->plugin_translate ) );
		}

		$user_agent_whitelist = '';
		$user_agent_blacklist = '';
		$ip_whitelist         = '';
		$ip_blacklist         = '';
		$return_header        = '';

		if ( isset( $settings['user_agent_whitelist'] ) ) {
			$user_agent_whitelist = $this->escape_values( $settings['user_agent_whitelist'] );
		}

		if ( isset( $settings['user_agent_blacklist'] ) ) {
			$user_agent_blacklist = $this->escape_values( $settings['user_agent_blacklist'] );
		}

		// Always add MainWp server IP to whitelist
		$ip_whitelist = preg_replace( '/[^0-9a-f:., ]/', '', strtolower( $_SERVER['REMOTE_ADDR'] ) );

		if ( isset( $settings['ip_whitelist'] ) ) {
			$ip_whitelist .= '|' . $this->escape_values( $settings['ip_whitelist'] );
		}

		if ( isset( $settings['ip_blacklist'] ) ) {
			$ip_blacklist = $this->escape_values( $settings['ip_blacklist'] );
		}

		if ( isset( $settings['return_header'] ) ) {
			$return_header = $this->escape_values( $settings['return_header'] );
		}

		// Create directory recursive
		if ( ! wp_mkdir_p( $this->cache_dir ) ) {
			return array( 'error' => __( 'Error in creating cache directory', $this->plugin_translate ) );
		}

		if ( ! is_dir( $this->cache_dir ) ) {
			return array( 'error' => __( 'Cannot create cache directory', $this->plugin_translate ) );
		}

		// Add deny from all
		if ( ! file_exists( $this->cache_dir . 'index.php' ) ) {
			if ( ! touch( $this->cache_dir . 'index.php' ) ) {
				return array( 'error' => __( 'Cannot create index.php', $this->plugin_translate ) );
			}
		}

		if ( ! file_exists( $this->cache_dir . '.htaccess' ) ) {
			if ( file_put_contents( $this->cache_dir . '.htaccess', 'deny from all' ) === false ) {
				return array( 'error' => __( 'Cannot create .htaccess', $this->plugin_translate ) );
			}
		}

		// Delete log file
		if ( file_exists( $this->log_file ) ) {
			if ( ! $this->try_unlink_file( $this->log_file ) ) {
				return array( 'error' => __( 'Cannot delete log file', $this->plugin_translate ) );
			}
		}

		// Always disable logs when update rules
		$rules = $user_agent_whitelist . "\n" . $user_agent_blacklist . "\n" . $ip_whitelist . "\n" . $ip_blacklist . "\n" . $return_header . "\n" . "0";

		if ( file_put_contents( $this->cache_file, $rules ) === false ) {
			return array( 'error' => __( 'Cannot write rules', $this->plugin_translate ) );
		}

		return array( 'success' => 1 );
	}

	protected function update_cache_file( $update_key = 'return_header' ) {
		if ( ! file_exists( $this->cache_file ) ) {
			return array( 'error' => __( 'First send rules to child', $this->plugin_translate ) );
		}

		$settings = $_POST['settings'];

		if ( ! is_array( $settings ) || ! isset( $settings['value'] ) ) {
			return array( 'error' => __( 'Missing argument', $this->plugin_translate ) );
		}

		$rules = file_get_contents( $this->cache_file );

		if ( $rules === false || ! is_string( $rules ) ) {
			return array( 'error' => __( 'Cannot read cache file', $this->plugin_translate ) );
		}

		list( $user_agent_whitelist, $user_agent_blacklist, $ip_whitelist, $ip_blacklist, $return_header, $log_enabled ) = explode( "\n", $rules );

		switch ( $update_key ) {
			case 'return_header':
				$return_header = $settings['value'];
				break;
			case 'log_enabled':
				$log_enabled = $settings['value'];

				if ( file_exists( $this->log_file ) ) {
					if ( ! $this->try_unlink_file( $this->log_file ) ) {
						return array( 'error' => __( 'Cannot delete log file', $this->plugin_translate ) );
					}
				}

				if ( $log_enabled == '1' ) {
					if ( ! file_exists( $this->log_file ) ) {
						if ( ! touch( $this->log_file ) ) {
							return array( 'error' => __( 'Cannot create log file', $this->plugin_translate ) );
						}
					}
				}

				break;

			default:
				return array( 'error' => __( 'Wrong update key', $this->plugin_translate ) );
		}

		$rules = $user_agent_whitelist . "\n" . $user_agent_blacklist . "\n" . $ip_whitelist . "\n" . $ip_blacklist . "\n" . $return_header . "\n" . $log_enabled;

		if ( file_put_contents( $this->cache_file, $rules ) === false ) {
			return array( 'error' => __( 'Cannot write cache file', $this->plugin_translate ) );
		}

		return array( 'success' => 1, 'new_value' => $settings['value'] );

	}

	protected function get_cache_file() {
		if ( ! file_exists( $this->cache_file ) ) {
			return array( 'error' => __( 'Cache file does not exist', $this->plugin_translate ) );
		}

		$content = file_get_contents( $this->cache_file );

		if ( $content === false ) {
			return array( 'error' => __( 'Cannot get cache file', $this->plugin_translate ) );
		}

		return array( 'success' => 1, 'log' => $content );
	}

	protected function delete_cache_file() {
		if ( file_exists( $this->cache_file ) ) {
			if ( ! $this->try_unlink_file( $this->cache_file ) ) {
				return array( 'error' => __( 'Cannot delete cache file', $this->plugin_translate ) );
			}
		}

		if ( file_exists( $this->log_file ) ) {
			if ( ! $this->try_unlink_file( $this->log_file ) ) {
				return array( 'error' => __( 'Cannot delete log file', $this->plugin_translate ) );
			}
		}

		return array( 'success' => 1 );
	}

	protected function log( $message ) {
		if ( $this->log_enabled ) {
			@file_put_contents( $this->log_file, date( "d-m-Y H:i:s" ) . ' - ' . $message . "\n", FILE_APPEND );
		}
	}

	protected function try_unlink_file( $file ) {
		for ( $i = 0; $i < 10; ++ $i ) {
			if ( unlink( $file ) ) {
				return true;
			}
			usleep( 50 );
		}

		return false;
	}

	protected function return_status( $status ) {
		$protocol = $_SERVER["SERVER_PROTOCOL"];

		if ( strcmp( $protocol, "HTTP/1.0" ) !== 0 && strcmp( $protocol, "HTTP/1.1" ) !== 0 ) {
			$protocol = "HTTP/1.0";
		}

		switch ( $status ) {
			case 1:
				header( $protocol . " 204 No Content" );
				break;

			case 2:
				header( $protocol . " 301 Moved Permanently" );
				header( "Location: http://www.google.com" );
				break;

			case 3:
				header( $protocol . " 302 Found" );
				header( "Location: http://www.google.com" );
				break;

			case 4:
				header( $protocol . " 307 Temporary Redirect" );
				header( "Location: http://www.google.com" );
				break;

			case 5:
				header( $protocol . " 400 Bad Request" );
				break;

			case 6:
				header( $protocol . " 401 Unauthorized" );
				break;

			case 7:
				header( $protocol . " 403 Forbidden" );
				break;

			case 8:
				header( $protocol . " 404 Not Found" );
				break;

			case 9:
				header( $protocol . " 408 Request Timeout" );
				break;

			case 10:
				header( $protocol . " 410 Gone" );
				header( "Location: http://www.google.com" );
				break;

			case 11:
				header( $protocol . " 412 Precondition Failed" );
				break;

			case 12:
				header( $protocol . " 500 Internal Server Error" );
				break;

			case 13:
				header( $protocol . " 503 Service Temporarily Unavailable" );
				break;

			default:
				header( $protocol . " 404 Not Found" );
		}
		die();
	}

	protected function get_logs() {
		$information = array();

		$log_enabled = 0;

		if ( file_exists( $this->cache_file ) ) {

			$rules = file_get_contents( $this->cache_file );

			if ( $rules !== false && is_string( $rules ) ) {
				list( , , , , , $log_enabled ) = explode( "\n", $rules );
			}
		}

		$information['status'] = $log_enabled;

		if ( ! file_exists( $this->log_file ) ) {
			$information['log'] = '';
		} else {
			$information['log'] = file_get_contents( $this->log_file );
		}

		$information['success'] = 1;

		return $information;
	}
}