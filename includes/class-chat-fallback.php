<?php
/**
 * Offline chat replies when AI is unavailable.
 *
 * @package QBMBot
 */

declare(strict_types=1);

namespace QBMBot;

/**
 * Builds helpful replies from the business profile without calling an LLM.
 */
final class Chat_Fallback {

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
	 * Generate a fallback chat reply for a visitor message.
	 *
	 * @param string                    $message     Visitor message.
	 * @param array<string, mixed>|null $lead        Lead state after this turn.
	 * @param array<string, mixed>|null $lead_before Lead state before this turn.
	 */
	public function reply( string $message, ?array $lead = null, ?array $lead_before = null ): string {
		if ( $this->settings->get( 'lead_capture_enabled', true ) && is_array( $lead ) ) {
			return $this->lead_reply( $message, $lead, is_array( $lead_before ) ? $lead_before : array() );
		}

		return $this->intro_or_generic( $message );
	}

	/**
	 * Conversational lead-capture replies: short ack + one next question.
	 *
	 * @param string               $message     Message.
	 * @param array<string, mixed> $lead        Lead after turn.
	 * @param array<string, mixed> $lead_before Lead before turn.
	 */
	private function lead_reply( string $message, array $lead, array $lead_before ): string {
		if ( ! empty( $lead['sent'] ) ) {
			$name = trim( (string) ( $lead['name'] ?? '' ) );
			if ( '' !== $name ) {
				return sprintf(
					/* translators: %s: visitor first name */
					__( 'Thanks %s — we have your details and the team will be in touch soon.', 'qbmbot' ),
					$this->first_name( $name )
				);
			}
			return __( 'Thanks — we have your details and the team will be in touch soon.', 'qbmbot' );
		}

		$filled = $this->fields_just_filled( $lead_before, $lead );
		$ask    = $this->next_lead_question( $lead );
		$ack    = $this->acknowledgment( $message, $lead, $filled );

		if ( '' === $ask ) {
			return $ack !== '' ? $ack : $this->intro_or_generic( $message );
		}

		if ( '' === $ack ) {
			return $ask;
		}

		return trim( $ack . ' ' . $ask );
	}

	/**
	 * Short acknowledgment for what the visitor just shared.
	 *
	 * @param string               $message Message.
	 * @param array<string, mixed> $lead    Lead after.
	 * @param array<int, string>   $filled  Fields filled this turn.
	 */
	private function acknowledgment( string $message, array $lead, array $filled ): string {
		$name = trim( (string) ( $lead['name'] ?? '' ) );

		if ( in_array( 'name', $filled, true ) && '' !== $name ) {
			return sprintf(
				/* translators: %s: visitor first name */
				__( 'Thanks, %s.', 'qbmbot' ),
				$this->first_name( $name )
			);
		}

		if ( in_array( 'email', $filled, true ) ) {
			return __( 'Got it, thanks.', 'qbmbot' );
		}

		if ( in_array( 'phone', $filled, true ) ) {
			return __( 'Perfect, thanks.', 'qbmbot' );
		}

		if ( in_array( 'enquiry', $filled, true ) ) {
			$enquiry = trim( (string) ( $lead['enquiry'] ?? '' ) );
			$snippet = $this->short_job_label( $enquiry !== '' ? $enquiry : $message );
			if ( '' !== $snippet ) {
				return sprintf(
					/* translators: %s: short job summary */
					__( 'Thanks — %s is something we can help with.', 'qbmbot' ),
					$snippet
				);
			}
			return __( 'Thanks, that helps.', 'qbmbot' );
		}

		// First useful turn: introduce the business briefly when they mention the trade/area.
		$trade    = trim( (string) $this->settings->get( 'business_trade', '' ) );
		$services = trim( (string) $this->settings->get( 'business_services', '' ) );
		$area     = trim( (string) $this->settings->get( 'business_service_area', '' ) );
		$needle   = strtolower( $message );

		if ( $this->mentions_service( $needle, $trade, $services ) ) {
			$parts   = array();
			$parts[] = sprintf(
				/* translators: 1: business name, 2: trade/services */
				__( 'Thanks for getting in touch — %1$s can help with %2$s.', 'qbmbot' ),
				$this->business_name(),
				$this->service_summary( $trade, $services )
			);
			if ( '' !== $area ) {
				$parts[] = sprintf(
					/* translators: %s: service area */
					__( 'We cover %s.', 'qbmbot' ),
					$area
				);
			}
			return implode( ' ', $parts );
		}

		if ( $this->mentions_area( $needle, $area ) ) {
			return sprintf(
				/* translators: 1: business name, 2: service area */
				__( 'Thanks — %1$s covers %2$s.', 'qbmbot' ),
				$this->business_name(),
				$area
			);
		}

		return '';
	}

	/**
	 * Ask for the next missing lead field only.
	 *
	 * @param array<string, mixed> $lead Lead.
	 */
	private function next_lead_question( array $lead ): string {
		$enquiry = trim( (string) ( $lead['enquiry'] ?? '' ) );
		if ( '' === $enquiry || $this->is_thin_enquiry( $enquiry ) ) {
			return __( 'Could you tell us a little more about what you need help with?', 'qbmbot' );
		}
		if ( '' === trim( (string) ( $lead['name'] ?? '' ) ) ) {
			return __( 'What name should we use for this enquiry?', 'qbmbot' );
		}
		if ( '' === trim( (string) ( $lead['email'] ?? '' ) ) || ! is_email( (string) ( $lead['email'] ?? '' ) ) ) {
			return __( 'What is the best email address to reach you on?', 'qbmbot' );
		}
		if ( $this->settings->get( 'lead_require_phone', true ) && '' === trim( (string) ( $lead['phone'] ?? '' ) ) ) {
			return __( 'And what is the best phone number to call you on?', 'qbmbot' );
		}
		return __( 'Thanks — we will pass this to the team now.', 'qbmbot' );
	}

	/**
	 * Mirror Lead_Capture thin-enquiry check for offline flow.
	 *
	 * @param string $enquiry Enquiry.
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
	 * Fields that became non-empty this turn.
	 *
	 * @param array<string, mixed> $before Before.
	 * @param array<string, mixed> $after  After.
	 * @return array<int, string>
	 */
	private function fields_just_filled( array $before, array $after ): array {
		$fields = array( 'name', 'email', 'phone', 'enquiry' );
		$filled = array();
		foreach ( $fields as $field ) {
			$was = trim( (string) ( $before[ $field ] ?? '' ) );
			$now = trim( (string) ( $after[ $field ] ?? '' ) );
			if ( '' === $was && '' !== $now ) {
				$filled[] = $field;
			} elseif ( 'enquiry' === $field && '' !== $now && $now !== $was && strlen( $now ) > strlen( $was ) + 8 ) {
				// Enquiry grew meaningfully (new detail).
				$filled[] = 'enquiry';
			}
		}
		return $filled;
	}

	/**
	 * Opening / non-lead generic reply.
	 *
	 * @param string $message Message.
	 */
	private function intro_or_generic( string $message ): string {
		$trade    = trim( (string) $this->settings->get( 'business_trade', '' ) );
		$services = trim( (string) $this->settings->get( 'business_services', '' ) );
		$area     = trim( (string) $this->settings->get( 'business_service_area', '' ) );
		$needle   = strtolower( $message );

		if ( $this->mentions_service( $needle, $trade, $services ) ) {
			$out = sprintf(
				/* translators: 1: business name, 2: trade/services */
				__( 'Thanks for getting in touch — %1$s can help with %2$s.', 'qbmbot' ),
				$this->business_name(),
				$this->service_summary( $trade, $services )
			);
			if ( '' !== $area ) {
				$out .= ' ' . sprintf(
					/* translators: %s: service area */
					__( 'We cover %s.', 'qbmbot' ),
					$area
				);
			}
			return $out;
		}

		return (string) $this->settings->get(
			'chat_fallback_message',
			__( 'Thanks for your message. How can we help today?', 'qbmbot' )
		);
	}

	/**
	 * Business display name.
	 */
	private function business_name(): string {
		$name = trim( (string) $this->settings->get( 'business_name', '' ) );
		if ( '' !== $name ) {
			return $name;
		}
		return wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES );
	}

	/**
	 * First name token for friendly thanks.
	 *
	 * @param string $name Full name.
	 */
	private function first_name( string $name ): string {
		$parts = preg_split( '/\s+/', trim( $name ) ) ?: array();
		$first = (string) ( $parts[0] ?? $name );
		return $first !== '' ? $first : $name;
	}

	/**
	 * Compact job label for acknowledgments.
	 *
	 * @param string $text Enquiry text.
	 */
	private function short_job_label( string $text ): string {
		$text = trim( preg_replace( '/\s+/', ' ', $text ) ?? $text );
		$text = preg_replace( '/^(?:i need|i\'m looking for|looking for|need|want|can you|could you)\s+/i', '', $text ) ?? $text;
		$text = trim( $text, " \t\n\r\0\x0B.," );
		if ( '' === $text ) {
			return '';
		}
		if ( strlen( $text ) > 60 ) {
			$text = rtrim( substr( $text, 0, 57 ) ) . '…';
		}
		return lcfirst( $text );
	}

	/**
	 * Short label for services/trade.
	 *
	 * @param string $trade    Trade.
	 * @param string $services Services list.
	 */
	private function service_summary( string $trade, string $services ): string {
		if ( '' !== $trade ) {
			return $trade;
		}
		if ( '' !== $services ) {
			$lines = preg_split( '/\r\n|\r|\n/', $services ) ?: array();
			$first = trim( (string) ( $lines[0] ?? '' ) );
			if ( '' !== $first ) {
				return $first;
			}
		}
		return __( 'our services', 'qbmbot' );
	}

	/**
	 * Whether the message mentions the business service area.
	 *
	 * @param string $message Lowercased message.
	 * @param string $area    Service area text.
	 */
	private function mentions_area( string $message, string $area ): bool {
		if ( '' === $area ) {
			return false;
		}

		$parts = preg_split( '/[\r\n,;/|]+/', strtolower( $area ) ) ?: array();
		foreach ( $parts as $part ) {
			$part = trim( $part );
			if ( strlen( $part ) < 3 ) {
				continue;
			}
			if ( false !== strpos( $message, $part ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Whether the message mentions offered services/trade.
	 *
	 * @param string $message  Lowercased message.
	 * @param string $trade    Trade.
	 * @param string $services Services list.
	 */
	private function mentions_service( string $message, string $trade, string $services ): bool {
		$terms = array();

		if ( '' !== $trade ) {
			$terms[] = strtolower( $trade );
		}

		foreach ( preg_split( '/[\r\n,;/|]+/', strtolower( $services ) ) ?: array() as $line ) {
			$line = trim( $line );
			if ( strlen( $line ) >= 3 ) {
				$terms[] = $line;
			}
		}

		$corpus = strtolower( $trade . ' ' . $services );
		if ( '' !== $corpus ) {
			foreach ( array_unique( $terms ) as $term ) {
				if ( strlen( $term ) >= 4 && false !== strpos( $message, $term ) ) {
					return true;
				}
			}

			$pairs = array(
				array( 'electrician', 'electric' ),
				array( 'electrical', 'electric' ),
				array( 'rewiring', 'electric' ),
				array( 'rewire', 'electric' ),
				array( 'plumber', 'plumb' ),
				array( 'plumbing', 'plumb' ),
				array( 'heating', 'heat' ),
				array( 'builder', 'build' ),
			);
			foreach ( $pairs as $pair ) {
				if ( false !== strpos( $message, $pair[0] ) && false !== strpos( $corpus, $pair[1] ) ) {
					return true;
				}
			}
		}

		return false;
	}
}
