<?php
/**
 * Chat lead capture (name, email, phone, enquiry).
 *
 * @package QBMBot
 */

declare(strict_types=1);

namespace QBMBot;

/**
 * Extracts and stores chat leads, then notifies the business by email.
 */
final class Lead_Capture {

	/**
	 * Settings.
	 *
	 * @var Settings
	 */
	private Settings $settings;

	/**
	 * Mailer.
	 *
	 * @var Mailer
	 */
	private Mailer $mailer;

	/**
	 * Logger.
	 *
	 * @var Logger
	 */
	private Logger $logger;

	/**
	 * Constructor.
	 *
	 * @param Settings $settings Settings.
	 * @param Mailer   $mailer   Mailer.
	 * @param Logger   $logger   Logger.
	 */
	public function __construct( Settings $settings, Mailer $mailer, Logger $logger ) {
		$this->settings = $settings;
		$this->mailer   = $mailer;
		$this->logger   = $logger;
	}

	/**
	 * Whether lead capture is enabled.
	 */
	public function enabled(): bool {
		return (bool) $this->settings->get( 'lead_capture_enabled', true );
	}

	/**
	 * Ingest a user message into the session lead and email when complete.
	 *
	 * @param string               $session_id Session id.
	 * @param string               $message    Latest user message.
	 * @param array<int, array{role:string,content:string}> $history Conversation history (including this message).
	 * @param string               $ip         Client IP.
	 * @return array{sent:bool,lead:array<string,mixed>,missing:array<int,string>}
	 */
	public function process_turn( string $session_id, string $message, array $history, string $ip ): array {
		$lead = $this->get_lead( $session_id );
		$lead = $this->merge_extracted( $lead, $message, $history );
		$this->save_lead( $session_id, $lead );

		$missing = $this->missing_fields( $lead );
		$sent    = false;

		if ( empty( $missing ) && empty( $lead['sent'] ) ) {
			$sent = $this->send_notification( $lead, $ip );
			$lead['sent'] = $sent;
			$lead['sent_at'] = current_time( 'mysql', true );
			$this->save_lead( $session_id, $lead );

			$this->logger->log(
				array(
					'channel' => 'lead',
					'status'  => $sent ? 'ok' : 'mail_failed',
					'reason'  => $sent ? 'lead_emailed' : 'lead_mail_failed',
					'ip_hash' => Logger::hash_ip( $ip ),
					'meta'    => array(
						'email' => (string) ( $lead['email'] ?? '' ),
						'name'  => (string) ( $lead['name'] ?? '' ),
					),
				)
			);
		}

		return array(
			'sent'    => $sent,
			'lead'    => $lead,
			'missing' => $missing,
		);
	}

	/**
	 * Fields still needed.
	 *
	 * @param array<string, mixed> $lead Lead data.
	 * @return array<int, string>
	 */
	public function missing_fields( array $lead ): array {
		$missing = array();
		if ( ! $this->enquiry_ready( $lead ) ) {
			$missing[] = 'enquiry';
		}
		if ( '' === trim( (string) ( $lead['name'] ?? '' ) ) ) {
			$missing[] = 'name';
		}
		if ( '' === trim( (string) ( $lead['email'] ?? '' ) ) || ! is_email( (string) ( $lead['email'] ?? '' ) ) ) {
			$missing[] = 'email';
		}
		if ( $this->settings->get( 'lead_require_phone', true ) ) {
			if ( '' === trim( (string) ( $lead['phone'] ?? '' ) ) ) {
				$missing[] = 'phone';
			}
		}
		return $missing;
	}

	/**
	 * Whether the enquiry has enough job detail to proceed to contact fields.
	 *
	 * @param array<string, mixed> $lead Lead.
	 */
	public function enquiry_ready( array $lead ): bool {
		$enquiry = trim( (string) ( $lead['enquiry'] ?? '' ) );
		if ( '' === $enquiry ) {
			return false;
		}
		return ! $this->is_thin_enquiry( $enquiry );
	}

	/**
	 * Load lead for session.
	 *
	 * @param string $session_id Session.
	 * @return array<string, mixed>
	 */
	public function get_lead( string $session_id ): array {
		$raw = get_transient( $this->key( $session_id ) );
		if ( ! is_array( $raw ) ) {
			return array(
				'name'    => '',
				'email'   => '',
				'phone'   => '',
				'enquiry' => '',
				'sent'    => false,
			);
		}
		return wp_parse_args(
			$raw,
			array(
				'name'    => '',
				'email'   => '',
				'phone'   => '',
				'enquiry' => '',
				'sent'    => false,
			)
		);
	}

	/**
	 * Recipient for lead emails (CF7 Mail To, or override).
	 */
	public function notify_email(): string {
		$override = sanitize_email( (string) $this->settings->get( 'lead_notify_email', '' ) );
		if ( is_email( $override ) ) {
			return $override;
		}

		$from_cf7 = $this->cf7_recipient();
		if ( is_email( $from_cf7 ) ) {
			return $from_cf7;
		}

		$admin = sanitize_email( (string) get_option( 'admin_email' ) );
		return is_email( $admin ) ? $admin : '';
	}

	/**
	 * Merge extracted fields from the latest message / history.
	 *
	 * @param array<string, mixed> $lead Lead.
	 * @param string               $message Latest user message.
	 * @param array<int, array{role:string,content:string}> $history History.
	 * @return array<string, mixed>
	 */
	private function merge_extracted( array $lead, string $message, array $history ): array {
		$email = $this->extract_email( $message );
		if ( $email && '' === (string) $lead['email'] ) {
			$lead['email'] = $email;
		}

		$phone = $this->extract_phone( $message );
		if ( $phone && '' === (string) $lead['phone'] ) {
			$lead['phone'] = $phone;
		}

		$name = $this->extract_name( $message, $history );
		if ( $name && '' === (string) $lead['name'] ) {
			$lead['name'] = $name;
		}

		$enquiry_bits = array();
		if ( '' !== trim( (string) $lead['enquiry'] ) ) {
			$enquiry_bits[] = trim( (string) $lead['enquiry'] );
		}
		$clean = $this->enquiry_fragment( $message );
		if ( '' !== $clean ) {
			$enquiry_bits[] = $clean;
		}
		$lead['enquiry'] = $this->truncate( implode( "\n", array_unique( $enquiry_bits ) ), 2000 );

		return $lead;
	}

	/**
	 * Vague “I need an electrician” style lines need a follow-up before contact details.
	 *
	 * @param string $enquiry Enquiry text.
	 */
	private function is_thin_enquiry( string $enquiry ): bool {
		$text = strtolower( trim( preg_replace( '/\s+/', ' ', $enquiry ) ?? $enquiry ) );
		if ( '' === $text ) {
			return true;
		}

		$hints = array(
			'rewir',
			'wiring',
			'install',
			'repair',
			'fix',
			'socket',
			'fuse',
			'light',
			'boiler',
			'leak',
			'quote',
			'bathroom',
			'kitchen',
			'room',
			'house',
			'consumer',
			'shower',
			'tap',
			'radiator',
			'fault',
			'emergency',
			'urgent',
			'inspect',
			'certificate',
			'cu ',
			'ebic',
			'pat ',
			'switch',
			'cooker',
			'oven',
			'extension',
			'outdoor',
			'garden',
		);
		foreach ( $hints as $hint ) {
			if ( false !== strpos( $text, $hint ) ) {
				return false;
			}
		}

		if ( str_word_count( $text ) >= 8 ) {
			return false;
		}

		$stripped = preg_replace( '/^(?:hi|hello|hey)[,!.\s]*/', '', $text ) ?? $text;
		$stripped = preg_replace( '/^(?:i need|i\'m looking for|looking for|need|want|can you|could you|help with|i would like|i\'d like)\s+/', '', $stripped ) ?? $stripped;
		$stripped = preg_replace( '/^(?:an?|the|some)\s+/', '', trim( $stripped ) ) ?? $stripped;
		$stripped = trim( $stripped, " \t.,!" );

		$trade = strtolower( trim( (string) $this->settings->get( 'business_trade', '' ) ) );
		if ( '' !== $trade && ( $stripped === $trade || $stripped === $trade . 's' || $text === $trade ) ) {
			return true;
		}

		$generic = array( 'electrician', 'electricians', 'plumber', 'plumbers', 'builder', 'builders', 'help', 'service', 'services' );
		if ( in_array( $stripped, $generic, true ) ) {
			return true;
		}

		return strlen( $stripped ) < 12;
	}

	/**
	 * Extract email address.
	 *
	 * @param string $text Text.
	 */
	private function extract_email( string $text ): string {
		if ( preg_match( '/[a-z0-9._%+\-]+@[a-z0-9.\-]+\.[a-z]{2,}/i', $text, $m ) ) {
			$email = sanitize_email( $m[0] );
			return is_email( $email ) ? $email : '';
		}
		return '';
	}

	/**
	 * Extract phone number (UK / international tolerant).
	 *
	 * @param string $text Text.
	 */
	private function extract_phone( string $text ): string {
		if ( preg_match( '/(?:(?:\+|00)\d{1,3}[\s\-]?)?(?:\(?0?\d{2,5}\)?[\s\-]?)?\d{3,4}[\s\-]?\d{3,4}(?:[\s\-]?\d{3,4})?/', $text, $m ) ) {
			$raw = preg_replace( '/[^\d+]/', '', $m[0] ) ?? '';
			$digits = preg_replace( '/\D/', '', $raw ) ?? '';
			if ( strlen( $digits ) < 10 || strlen( $digits ) > 15 ) {
				return '';
			}
			return sanitize_text_field( trim( $m[0] ) );
		}
		return '';
	}

	/**
	 * Extract a likely name from the message.
	 *
	 * @param string $message Message.
	 * @param array<int, array{role:string,content:string}> $history History.
	 */
	private function extract_name( string $message, array $history ): string {
		$text = trim( $message );

		if ( preg_match( '/\b(?:my name is|i am|i\'m|this is|it\'s|its)\s+([A-Za-z][A-Za-z\'\-]+(?:\s+[A-Za-z][A-Za-z\'\-]+){0,2})\b/i', $text, $m ) ) {
			return $this->sanitize_name( $m[1] );
		}

		if ( preg_match( '/\b(?:name[:\s]+)([A-Za-z][A-Za-z\'\-]+(?:\s+[A-Za-z][A-Za-z\'\-]+){0,2})\b/i', $text, $m ) ) {
			return $this->sanitize_name( $m[1] );
		}

		if ( preg_match( '/^([A-Za-z][A-Za-z\'\-]+(?:\s+[A-Za-z][A-Za-z\'\-]+){0,2})\s+here\.?$/i', $text, $m ) ) {
			return $this->sanitize_name( $m[1] );
		}

		$prev = $this->previous_assistant_message( $history );
		if ( $prev && preg_match( '/\b(name|called)\b/i', $prev ) ) {
			$candidate = $text;
			if ( preg_match( '/^(?:it\'s|its|i\'m|i am)\s+(.+)$/i', $candidate, $m ) ) {
				$candidate = trim( $m[1] );
			}
			if ( preg_match( '/^[A-Za-z][A-Za-z\'\-]+(?:\s+[A-Za-z][A-Za-z\'\-]+){0,2}\.?$/', $candidate ) ) {
				return $this->sanitize_name( rtrim( $candidate, '.' ) );
			}
		}

		return '';
	}

	/**
	 * Keep enquiry-ish text; drop pure contact replies.
	 *
	 * @param string $message Message.
	 */
	private function enquiry_fragment( string $message ): string {
		$text = trim( $message );
		if ( '' === $text ) {
			return '';
		}
		if ( $this->extract_email( $text ) && strlen( $text ) < 80 ) {
			return '';
		}
		if ( $this->extract_phone( $text ) && strlen( $text ) < 40 ) {
			return '';
		}
		if ( preg_match( '/^(?:my name is|i am|i\'m|name[:\s])/i', $text ) && strlen( $text ) < 60 ) {
			return '';
		}
		if ( preg_match( '/^[A-Za-z][A-Za-z\'\-]+(?:\s+[A-Za-z][A-Za-z\'\-]+){0,2}$/', $text ) ) {
			return '';
		}
		return $this->truncate( $text, 500 );
	}

	/**
	 * Previous assistant message before the latest user turn.
	 *
	 * @param array<int, array{role:string,content:string}> $history History.
	 */
	private function previous_assistant_message( array $history ): string {
		$reversed = array_reverse( $history );
		$seen_user = false;
		foreach ( $reversed as $row ) {
			if ( ! $seen_user && 'user' === ( $row['role'] ?? '' ) ) {
				$seen_user = true;
				continue;
			}
			if ( $seen_user && 'assistant' === ( $row['role'] ?? '' ) ) {
				return (string) ( $row['content'] ?? '' );
			}
		}
		return '';
	}

	/**
	 * @param string $name Name.
	 */
	private function sanitize_name( string $name ): string {
		$name = sanitize_text_field( $name );
		$name = preg_replace( '/\s+/', ' ', $name ) ?? $name;
		if ( strlen( $name ) < 2 || strlen( $name ) > 80 ) {
			return '';
		}
		$blocked = array( 'yes', 'no', 'hi', 'hello', 'thanks', 'thank you', 'ok', 'okay' );
		if ( in_array( strtolower( $name ), $blocked, true ) ) {
			return '';
		}
		return $name;
	}

	/**
	 * Email the business.
	 *
	 * @param array<string, mixed> $lead Lead.
	 * @param string               $ip   IP.
	 */
	private function send_notification( array $lead, string $ip ): bool {
		$to = $this->notify_email();
		if ( ! is_email( $to ) ) {
			return false;
		}

		$subject = (string) $this->settings->get( 'lead_email_subject', 'New chat enquiry' );
		$body    = $this->format_body( $lead, $ip );

		return $this->mailer->send_lead_notification( $to, $subject, $body, (string) ( $lead['email'] ?? '' ) );
	}

	/**
	 * Format notification body.
	 *
	 * @param array<string, mixed> $lead Lead.
	 * @param string               $ip   IP.
	 */
	private function format_body( array $lead, string $ip ): string {
		$lines   = array();
		$lines[] = 'New enquiry from the website chat:';
		$lines[] = '';
		$lines[] = 'Name: ' . (string) ( $lead['name'] ?? '' );
		$lines[] = 'Email: ' . (string) ( $lead['email'] ?? '' );
		$lines[] = 'Phone: ' . ( (string) ( $lead['phone'] ?? '' ) ?: '(not provided)' );
		$lines[] = '';
		$lines[] = 'Enquiry:';
		$lines[] = (string) ( $lead['enquiry'] ?? '' );
		$lines[] = '';
		$lines[] = 'Site: ' . home_url( '/' );
		$lines[] = 'Received: ' . current_time( 'mysql' );

		unset( $ip );
		return implode( "\n", $lines );
	}

	/**
	 * CF7 primary mail recipient for enabled forms.
	 */
	private function cf7_recipient(): string {
		if ( ! class_exists( 'WPCF7_ContactForm' ) ) {
			return '';
		}

		$forms = \WPCF7_ContactForm::find( array( 'posts_per_page' => 50 ) );
		if ( ! is_array( $forms ) ) {
			return '';
		}

		foreach ( $forms as $form ) {
			$form_id = (int) $form->id();
			$ids     = $this->settings->get( 'cf7_form_ids', array() );
			if ( is_array( $ids ) && ! empty( $ids ) ) {
				$ids = array_map( 'intval', $ids );
				if ( ! in_array( $form_id, $ids, true ) ) {
					continue;
				}
			}
			$mail = $form->prop( 'mail' );
			if ( ! is_array( $mail ) ) {
				continue;
			}
			$recipient = (string) ( $mail['recipient'] ?? '' );
			$recipient = trim( preg_replace( '/\[[^\]]+\]/', '', $recipient ) ?? $recipient );
			// Prefer a concrete email over mail-tags alone.
			if ( preg_match( '/[a-z0-9._%+\-]+@[a-z0-9.\-]+\.[a-z]{2,}/i', $recipient, $m ) ) {
				$email = sanitize_email( $m[0] );
				if ( is_email( $email ) ) {
					return $email;
				}
			}
		}

		return '';
	}

	/**
	 * @param string $session_id Session.
	 * @param array<string, mixed> $lead Lead.
	 */
	private function save_lead( string $session_id, array $lead ): void {
		set_transient( $this->key( $session_id ), $lead, DAY_IN_SECONDS );
	}

	/**
	 * @param string $session_id Session.
	 */
	private function key( string $session_id ): string {
		return 'qbmbot_lead_' . md5( $session_id );
	}

	/**
	 * @param string $text Text.
	 * @param int    $max  Max length.
	 */
	private function truncate( string $text, int $max ): string {
		if ( strlen( $text ) <= $max ) {
			return $text;
		}
		return rtrim( substr( $text, 0, $max - 1 ) ) . '…';
	}
}
