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
	 * @param string                    $message Visitor message.
	 * @param array<string, mixed>|null $lead    Optional lead progress.
	 */
	public function reply( string $message, ?array $lead = null ): string {
		$name     = $this->business_name();
		$trade    = trim( (string) $this->settings->get( 'business_trade', '' ) );
		$services = trim( (string) $this->settings->get( 'business_services', '' ) );
		$area     = trim( (string) $this->settings->get( 'business_service_area', '' ) );
		$needle   = strtolower( $message );

		$service_match = $this->mentions_service( $needle, $trade, $services );
		$area_match    = $this->mentions_area( $needle, $area );

		if ( $service_match && $area_match ) {
			$base = sprintf(
				/* translators: 1: business name, 2: trade/services summary, 3: service area */
				__( 'Thanks for getting in touch. %1$s covers %3$s for %2$s.', 'qbmbot' ),
				$name,
				$this->service_summary( $trade, $services ),
				$area
			);
		} elseif ( $service_match ) {
			$base = sprintf(
				/* translators: 1: business name, 2: trade/services summary, 3: service area or fallback phrase */
				__( 'Thanks for your enquiry. %1$s provides %2$s. %3$s', 'qbmbot' ),
				$name,
				$this->service_summary( $trade, $services ),
				'' !== $area
					? sprintf(
						/* translators: %s: service area */
						__( 'We cover %s.', 'qbmbot' ),
						$area
					)
					: ''
			);
		} elseif ( $area_match ) {
			$base = sprintf(
				/* translators: 1: business name, 2: service area, 3: trade or generic services phrase */
				__( 'Thanks for your message. %1$s covers %2$s for %3$s.', 'qbmbot' ),
				$name,
				$area,
				'' !== $trade ? $trade : __( 'our services', 'qbmbot' )
			);
		} else {
			$base = (string) $this->settings->get(
				'chat_fallback_message',
				__( 'Thanks for your message. Please share a few more details or use the contact form on our website and the team will get back to you soon.', 'qbmbot' )
			);
		}

		$ask = $this->next_lead_question( $lead );
		if ( '' !== $ask ) {
			return trim( $base . ' ' . $ask );
		}

		return trim( $base );
	}

	/**
	 * Ask for the next missing lead field.
	 *
	 * @param array<string, mixed>|null $lead Lead.
	 */
	private function next_lead_question( ?array $lead ): string {
		if ( ! $this->settings->get( 'lead_capture_enabled', true ) || ! is_array( $lead ) ) {
			return '';
		}
		if ( ! empty( $lead['sent'] ) ) {
			return __( 'We have your details and the team will be in touch.', 'qbmbot' );
		}
		if ( '' === trim( (string) ( $lead['enquiry'] ?? '' ) ) ) {
			return __( 'Could you tell us a little more about what you need help with?', 'qbmbot' );
		}
		if ( '' === trim( (string) ( $lead['name'] ?? '' ) ) ) {
			return __( 'What name should we use for this enquiry?', 'qbmbot' );
		}
		if ( '' === trim( (string) ( $lead['email'] ?? '' ) ) ) {
			return __( 'What is the best email address to reach you on?', 'qbmbot' );
		}
		if ( $this->settings->get( 'lead_require_phone', true ) && '' === trim( (string) ( $lead['phone'] ?? '' ) ) ) {
			return __( 'And what is the best phone number to call you on?', 'qbmbot' );
		}
		return __( 'Thanks — we will pass this to the team now.', 'qbmbot' );
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
