<?php
/**
 * Email sending helpers.
 *
 * @package QBMBot
 */

declare(strict_types=1);

namespace QBMBot;

/**
 * Sends CF7 auto-reply emails via wp_mail.
 */
final class Mailer {

	/**
	 * Settings.
	 *
	 * @var Settings
	 */
	private Settings $settings;

	/**
	 * Constructor.
	 *
	 * @param Settings $settings Settings.
	 */
	public function __construct( Settings $settings ) {
		$this->settings = $settings;
	}

	/**
	 * Send auto-reply email.
	 *
	 * @param string $to      Recipient.
	 * @param string $body    Plain text body.
	 * @param string $subject Optional subject override.
	 */
	public function send_auto_reply( string $to, string $body, string $subject = '' ): bool {
		$to = sanitize_email( $to );
		if ( ! is_email( $to ) ) {
			return false;
		}

		if ( '' === $subject ) {
			$subject = (string) $this->settings->get( 'cf7_email_subject', 'Re: Your enquiry' );
		}

		$headers = array( 'Content-Type: text/plain; charset=UTF-8' );

		$from_name  = (string) $this->settings->get( 'cf7_from_name', '' );
		$from_email = sanitize_email( (string) $this->settings->get( 'cf7_from_email', '' ) );
		if ( '' === $from_name ) {
			$from_name = wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES );
		}
		if ( '' === $from_email ) {
			$from_email = get_option( 'admin_email' );
		}
		if ( is_email( $from_email ) ) {
			$headers[] = sprintf( 'From: %s <%s>', $this->encode_from_name( $from_name ), $from_email );
		}

		return (bool) wp_mail( $to, $subject, $body, $headers );
	}

	/**
	 * Encode From display name for headers.
	 *
	 * @param string $name Display name.
	 */
	private function encode_from_name( string $name ): string {
		$name = str_replace( array( "\r", "\n" ), '', $name );
		return $name;
	}
}
