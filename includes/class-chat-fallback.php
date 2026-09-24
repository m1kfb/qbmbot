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
	 * Job intake.
	 *
	 * @var Job_Intake
	 */
	private Job_Intake $intake;

	/**
	 * FAQ store.
	 *
	 * @var FAQ_Store|null
	 */
	private ?FAQ_Store $faq;

	/**
	 * Constructor.
	 *
	 * @param Settings        $settings Settings.
	 * @param Job_Intake|null $intake   Job intake.
	 * @param FAQ_Store|null  $faq      FAQ store.
	 */
	public function __construct( Settings $settings, ?Job_Intake $intake = null, ?FAQ_Store $faq = null ) {
		$this->settings = $settings;
		$this->intake   = $intake ?? new Job_Intake();
		$this->faq      = $faq;
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

		$answered = $this->answer_question( $message );
		return '' !== $answered ? $answered : $this->intro_or_generic( $message );
	}

	/**
	 * Conversational lead-capture replies: answer questions first, then advance the lead when appropriate.
	 *
	 * @param string               $message     Message.
	 * @param array<string, mixed> $lead        Lead after turn.
	 * @param array<string, mixed> $lead_before Lead before turn.
	 */
	private function lead_reply( string $message, array $lead, array $lead_before ): string {
		$was_sent = ! empty( $lead_before['sent'] );
		$now_sent = ! empty( $lead['sent'] );

		if ( $now_sent ) {
			return $this->post_capture_reply( $message, $lead, $was_sent );
		}

		$answered = $this->answer_question( $message );
		$collect  = $this->intake->should_collect_contact( $message, $lead );

		// Informational / FAQ turns: answer and stop — do not push for name/email.
		if ( '' !== $answered && ! $collect ) {
			return $answered;
		}

		$filled = $this->fields_just_filled( $lead_before, $lead );
		$ask    = $collect ? $this->next_lead_question( $lead ) : '';
		$ack    = '' !== $answered
			? $answered
			: $this->acknowledgment( $message, $lead, $lead_before, $filled );

		if ( '' === $ask ) {
			if ( '' !== $ack ) {
				return $ack;
			}
			return $this->intro_or_generic( $message );
		}

		if ( '' === $ack ) {
			return $ask;
		}

		return trim( $ack . ' ' . $ask );
	}

	/**
	 * Answer from FAQ or business profile when the visitor asked something we can resolve offline.
	 *
	 * @param string $message Message.
	 */
	private function answer_question( string $message ): string {
		if ( null !== $this->faq ) {
			$item = $this->faq->match_question( $message );
			if ( is_array( $item ) && '' !== trim( (string) ( $item['answer'] ?? '' ) ) ) {
				return trim( (string) $item['answer'] );
			}
		}

		if ( $this->intake->is_pricing_question( $message ) ) {
			return __( "We can't give a firm price here as it depends on the job, but if you share a few details we can arrange for a team member to follow up.", 'qbmbot' );
		}

		$trade    = trim( (string) $this->settings->get( 'business_trade', '' ) );
		$services = trim( (string) $this->settings->get( 'business_services', '' ) );
		$area     = trim( (string) $this->settings->get( 'business_service_area', '' ) );
		$notes    = trim( (string) $this->settings->get( 'business_notes', '' ) );
		$needle   = strtolower( $message );

		if ( $this->asks_about_hours( $needle ) ) {
			$from_notes = $this->notes_snippet( $notes, array( 'hour', 'open', 'monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday', 'sunday', 'am', 'pm' ) );
			if ( '' !== $from_notes ) {
				return $from_notes;
			}
		}

		if ( $this->mentions_area( $needle, $area ) || $this->asks_about_area( $needle ) ) {
			if ( '' !== $area ) {
				return sprintf(
					/* translators: 1: business name, 2: service area */
					__( '%1$s covers %2$s.', 'qbmbot' ),
					$this->business_name(),
					$area
				);
			}
		}

		if ( $this->mentions_service( $needle, $trade, $services ) || $this->asks_about_services( $needle ) ) {
			$parts   = array();
			$parts[] = sprintf(
				/* translators: 1: business name, 2: trade/services */
				__( '%1$s can help with %2$s.', 'qbmbot' ),
				$this->business_name(),
				$this->service_summary( $trade, $services )
			);
			if ( '' !== $services && false === stripos( $parts[0], $services ) ) {
				$parts[] = $services;
			}
			if ( '' !== $area ) {
				$parts[] = sprintf(
					/* translators: %s: service area */
					__( 'We cover %s.', 'qbmbot' ),
					$area
				);
			}
			return implode( ' ', array_filter( $parts ) );
		}

		if ( $this->intake->is_informational_question( $message ) && '' !== $notes ) {
			$snippet = $this->notes_snippet( $notes, preg_split( '/\s+/', $needle ) ?: array() );
			if ( '' !== $snippet ) {
				return $snippet;
			}
		}

		return '';
	}

	/**
	 * Whether the message asks about opening hours.
	 *
	 * @param string $message Lowercased message.
	 */
	private function asks_about_hours( string $message ): bool {
		return (bool) preg_match( '/\b(hours|open|opening|closed|what time)\b/i', $message );
	}

	/**
	 * Whether the message asks about coverage area.
	 *
	 * @param string $message Lowercased message.
	 */
	private function asks_about_area( string $message ): bool {
		return (bool) preg_match( '/\b(cover|coverage|service area|areas? covered|do you come|near me|in my area)\b/i', $message );
	}

	/**
	 * Whether the message asks what services are offered.
	 *
	 * @param string $message Lowercased message.
	 */
	private function asks_about_services( string $message ): bool {
		return (bool) preg_match( '/\b(what (do|services)|services|what can you|do you (do|offer|provide)|offer)\b/i', $message );
	}

	/**
	 * Pull a relevant line from business notes.
	 *
	 * @param string            $notes Notes text.
	 * @param array<int, string> $needles Keywords.
	 */
	private function notes_snippet( string $notes, array $needles ): string {
		if ( '' === $notes ) {
			return '';
		}
		$lines = preg_split( '/\r\n|\r|\n/', $notes ) ?: array();
		foreach ( $lines as $line ) {
			$line = trim( $line );
			if ( '' === $line ) {
				continue;
			}
			$hay = strtolower( $line );
			foreach ( $needles as $needle ) {
				$needle = strtolower( trim( (string) $needle ) );
				if ( strlen( $needle ) < 2 ) {
					continue;
				}
				if ( false !== strpos( $hay, $needle ) ) {
					return $line;
				}
			}
		}
		return '';
	}

	/**
	 * Replies after the lead email has already been sent.
	 *
	 * @param string               $message  Message.
	 * @param array<string, mixed> $lead     Lead.
	 * @param bool                 $was_sent Already sent before this turn.
	 */
	private function post_capture_reply( string $message, array $lead, bool $was_sent ): string {
		$name = $this->first_name( trim( (string) ( $lead['name'] ?? '' ) ) );

		$answered = $this->answer_question( $message );
		if ( '' !== $answered && ! $this->intake->is_pricing_question( $message ) ) {
			return $answered;
		}

		if ( $this->intake->is_pricing_question( $message ) ) {
			$base = __( "We can't give a firm price here as it depends on the job, but a member of the team will be in touch shortly with more information.", 'qbmbot' );
			if ( '' !== $name && ! $was_sent ) {
				return sprintf(
					/* translators: 1: first name, 2: pricing reply */
					__( 'Thanks %1$s — %2$s', 'qbmbot' ),
					$name,
					lcfirst( $base )
				);
			}
			return $base;
		}

		if ( ! $was_sent ) {
			if ( '' !== $name ) {
				return sprintf(
					/* translators: %s: visitor first name */
					__( 'Thanks %s — we have your details and the team will be in touch soon.', 'qbmbot' ),
					$name
				);
			}
			return __( 'Thanks — we have your details and the team will be in touch soon.', 'qbmbot' );
		}

		return __( 'A member of the team already has your details and will cover that when they get in touch. Is there anything else we should note for them?', 'qbmbot' );
	}

	/**
	 * Short acknowledgment for what the visitor just shared.
	 *
	 * @param string               $message     Message.
	 * @param array<string, mixed> $lead        Lead after.
	 * @param array<string, mixed> $lead_before Lead before.
	 * @param array<int, string>   $filled      Fields filled this turn.
	 */
	private function acknowledgment( string $message, array $lead, array $lead_before, array $filled ): string {
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

		$pending_before = $this->intake->pending_keys( $lead_before );
		$pending_after  = $this->intake->pending_keys( $lead );
		$answered_detail = ! empty( $pending_before ) && count( $pending_after ) < count( $pending_before );

		if ( $answered_detail ) {
			return __( 'Thanks, that helps.', 'qbmbot' );
		}

		$job_just_set = '' === trim( (string) ( $lead_before['job_type'] ?? '' ) )
			&& '' !== trim( (string) ( $lead['job_type'] ?? '' ) );

		if ( $job_just_set || ( in_array( 'enquiry', $filled, true ) && '' !== trim( (string) ( $lead['job_type'] ?? '' ) ) ) ) {
			$label = $this->intake->short_label( $lead, $message );
			return sprintf(
				/* translators: %s: short job summary */
				__( 'Thanks — %s is something we can help with.', 'qbmbot' ),
				$label
			);
		}

		if ( in_array( 'enquiry', $filled, true ) ) {
			return __( 'Thanks, that helps.', 'qbmbot' );
		}

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
	 * Ask for the next missing lead field / job detail only.
	 *
	 * @param array<string, mixed> $lead Lead.
	 */
	private function next_lead_question( array $lead ): string {
		$enquiry = trim( (string) ( $lead['enquiry'] ?? '' ) );
		if ( '' === $enquiry || $this->is_thin_enquiry( $enquiry ) ) {
			return __( 'Could you tell us a little more about what you need help with?', 'qbmbot' );
		}

		$detail = $this->intake->next_question( $lead );
		if ( '' !== $detail ) {
			return $detail;
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
	 * Vague “I need an electrician” style lines need a follow-up before contact details.
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
