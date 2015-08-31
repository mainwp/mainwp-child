<?php
if (class_exists('WP_Stream\Connector')) {
    class MainWPStreamConnectorBackups extends WP_Stream\Connector
    {   

	/**
	 * Connector slug
	 *
	 * @var string
	 */
	public $name = 'mainwp_backups';

	/**
	 * Actions registered for this connector
	 *
	 * @var array
	 */
	public $actions = array(
            'mainwp_backup',		
	);

	/**
	 * Return translated connector label
	 *
	 * @return string Translated connector label
	 */
	public function get_label() {
            return __( 'MainWP Backups', 'default' );                
	}

	/**
	 * Return translated action labels
	 *
	 * @return array Action label translations
	 */
	public function get_action_labels() {
            return array(
                'mainwp_backup'    => __( 'Backup', 'default' ),			
            );
	}

	/**
	 * Return translated context labels
	 *
	 * @return array Context label translations
	 */
	public function get_context_labels() {
            return array(
                'mainwp_backups' => __( 'MainWP Backups', 'mainwp-child' ),
            );
	}

	/**
	 * Add action links to Stream drop row in admin list screen
	 *
	 * @filter wp_stream_action_links_{connector}
	 * @param  array $links      Previous links registered
	 * @param  int   $record     Stream record
	 * @return array             Action links
	 */
	public function action_links( $links, $record ) {
            if (isset($record->object_id)) {
            }
            return $links;
	}

        public function callback_mainwp_backup($destination, $message, $size, $status, $type) {
            $this->log(
                $message,
                compact('destination', 'status', 'type', 'size'),
                0,
                'mainwp_backups',
                'mainwp_backup'
            );
        }
    }
}

