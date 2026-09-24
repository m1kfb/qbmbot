<?php
/**
 * FAQ / preload question store.
 *
 * @package QBMBot
 */

declare(strict_types=1);

namespace QBMBot;

/**
 * Manages preloaded questions and AI instructions.
 */
final class FAQ_Store {

	public const OPTION_KEY = 'qbmbot_faq';

	/**
	 * Settings instance.
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
	 * All FAQ items.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public function all(): array {
		$items = get_option( self::OPTION_KEY, array() );
		if ( ! is_array( $items ) ) {
			return array();
		}
		usort(
			$items,
			static function ( $a, $b ): int {
				return (int) ( $a['order'] ?? 0 ) <=> (int) ( $b['order'] ?? 0 );
			}
		);
		return array_values( $items );
	}

	/**
	 * Enabled FAQs for the widget.
	 *
	 * @return array<int, array{id:string,question:string,answer:string}>
	 */
	public function public_items(): array {
		$out = array();
		foreach ( $this->all() as $item ) {
			if ( empty( $item['enabled'] ) ) {
				continue;
			}
			$out[] = array(
				'id'       => (string) ( $item['id'] ?? '' ),
				'question' => (string) ( $item['question'] ?? '' ),
				'answer'   => (string) ( $item['answer'] ?? '' ),
			);
		}
		return $out;
	}

	/**
	 * Find FAQ by id.
	 *
	 * @param string $id FAQ id.
	 * @return array<string, mixed>|null
	 */
	public function find( string $id ): ?array {
		foreach ( $this->all() as $item ) {
			if ( (string) ( $item['id'] ?? '' ) === $id ) {
				return $item;
			}
		}
		return null;
	}

	/**
	 * Match FAQ by question text (exact, then soft overlap).
	 *
	 * @param string $question Question text.
	 * @return array<string, mixed>|null
	 */
	public function match_question( string $question ): ?array {
		$needle = strtolower( trim( $question ) );
		if ( '' === $needle ) {
			return null;
		}

		foreach ( $this->all() as $item ) {
			if ( empty( $item['enabled'] ) ) {
				continue;
			}
			$label = strtolower( trim( (string) ( $item['question'] ?? '' ) ) );
			if ( '' !== $label && $label === $needle ) {
				return $item;
			}
		}

		$best         = null;
		$best_score   = 0.0;
		$needle_words = $this->significant_words( $needle );
		foreach ( $this->all() as $item ) {
			if ( empty( $item['enabled'] ) ) {
				continue;
			}
			$label = strtolower( trim( (string) ( $item['question'] ?? '' ) ) );
			if ( '' === $label ) {
				continue;
			}
			if ( false !== strpos( $needle, $label ) || false !== strpos( $label, $needle ) ) {
				return $item;
			}
			$label_words = $this->significant_words( $label );
			if ( count( $label_words ) < 2 || count( $needle_words ) < 2 ) {
				continue;
			}
			$overlap = count( array_intersect( $needle_words, $label_words ) );
			$score   = $overlap / max( count( $label_words ), 1 );
			if ( $score >= 0.6 && $score > $best_score ) {
				$best_score = $score;
				$best       = $item;
			}
		}

		return $best;
	}

	/**
	 * Significant words for soft FAQ matching.
	 *
	 * @param string $text Text.
	 * @return array<int, string>
	 */
	private function significant_words( string $text ): array {
		$stop  = array( 'the', 'and', 'for', 'with', 'from', 'that', 'this', 'have', 'about', 'please', 'could', 'would', 'your', 'you', 'are', 'can', 'do', 'does', 'what', 'when', 'where', 'who', 'how', 'is', 'a', 'an', 'to', 'of', 'in', 'on', 'our' );
		$words = preg_split( '/\s+/', strtolower( preg_replace( '/[^a-z0-9\s\-]/i', ' ', $text ) ?? $text ) ) ?: array();
		$out   = array();
		foreach ( $words as $word ) {
			$word = trim( $word );
			if ( strlen( $word ) < 3 || in_array( $word, $stop, true ) ) {
				continue;
			}
			$out[] = $word;
		}
		return array_values( array_unique( $out ) );
	}

	/**
	 * Replace all FAQ items.
	 *
	 * @param array<int, array<string, mixed>> $items Items.
	 */
	public function save( array $items ): void {
		$clean = array();
		$order = 0;
		foreach ( $items as $item ) {
			$question = sanitize_text_field( (string) ( $item['question'] ?? '' ) );
			if ( '' === $question ) {
				continue;
			}
			$id = sanitize_key( (string) ( $item['id'] ?? '' ) );
			if ( '' === $id ) {
				$id = 'faq_' . wp_generate_password( 8, false, false );
			}
			$clean[] = array(
				'id'             => $id,
				'question'       => $question,
				'answer'         => sanitize_textarea_field( (string) ( $item['answer'] ?? '' ) ),
				'ai_instructions'=> sanitize_textarea_field( (string) ( $item['ai_instructions'] ?? '' ) ),
				'enabled'        => ! empty( $item['enabled'] ),
				'order'          => isset( $item['order'] ) ? (int) $item['order'] : $order,
			);
			++$order;
		}
		update_option( self::OPTION_KEY, $clean );
	}

	/**
	 * Global prompt from settings.
	 */
	public function global_prompt(): string {
		return (string) $this->settings->get( 'global_prompt', '' );
	}
}
