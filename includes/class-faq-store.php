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
	 * Match FAQ by question text (case-insensitive).
	 *
	 * @param string $question Question text.
	 * @return array<string, mixed>|null
	 */
	public function match_question( string $question ): ?array {
		$needle = strtolower( trim( $question ) );
		foreach ( $this->all() as $item ) {
			if ( empty( $item['enabled'] ) ) {
				continue;
			}
			$label = strtolower( trim( (string) ( $item['question'] ?? '' ) ) );
			if ( $label !== '' && $label === $needle ) {
				return $item;
			}
		}
		return null;
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
