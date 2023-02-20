<?php
/**
 * MainWP Render Branding
 *
 * This file handles rendering the Child Branding settings.
 *
 * @package MainWP\Child
 */

namespace MainWP\Child;

/**
 * Class MainWP_Child_Branding_Render
 *
 * @package MainWP\Child
 */
class MainWP_Child_Branding_Render {

	/**
	 * Public static variable to hold the single instance of the class.
	 *
	 * @var mixed Default null
	 */
	public static $instance = null;

	/**
	 * Method instance()
	 *
	 * Create a public static instance.
	 *
	 * @return mixed Class instance.
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Get Class Name.
	 *
	 * @return string
	 */
	public static function get_class_name() {
		return __CLASS__;
	}

	/**
	 * MainWP_Child_Branding_Render constructor.
	 *
	 * Run any time the class is called.
	 */
	public function __construct() {
	}

	/**
	 * Method admin_head_hide_elements().
	 */
	public static function admin_head_hide_elements() {
		?>
		<script type="text/javascript">
			document.addEventListener( "DOMContentLoaded", function( event ) {
				document.getElementById( "wp-admin-bar-updates" ).outerHTML = '';
				document.getElementById( "menu-plugins" ).outerHTML = '';
				var els_core = document.querySelectorAll( "a[href='update-core.php']" );
				for ( var i = 0, l = els_core.length; i < l; i++ ) {
					var el = els_core[i];
					el.parentElement.innerHTML = '';
				}
			} );
		</script>
		<?php
	}

	/**
	 * Render Contact Support.
	 *
	 * @return string Contact Support form html.
	 *
	 * @uses \MainWP\Child\MainWP_Child_Branding::$child_branding_options
	 */
	public function contact_support() {

		/**
		 * Current user global.
		 *
		 * @global string
		 */
		global $current_user;

		?>
		<style>
			.mainwp_info-box-yellow {
				margin: 5px 0 15px;
				padding: .6em;
				background: #ffffe0;
				border: 1px solid #e6db55;
				border-radius: 3px;
				-moz-border-radius: 3px;
				-webkit-border-radius: 3px;
				clear: both;
			}
		</style>
		<?php
		$opts = MainWP_Child_Branding::instance()->child_branding_options;

		if ( isset( $_POST['submit'] ) ) {
			if ( ! isset( $_POST['_wpnonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['_wpnonce'] ) ), '_contactNonce' ) ) {
				return false;
			}
			$this->render_submit_message( $opts );
			return;
		}

		$from_page = '';
		if ( isset( $_GET['from_page'] ) ) {
			$from_page = isset( $_GET['from_page'] ) ? rawurldecode( wp_unslash( $_GET['from_page'] ) ) : '';
		} else {
			$protocol  = isset( $_SERVER['HTTPS'] ) && strcasecmp( sanitize_text_field( wp_unslash( $_SERVER['HTTPS'] ) ), 'off' ) ? 'https://' : 'http://';
			$fullurl   = isset( $_SERVER['HTTP_HOST'] ) && isset( $_SERVER['REQUEST_URI'] ) ? $protocol . sanitize_text_field( wp_unslash( $_SERVER['HTTP_HOST'] ) ) . wp_unslash( $_SERVER['REQUEST_URI'] ) : '';
			$from_page = rawurldecode( $fullurl );
		}

		$support_message = $opts['support_message'];
		$support_message = nl2br( stripslashes( $support_message ) );
		$from_email      = $current_user ? $current_user->user_email : '';
		?>
		<form action="" method="post">
			<div style="width: 99%;" class="whlb-support-form">
				<h2><?php echo esc_html( $opts['contact_label'] ); ?></h2>
				<div style="height: auto; margin-bottom: 10px; text-align: left">
					<p class="whlb-support-form"><?php echo wp_kses_post( $support_message ); ?></p>
					<p class="whlb-support-form">
						<label for="mainwp_branding_contact_message_subject"><?php esc_html_e( 'Subject:', 'mainwp-child' ); ?></label>
						<br>
						<input type="text" id="mainwp_branding_contact_message_subject" name="mainwp_branding_contact_message_subject" style="width: 650px;">
					</p>
					<p class="whlb-support-form">
						<label for="mainwp_branding_contact_send_from"><?php esc_html_e( 'From:', 'mainwp-child' ); ?></label>
						<br>
						<input type="text" id="mainwp_branding_contact_send_from" name="mainwp_branding_contact_send_from" style="width: 650px;" value="<?php echo esc_attr( $from_email ); ?>">
					</p>
					<div style="max-width: 650px;" class="whlb-support-form">
						<label for="mainwp_branding_contact_message_content"><?php esc_html_e( 'Your message:', 'mainwp-child' ); ?></label>
						<br>
						<?php
						remove_editor_styles(); // stop custom theme styling interfering with the editor.
						wp_editor(
							'',
							'mainwp_branding_contact_message_content',
							array(
								'textarea_name' => 'mainwp_branding_contact_message_content',
								'textarea_rows' => 10,
								'teeny'         => true,
								'wpautop'       => true,
								'media_buttons' => false,
							)
						);
						?>
					</div>
				</div>
				<br/>
				<?php
				$button_title = $opts['submit_button_title'];
				$button_title = ! empty( $button_title ) ? $button_title : esc_html__( 'Submit', 'mainwp-child' );
				?>
				<div class="whlb-support-field">
				<input id="mainwp-branding-contact-support-submit" type="submit" name="submit" value="<?php echo esc_attr( $button_title ); ?>" class="button-primary button" style="float: left"/>
				</div>
			</div>
			<input type="hidden" name="mainwp_branding_send_from_page" value="<?php echo esc_url( $from_page ); ?>"/>
			<input type="hidden" name="_wpnonce" value="<?php echo esc_attr( wp_create_nonce( '_contactNonce' ) ); ?>"/>
		</form>
		<?php
	}

	/**
	 * Render contact support submit message.
	 *
	 * @param array $opts An array containg message options.
	 */
	private function render_submit_message( $opts ) {
		$from_page = isset( $_POST['mainwp_branding_send_from_page'] ) ? wp_unslash( $_POST['mainwp_branding_send_from_page'] ) : '';
		$back_link = $opts['message_return_sender'];
		$back_link = ! empty( $back_link ) ? $back_link : 'Go Back';
		$back_link = ! empty( $from_page ) ? '<a href="' . esc_url( $from_page ) . '" title="' . esc_attr( $back_link ) . '">' . esc_html( $back_link ) . '</a>' : '';

		if ( MainWP_Utility::instance()->send_support_mail() ) {
			$send_email_message = isset( $opts['send_email_message'] ) ? $opts['send_email_message'] : '';
			if ( ! empty( $send_email_message ) ) {
				$send_email_message = stripslashes( $send_email_message );
			} else {
				$send_email_message = esc_html__( 'Message has been submitted successfully.', 'mainwp-child' );
			}
		} else {
			$send_email_message = esc_html__( 'Sending email failed!', 'mainwp-child' );
		}
		?>
		<div class="mainwp_info-box-yellow"><?php echo esc_html( $send_email_message ) . '&nbsp;&nbsp' . $back_link; ?></div>
		<?php
	}

	/**
	 * After admin bar render.
	 */
	public function after_admin_bar_render() {
		$hide_slugs = apply_filters( 'mainwp_child_hide_update_notice', array() );

		if ( ! is_array( $hide_slugs ) ) {
			$hide_slugs = array();
		}

		if ( 0 == count( $hide_slugs ) ) {
			return;
		}

		if ( ! function_exists( '\get_plugin_updates' ) ) {
			include_once ABSPATH . '/wp-admin/includes/update.php';
		}

		$count_hide = 0;

		$updates = get_plugin_updates();
		if ( is_array( $updates ) ) {
			foreach ( $updates as $slug => $data ) {
				if ( in_array( $slug, $hide_slugs ) ) {
					$count_hide++;
				}
			}
		}

		if ( 0 == $count_hide ) {
			return;
		}
		?>
		<script type="text/javascript">
			var mainwpCountHide = <?php echo esc_attr( $count_hide ); ?>;
			document.addEventListener( "DOMContentLoaded", function( event ) {
				var $adminBarUpdates = document.querySelector( '#wp-admin-bar-updates .ab-label' ),
					itemCount;

				if ( typeof( $adminBarUpdates ) !== 'undefined' && $adminBarUpdates !== null ) {
					itemCount = $adminBarUpdates.textContent;
					itemCount = parseInt( itemCount );

					itemCount -= mainwpCountHide;
					if ( itemCount < 0 )
						itemCount = 0;

					$adminBarUpdates.textContent = itemCount;
				}
			} );
		</script>
		<?php
	}

	/**
	 * Admin footer text.
	 */
	public function in_admin_footer() {
		$hide_slugs = apply_filters( 'mainwp_child_hide_update_notice', array() );

		if ( ! is_array( $hide_slugs ) ) {
			$hide_slugs = array();
		}

		$count_hide = 0;

		$updates = get_plugin_updates();
		if ( is_array( $updates ) ) {
			foreach ( $updates as $slug => $data ) {
				if ( in_array( $slug, $hide_slugs ) ) {
					$count_hide++;
				}
			}
		}

		if ( 0 == $count_hide ) {
			return;
		}

		?>
		<script type="text/javascript">
			var mainwpCountHide = <?php echo esc_attr( $count_hide ); ?>;
			document.addEventListener( "DOMContentLoaded", function( event ) {
				if ( typeof( pagenow ) !== 'undefined' && pagenow == 'plugins' ) {
					<?php
					// hide update notice row.
					if ( in_array( 'mainwp-child/mainwp-child.php', $hide_slugs ) ) {
						?>
						var el = document.querySelector( 'tr#mainwp-child-update' );
						if ( typeof( el ) !== 'undefined' && el !== null ) {
							el.style.display = 'none';
						}
						<?php
					}
					// hide update notice row.
					if ( in_array( 'mainwp-child-reports/mainwp-child-reports.php', $hide_slugs ) ) {
						?>
						var el = document.querySelector( 'tr#mainwp-child-reports-update' );
						if ( typeof( el ) !== 'undefined' && el !== null ) {
							el.style.display = 'none';
						}
						<?php
					}
					?>
				}

				if ( mainwpCountHide > 0 ) {
					jQuery( document ).ready( function () {

						var $adminBarUpdates       = jQuery( '#wp-admin-bar-updates' ),
						$pluginsNavMenuUpdateCount = jQuery( 'a[href="plugins.php"] .update-plugins' ),
						itemCount;
						itemCount = $adminBarUpdates.find( '.ab-label' ).text();
						itemCount -= mainwpCountHide;
						if ( itemCount < 0 )
							itemCount = 0;

						itemPCount = $pluginsNavMenuUpdateCount.find( '.plugin-count' ).text();
						itemPCount -= mainwpCountHide;

						if ( itemPCount < 0 )
							itemPCount = 0;

						$adminBarUpdates.find( '.ab-label' ).text( itemCount );
						$pluginsNavMenuUpdateCount.find( '.plugin-count' ).text( itemPCount );

					} );
				}
			} );
		</script>
		<?php
	}

}

