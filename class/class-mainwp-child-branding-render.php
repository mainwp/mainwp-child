<?php
/**
 * MainWP Render Branding
 *
 * This file handles rendering the Child Branding settings.
 */
namespace MainWP\Child;

/**
 * Class MainWP_Child_Branding_Render
 * @package MainWP\Child
 */
class MainWP_Child_Branding_Render {
    /**
     * @static
     * @var null Holds the Public static instance MainWP_Child_Branding_Render.
     */
    public static $instance = null;

    /**
     * Create a public static instance of MainWP_Child_Branding_Render.
     *
     * @return MainWP_Child_Branding_Render|null
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
     */
    public function __construct() {
	}

    /**
     * Method admin_head_hide_elements().
     *
     * @deprecated Unused Element.
     */
    public function admin_head_hide_elements() {
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
     * @deprecated Unused Element.
     */
    public function contact_support() {
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
			if ( ! isset( $_POST['_wpnonce'] ) || ! wp_verify_nonce( $_POST['_wpnonce'], '_contactNonce' ) ) {
				return false;
			}
			$this->render_submit_message( $opts );
			return;
		}

		$from_page = '';
		if ( isset( $_GET['from_page'] ) ) {
			$from_page = rawurldecode( $_GET['from_page'] );
		} else {
			$protocol  = isset( $_SERVER['HTTPS'] ) && strcasecmp( $_SERVER['HTTPS'], 'off' ) ? 'https://' : 'http://';
			$fullurl   = $protocol . $_SERVER['HTTP_HOST'] . $_SERVER['REQUEST_URI'];
			$from_page = rawurldecode( $fullurl );
		}

		$support_message = $opts['support_message'];
		$support_message = nl2br( stripslashes( $support_message ) );
		$from_email      = $current_user ? $current_user->user_email : '';
		?>
		<form action="" method="post">
			<div style="width: 99%;">
				<h2><?php echo esc_html( $opts['contact_label'] ); ?></h2>
				<div style="height: auto; margin-bottom: 10px; text-align: left">
					<p><?php echo wp_kses_post( $support_message ); ?></p>
					<p>
						<label for="mainwp_branding_contact_message_subject"><?php esc_html_e( 'Subject:', 'mainwp-child' ); ?></label>
						<br>
						<input type="text" id="mainwp_branding_contact_message_subject" name="mainwp_branding_contact_message_subject" style="width: 650px;">
					</p>
					<p>
						<label for="mainwp_branding_contact_send_from"><?php esc_html_e( 'From:', 'mainwp-child' ); ?></label>
						<br>
						<input type="text" id="mainwp_branding_contact_send_from" name="mainwp_branding_contact_send_from" style="width: 650px;" value="<?php echo esc_attr( $from_email ); ?>">
					</p>
					<div style="max-width: 650px;">
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
				$button_title = ! empty( $button_title ) ? $button_title : __( 'Submit', 'mainwp-child' );
				?>
				<input id="mainwp-branding-contact-support-submit" type="submit" name="submit" value="<?php echo esc_attr( $button_title ); ?>" class="button-primary button" style="float: left"/>
			</div>
			<input type="hidden" name="mainwp_branding_send_from_page" value="<?php echo esc_url( $from_page ); ?>"/>
			<input type="hidden" name="_wpnonce" value="<?php echo esc_attr( wp_create_nonce( '_contactNonce' ) ); ?>"/>
		</form>
		<?php
	}

    /**
     * Render contact support submit message.
     *
     * @param $opts Message options.
     * @return string Submitted message.
     */
    private function render_submit_message( $opts ) {

		$from_page = $_POST['mainwp_branding_send_from_page'];
		$back_link = $opts['message_return_sender'];
		$back_link = ! empty( $back_link ) ? $back_link : 'Go Back';
		$back_link = ! empty( $from_page ) ? '<a href="' . esc_url( $from_page ) . '" title="' . esc_attr( $back_link ) . '">' . esc_html( $back_link ) . '</a>' : '';

		if ( $this->send_support_mail() ) {
			$send_email_message = isset( $opts['send_email_message'] ) ? $opts['send_email_message'] : '';
			if ( ! empty( $send_email_message ) ) {
				$send_email_message = stripslashes( $send_email_message );
			} else {
				$send_email_message = __( 'Message has been submitted successfully.', 'mainwp-child' );
			}
		} else {
			$send_email_message = __( 'Sending email failed!', 'mainwp-child' );
		}
		?>
		<div class="mainwp_info-box-yellow"><?php echo esc_html( $send_email_message ) . '&nbsp;&nbsp' . $back_link; ?></div>
		<?php
	}

    /**
     * Send support email.
     *
     * @return bool Return TRUE on success FALSE on failure.
     */
    public function send_support_mail() {
		$opts    = MainWP_Child_Branding::instance()->get_branding_options();
		$email   = $opts['support_email'];
		$sub     = wp_kses_post( nl2br( stripslashes( $_POST['mainwp_branding_contact_message_subject'] ) ) );
		$from    = trim( $_POST['mainwp_branding_contact_send_from'] );
		$subject = ! empty( $sub ) ? $sub : 'MainWP - Support Contact';
		$content = wp_kses_post( nl2br( stripslashes( $_POST['mainwp_branding_contact_message_content'] ) ) );
		$mail    = '';
		$headers = '';
		if ( ! empty( $_POST['mainwp_branding_contact_message_content'] ) && ! empty( $email ) ) {
			global $current_user;
			$headers .= "Content-Type: text/html;charset=utf-8\r\n";
			if ( ! empty( $from ) ) {
				$headers .= 'From: "' . $from . '" <' . $from . ">\r\n";
			}
			$mail .= "<p>Support Email from: <a href='" . site_url() . "'>" . site_url() . "</a></p>\r\n\r\n";
			$mail .= '<p>Sent from WordPress page: ' . ( ! empty( $_POST['mainwp_branding_send_from_page'] ) ? "<a href='" . esc_url( $_POST['mainwp_branding_send_from_page'] ) . "'>" . esc_url( $_POST['mainwp_branding_send_from_page'] ) . "</a></p>\r\n\r\n" : '' );
			$mail .= '<p>Client Email: ' . $current_user->user_email . " </p>\r\n\r\n";
			$mail .= "<p>Support Text:</p>\r\n\r\n";
			$mail .= '<p>' . $content . "</p>\r\n\r\n";

			wp_mail( $email, $subject, $mail, $headers );

			return true;
		}
		return false;
	}

    /**
     * After admin bar render.
     *
     * @deprecated Unused Element.
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
     *
     * @deprecated Unused Element.
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