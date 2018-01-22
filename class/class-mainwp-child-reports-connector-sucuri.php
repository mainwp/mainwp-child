<?php
if ( class_exists( 'MainWP_WP_Stream_Connector' ) ) {
	class MainWP_Child_Reports_Connector_Sucuri extends MainWP_WP_Stream_Connector {

		/**
		 * Connector slug
		 *
		 * @var string
		 */
		public static $name = 'mainwp_sucuri';

		/**
		 * Actions registered for this connector
		 *
		 * @var array
		 */
		public static $actions = array(
			'mainwp_sucuri_scan',
		);

		/**
		 * Return translated connector label
		 *
		 * @return string Translated connector label
		 */
		public static function get_label() {
			return __( 'MainWP Sucuri', 'default' );
		}

		/**
		 * Return translated action labels
		 *
		 * @return array Action label translations
		 */
		public static function get_action_labels() {
			return array(
				'mainwp_sucuri_scan' => __( 'Scan', 'default' ),
			);
		}

		/**
		 * Return translated context labels
		 *
		 * @return array Context label translations
		 */
		public static function get_context_labels() {
			return array(
				'mainwp_sucuri' => __( 'MainWP Sucuri', 'default' ),
			);
		}

		/**
		 * Add action links to Stream drop row in admin list screen
		 *
		 * @filter wp_stream_action_links_{connector}
		 *
		 * @param  array $links Previous links registered
		 * @param  int $record Stream record
		 *
		 * @return array             Action links
		 */
		public static function action_links( $links, $record ) {
			return $links;
		}

		public static function callback_mainwp_sucuri_scan( $data, $scan_status, $scan_data ) {
			$message = '';
			if ( 'success' === $scan_status ) {
				$message     = __( 'Sucuri scan successful!', 'mainwp-child' );
				$scan_status = 'success';
			} else {
				$message     = __( 'Sucuri scan failed!', 'mainwp-child' );
				$scan_status = 'failed';
			}

			$scan_result = maybe_unserialize( base64_decode( $data ) );
			$status      = $webtrust = '';
			if ( is_array( $scan_result ) ) {
				$status   = isset( $scan_result['status'] ) ? $scan_result['status'] : '';
				$webtrust = isset( $scan_result['webtrust'] ) ? $scan_result['webtrust'] : '';
			}

			self::log(
				$message,
				compact( 'scan_status', 'status', 'webtrust', 'scan_data' ),
				0,
				array( 'mainwp_sucuri' => 'mainwp_sucuri_scan' )
			);
		}
	}
}

