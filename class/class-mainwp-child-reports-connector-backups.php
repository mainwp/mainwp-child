<?php
if ( class_exists( 'MainWP_WP_Stream_Connector' ) ) {
	class MainWP_Child_Reports_Connector_Backups extends MainWP_WP_Stream_Connector {

		/**
		 * Connector slug
		 *
		 * @var string
		 */
		public static $name = 'mainwp_backups';

		/**
		 * Actions registered for this connector
		 *
		 * @var array
		 */
		public static $actions = array(
			'mainwp_backup',
		);

		/**
		 * Return translated connector label
		 *
		 * @return string Translated connector label
		 */
		public static function get_label() {
			return __( 'MainWP Backups', 'default' );
		}

		/**
		 * Return translated action labels
		 *
		 * @return array Action label translations
		 */
		public static function get_action_labels() {
			return array(
				'mainwp_backup' => __( 'MainWP Backup', 'default' ),
			);
		}

		/**
		 * Return translated context labels
		 *
		 * @return array Context label translations
		 */
		public static function get_context_labels() {
			return array(
				'mainwp_backups' => __( 'MainWP Backups', 'mainwp-child' ),
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

		public static function callback_mainwp_backup( $destination, $message, $size, $status, $type ) {
			self::log(
				$message,
				compact( 'destination', 'status', 'type', 'size' ),
				0,
				array( 'mainwp_backups' => 'mainwp_backup' )
			);
		}
	}
}

