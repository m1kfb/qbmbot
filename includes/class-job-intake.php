<?php
/**
 * Job-specific intake questions for chat leads.
 *
 * @package QBMBot
 */

declare(strict_types=1);

namespace QBMBot;

/**
 * Matches job types and returns follow-up detail questions.
 */
final class Job_Intake {

	/**
	 * Detect a job profile from enquiry text.
	 *
	 * @param string $enquiry Enquiry text.
	 * @return array{id:string,label:string,questions:array<string,string>}|null
	 */
	public function match( string $enquiry ): ?array {
		$text = strtolower( $enquiry );
		foreach ( $this->profiles() as $profile ) {
			foreach ( $profile['match'] as $needle ) {
				if ( false !== strpos( $text, $needle ) ) {
					return array(
						'id'        => $profile['id'],
						'label'     => $profile['label'],
						'questions' => $profile['questions'],
					);
				}
			}
		}
		return null;
	}

	/**
	 * Remaining detail question keys for a lead.
	 *
	 * @param array<string, mixed> $lead Lead.
	 * @return array<int, string>
	 */
	public function pending_keys( array $lead ): array {
		$pending = $lead['detail_pending'] ?? null;
		if ( ! is_array( $pending ) ) {
			return array();
		}
		$out = array();
		foreach ( $pending as $key ) {
			$key = sanitize_key( (string) $key );
			if ( '' !== $key ) {
				$out[] = $key;
			}
		}
		return array_values( array_unique( $out ) );
	}

	/**
	 * Next detail question text, or empty.
	 *
	 * @param array<string, mixed> $lead Lead.
	 */
	public function next_question( array $lead ): string {
		$pending = $this->pending_keys( $lead );
		if ( empty( $pending ) ) {
			return '';
		}
		$key      = $pending[0];
		$catalog  = $this->question_catalog();
		$job_id   = sanitize_key( (string) ( $lead['job_type'] ?? '' ) );
		$profile  = null;
		foreach ( $this->profiles() as $row ) {
			if ( $row['id'] === $job_id ) {
				$profile = $row;
				break;
			}
		}
		if ( is_array( $profile ) && isset( $profile['questions'][ $key ] ) ) {
			return (string) $profile['questions'][ $key ];
		}
		return (string) ( $catalog[ $key ] ?? '' );
	}

	/**
	 * Whether job detail intake is finished (or not required).
	 *
	 * @param array<string, mixed> $lead Lead.
	 */
	public function details_complete( array $lead ): bool {
		if ( ! empty( $lead['details_complete'] ) ) {
			return true;
		}
		// Initialised with an empty queue after volunteering enough detail.
		if ( isset( $lead['detail_pending'] ) && is_array( $lead['detail_pending'] ) && empty( $lead['detail_pending'] ) ) {
			return true;
		}
		return empty( $this->pending_keys( $lead ) ) && ! empty( $lead['job_type'] );
	}

	/**
	 * Initialise / advance detail questions for this turn.
	 *
	 * @param array<string, mixed> $lead    Lead after basic merge.
	 * @param string               $message Latest user message.
	 * @param string               $prev_assistant Previous assistant message.
	 * @return array<string, mixed>
	 */
	public function advance( array $lead, string $message, string $prev_assistant ): array {
		$enquiry = trim( (string) ( $lead['enquiry'] ?? '' ) );
		if ( '' === $enquiry ) {
			return $lead;
		}

		if ( empty( $lead['job_type'] ) ) {
			$profile = $this->match( $enquiry );
			if ( null === $profile ) {
				// Generic practical follow-up once we have a non-empty job line.
				$lead['job_type']         = 'general';
				$lead['job_label']        = 'this job';
				$lead['detail_pending']   = array( 'general_detail' );
				$lead['details_complete'] = false;
			} else {
				$lead['job_type']         = $profile['id'];
				$lead['job_label']        = $profile['label'];
				$lead['detail_pending']   = array_keys( $profile['questions'] );
				$lead['details_complete'] = false;
				// Drop questions already answered in the opening message.
				$lead['detail_pending'] = $this->filter_answered( $lead['detail_pending'], $enquiry, $profile['questions'] );
			}
		}

		$pending = $this->pending_keys( $lead );
		if ( empty( $pending ) ) {
			$lead['detail_pending']   = array();
			$lead['details_complete'] = true;
			return $lead;
		}

		// If the assistant just asked a detail question, treat this user reply as the answer.
		$current_key = $pending[0];
		$asked       = $this->assistant_asked_detail( $prev_assistant, $current_key, $lead );
		if ( $asked && $this->looks_like_detail_answer( $message ) ) {
			array_shift( $pending );
			$lead['detail_pending'] = array_values( $pending );
			if ( empty( $pending ) ) {
				$lead['details_complete'] = true;
			}
			return $lead;
		}

		// Opening message (or a rich reply) may already cover later questions.
		$profile_questions = $this->questions_for_lead( $lead );
		$lead['detail_pending'] = $this->filter_answered( $pending, $enquiry . "\n" . $message, $profile_questions );
		if ( empty( $lead['detail_pending'] ) ) {
			$lead['details_complete'] = true;
		}

		return $lead;
	}

	/**
	 * Friendly short label for acknowledgments.
	 *
	 * @param array<string, mixed> $lead    Lead.
	 * @param string               $message Latest message fallback.
	 */
	public function short_label( array $lead, string $message = '' ): string {
		$label = trim( (string) ( $lead['job_label'] ?? '' ) );
		if ( '' !== $label && 'this job' !== $label ) {
			return $label;
		}
		$text = trim( preg_replace( '/\s+/', ' ', $message ) ?? $message );
		$text = preg_replace( '/^(?:i need|i\'m looking for|looking for|need|want|can you|could you)\s+/i', '', $text ) ?? $text;
		$text = trim( $text, " \t\n\r\0\x0B.," );
		if ( '' === $text ) {
			return __( 'that', 'qbmbot' );
		}
		if ( strlen( $text ) > 48 ) {
			$text = rtrim( substr( $text, 0, 45 ) ) . '…';
		}
		return lcfirst( $text );
	}

	/**
	 * Whether the visitor is asking about price/cost.
	 *
	 * @param string $message Message.
	 */
	public function is_pricing_question( string $message ): bool {
		$text = strtolower( $message );
		return (bool) preg_match( '/\b(how much|price|priced|pricing|cost|costs|quote|estimate|fee|charge|rates?)\b/i', $text );
	}

	/**
	 * Whether the message is mainly an informational question (not a booking request).
	 *
	 * @param string $message Message.
	 */
	public function is_informational_question( string $message ): bool {
		$text = strtolower( trim( $message ) );
		if ( '' === $text ) {
			return false;
		}

		// Booking / call-out intent wins over informational wording.
		if ( $this->is_booking_intent( $text ) ) {
			return false;
		}

		// Concrete job descriptions are enquiries, even if phrased with a question mark.
		if ( null !== $this->match( $text ) ) {
			return false;
		}

		if ( $this->is_pricing_question( $text ) ) {
			return true;
		}

		$patterns = array(
			'/\b(what (are|is)|whats|what\'s|when (are|do|is)|where (are|do|is)|who (are|is)|how (do|does|long|far|often)|do you|does your|are you|can you cover|which areas?|opening hours|what time|about (you|the business|your))\b/i',
			'/\b(hours|open|closed|coverage|cover|service area|areas? covered|insured|insured\?|qualified|guarantee|warranty|emergency|call.?out|response time|how it works|process|faq)\b/i',
		);
		foreach ( $patterns as $pattern ) {
			if ( preg_match( $pattern, $text ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Whether the visitor wants a quote, booking, visit, or callback.
	 *
	 * @param string $message Message.
	 */
	public function is_booking_intent( string $message ): bool {
		$text = strtolower( $message );
		return (bool) preg_match(
			'/\b(book|booking|arrange|schedule|call me|call back|callback|get in touch|come (out|round|over)|site visit|please contact|take my (details|number|email)|i need (an? )?(electrician|plumber|builder|engineer)|can you (come|help|do|fix|install|rewire))\b/i',
			$text
		);
	}

	/**
	 * Whether contact details should be requested this turn.
	 *
	 * @param string               $message Message.
	 * @param array<string, mixed> $lead    Lead after this turn.
	 */
	public function should_collect_contact( string $message, array $lead ): bool {
		if ( ! empty( $lead['sent'] ) ) {
			return false;
		}

		$enquiry_ready = ! empty( $lead['details_complete'] )
			|| ( isset( $lead['detail_pending'] ) && is_array( $lead['detail_pending'] ) && empty( $lead['detail_pending'] ) && ! empty( $lead['job_type'] ) );

		$awaiting_contact = $enquiry_ready
			&& (
				'' === trim( (string) ( $lead['name'] ?? '' ) )
				|| '' === trim( (string) ( $lead['email'] ?? '' ) )
				|| '' === trim( (string) ( $lead['phone'] ?? '' ) )
			);

		if ( $awaiting_contact && ! $this->is_informational_question( $message ) ) {
			return true;
		}

		if ( $this->is_booking_intent( $message ) ) {
			return true;
		}

		if ( $this->is_informational_question( $message ) ) {
			return false;
		}

		// Practical job detail still being collected.
		if ( ! empty( $this->pending_keys( $lead ) ) ) {
			return true;
		}

		$enquiry = trim( (string) ( $lead['enquiry'] ?? '' ) );
		return '' !== $enquiry && null !== $this->match( $enquiry );
	}

	/**
	 * Job profiles with ordered follow-up questions.
	 *
	 * @return array<int, array{id:string,label:string,match:array<int,string>,questions:array<string,string>}>
	 */
	private function profiles(): array {
		return array(
			array(
				'id'        => 'rewiring',
				'label'     => 'a room rewire',
				'match'     => array( 'rewir', 'full rewire', 're-wire', 're wire' ),
				'questions' => array(
					'room_use' => __( 'What is the room mainly used for (bedroom, kitchen, office, etc.)?', 'qbmbot' ),
					'sockets'  => __( 'Roughly how many sockets and light points do you need in there?', 'qbmbot' ),
					'property' => __( 'Is this a house, flat, or commercial property?', 'qbmbot' ),
				),
			),
			array(
				'id'        => 'sockets',
				'label'     => 'extra sockets / electrics',
				'match'     => array( 'socket', 'plug point', 'usb outlet' ),
				'questions' => array(
					'count'    => __( 'How many extra sockets do you need, and in which rooms?', 'qbmbot' ),
					'property' => __( 'Is this a house, flat, or commercial property?', 'qbmbot' ),
				),
			),
			array(
				'id'        => 'consumer_unit',
				'label'     => 'a consumer unit / fuse board upgrade',
				'match'     => array( 'consumer unit', 'fuse board', 'fuseboard', 'fuse box', 'distribution board' ),
				'questions' => array(
					'age'      => __( 'Do you know roughly how old the current board is, or has it been flagged on an inspection?', 'qbmbot' ),
					'property' => __( 'Is this a house, flat, or commercial property?', 'qbmbot' ),
				),
			),
			array(
				'id'        => 'lighting',
				'label'     => 'lighting work',
				'match'     => array( 'light fitting', 'downlight', 'spotlight', 'outdoor light', 'lighting' ),
				'questions' => array(
					'scope'    => __( 'Is this replacing existing lights, or adding new points?', 'qbmbot' ),
					'rooms'    => __( 'Which rooms or areas are involved?', 'qbmbot' ),
				),
			),
			array(
				'id'        => 'ev_charger',
				'label'     => 'an EV charger install',
				'match'     => array( 'ev charger', 'electric car', 'wallbox', 'vehicle charger' ),
				'questions' => array(
					'parking'  => __( 'Where will the car usually park (driveway, garage, on-street)?', 'qbmbot' ),
					'supply'   => __( 'Do you already have a spare way on the consumer unit, or has an electrician checked the supply?', 'qbmbot' ),
				),
			),
			array(
				'id'        => 'boiler',
				'label'     => 'boiler / heating work',
				'match'     => array( 'boiler', 'central heating', 'radiator', 'combi' ),
				'questions' => array(
					'issue'    => __( 'Is this a repair, service, or a new install/replacement?', 'qbmbot' ),
					'urgency'  => __( 'Do you have hot water and heating at the moment, or is it urgent?', 'qbmbot' ),
				),
			),
			array(
				'id'        => 'leak',
				'label'     => 'a leak / plumbing issue',
				'match'     => array( 'leak', 'burst', 'drip', 'flood' ),
				'questions' => array(
					'where'    => __( 'Where is the leak (kitchen, bathroom, under floor, outside)?', 'qbmbot' ),
					'urgency'  => __( 'Is water still running / do you need someone out urgently?', 'qbmbot' ),
				),
			),
			array(
				'id'        => 'bathroom',
				'label'     => 'bathroom work',
				'match'     => array( 'bathroom', 'shower', 'wet room' ),
				'questions' => array(
					'scope'    => __( 'Is this a full bathroom refit, or a repair/replace of specific items?', 'qbmbot' ),
					'access'   => __( 'Is the property a house or flat, and which floor is the bathroom on?', 'qbmbot' ),
				),
			),
		);
	}

	/**
	 * @return array<string, string>
	 */
	private function question_catalog(): array {
		$catalog = array(
			'general_detail' => __( 'Could you share a bit more detail — which room or area, rough size of the job, and anything urgent?', 'qbmbot' ),
		);
		foreach ( $this->profiles() as $profile ) {
			foreach ( $profile['questions'] as $key => $question ) {
				$catalog[ $key ] = $question;
			}
		}
		return $catalog;
	}

	/**
	 * @param array<string, mixed> $lead Lead.
	 * @return array<string, string>
	 */
	private function questions_for_lead( array $lead ): array {
		$job_id = sanitize_key( (string) ( $lead['job_type'] ?? '' ) );
		foreach ( $this->profiles() as $profile ) {
			if ( $profile['id'] === $job_id ) {
				return $profile['questions'];
			}
		}
		return array(
			'general_detail' => $this->question_catalog()['general_detail'],
		);
	}

	/**
	 * Remove questions already answered in free text.
	 *
	 * @param array<int, string>   $pending   Pending keys.
	 * @param string               $text      Combined text.
	 * @param array<string, string> $questions Question map.
	 * @return array<int, string>
	 */
	private function filter_answered( array $pending, string $text, array $questions ): array {
		$lower = strtolower( $text );
		$out   = array();
		foreach ( $pending as $key ) {
			if ( $this->text_answers_key( $key, $lower ) ) {
				continue;
			}
			$out[] = $key;
		}
		unset( $questions );
		return $out;
	}

	/**
	 * Heuristic: does free text already cover this detail key?
	 *
	 * @param string $key  Question key.
	 * @param string $text Lowercased text.
	 */
	private function text_answers_key( string $key, string $text ): bool {
		switch ( $key ) {
			case 'room_use':
			case 'rooms':
				return (bool) preg_match( '/\b(bedroom|kitchen|bathroom|lounge|living|office|garage|hallway|landing|dining|utility|conservatory|loft|attic)\b/', $text );
			case 'sockets':
			case 'count':
				return (bool) preg_match( '/\b\d+\s*(sockets?|points?|lights?|downlights?)\b/', $text )
					|| (bool) preg_match( '/\b(sockets?|points?)\b.+\b\d+\b/', $text );
			case 'property':
			case 'access':
				return (bool) preg_match( '/\b(house|flat|apartment|bungalow|maisonette|commercial|shop|office unit|new build)\b/', $text );
			case 'urgency':
				return (bool) preg_match( '/\b(urgent|emergency|asap|today|tomorrow|no hot water|no heating|still leaking)\b/', $text );
			case 'scope':
			case 'issue':
				return (bool) preg_match( '/\b(repair|replace|replacement|install|new|full (refit|rewire)|service)\b/', $text );
			case 'age':
				return (bool) preg_match( '/\b(\d+\s*years?|old|new|recent|eicr|failed|inspection)\b/', $text );
			case 'parking':
				return (bool) preg_match( '/\b(driveway|garage|drive|on-?street|car park)\b/', $text );
			case 'supply':
				return (bool) preg_match( '/\b(consumer unit|fuse ?board|spare way|checked|survey)\b/', $text );
			case 'where':
				return (bool) preg_match( '/\b(kitchen|bathroom|ceiling|floor|outside|under)\b/', $text );
			case 'general_detail':
				return str_word_count( $text ) >= 12;
			default:
				return false;
		}
	}

	/**
	 * @param string               $prev Previous assistant message.
	 * @param string               $key  Current detail key.
	 * @param array<string, mixed> $lead Lead.
	 */
	private function assistant_asked_detail( string $prev, string $key, array $lead ): bool {
		if ( '' === trim( $prev ) ) {
			return false;
		}
		$question = $this->next_question( array_merge( $lead, array( 'detail_pending' => array( $key ) ) ) );
		if ( '' !== $question && false !== stripos( $prev, substr( $question, 0, 24 ) ) ) {
			return true;
		}
		// Broader cues by key.
		$cues = array(
			'room_use'       => 'room mainly used',
			'sockets'        => 'sockets and light',
			'property'       => 'house, flat, or commercial',
			'count'          => 'how many extra sockets',
			'age'            => 'how old the current board',
			'scope'          => 'replacing existing',
			'rooms'          => 'which rooms or areas',
			'parking'        => 'car usually park',
			'supply'         => 'spare way',
			'issue'          => 'repair, service, or a new',
			'urgency'        => 'urgent',
			'where'          => 'where is the leak',
			'access'         => 'house or flat',
			'general_detail' => 'share a bit more detail',
		);
		$cue = $cues[ $key ] ?? '';
		return '' !== $cue && false !== stripos( $prev, $cue );
	}

	/**
	 * @param string $message User message.
	 */
	private function looks_like_detail_answer( string $message ): bool {
		$text = trim( $message );
		if ( '' === $text ) {
			return false;
		}
		if ( is_email( $text ) ) {
			return false;
		}
		if ( preg_match( '/^(?:my name is|i am|i\'m)\s+/i', $text ) ) {
			return false;
		}
		// Pure contact-shaped replies are not job details.
		if ( preg_match( '/^[A-Za-z0-9._%+\-]+@[A-Za-z0-9.\-]+\.[A-Za-z]{2,}$/', $text ) ) {
			return false;
		}
		if ( preg_match( '/^\+?\d[\d\s\-()]{8,}$/', $text ) ) {
			return false;
		}
		return true;
	}
}
