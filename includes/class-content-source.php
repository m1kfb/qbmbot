<?php
/**
 * Pulls on-site WordPress content for AI context.
 *
 * @package QBMBot
 */

declare(strict_types=1);

namespace QBMBot;

/**
 * Sources published site content for grounded answers.
 */
final class Content_Source {

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
	 * Build a context block for the AI from the WordPress site.
	 *
	 * @param string $query Visitor question or enquiry text.
	 */
	public function context_for_query( string $query ): string {
		if ( ! $this->settings->get( 'content_enabled', true ) ) {
			return '';
		}

		$max_chars = max( 500, min( 20000, (int) $this->settings->get( 'content_max_chars', 6000 ) ) );
		$chunks    = array();
		$used      = 0;
		$seen      = array();

		foreach ( $this->pinned_posts() as $post ) {
			$block = $this->format_post( $post );
			if ( '' === $block || isset( $seen[ $post->ID ] ) ) {
				continue;
			}
			if ( $used + strlen( $block ) > $max_chars ) {
				$remain = $max_chars - $used;
				if ( $remain < 200 ) {
					break;
				}
				$block = $this->truncate( $block, $remain );
			}
			$chunks[]           = $block;
			$seen[ $post->ID ]  = true;
			$used              += strlen( $block );
			if ( $used >= $max_chars ) {
				break;
			}
		}

		if ( $used < $max_chars ) {
			foreach ( $this->search_posts( $query, array_keys( $seen ) ) as $post ) {
				$block = $this->format_post( $post );
				if ( '' === $block ) {
					continue;
				}
				if ( $used + strlen( $block ) > $max_chars ) {
					$remain = $max_chars - $used;
					if ( $remain < 200 ) {
						break;
					}
					$block = $this->truncate( $block, $remain );
				}
				$chunks[]          = $block;
				$seen[ $post->ID ] = true;
				$used             += strlen( $block );
				if ( $used >= $max_chars ) {
					break;
				}
			}
		}

		if ( empty( $chunks ) ) {
			return '';
		}

		return "SITE CONTENT (use only this business's information; do not invent facts beyond this):\n\n" . implode( "\n\n---\n\n", $chunks );
	}

	/**
	 * Always-include posts from settings.
	 *
	 * @return array<int, \WP_Post>
	 */
	private function pinned_posts(): array {
		$ids = $this->settings->get( 'content_pinned_ids', array() );
		if ( ! is_array( $ids ) || empty( $ids ) ) {
			return array();
		}
		$ids = array_values( array_filter( array_map( 'intval', $ids ) ) );
		if ( empty( $ids ) ) {
			return array();
		}

		$posts = get_posts(
			array(
				'post_type'              => $this->post_types(),
				'post_status'            => 'publish',
				'post__in'               => $ids,
				'orderby'                => 'post__in',
				'posts_per_page'         => count( $ids ),
				'no_found_rows'          => true,
				'update_post_meta_cache' => false,
				'update_post_term_cache' => false,
			)
		);

		return is_array( $posts ) ? $posts : array();
	}

	/**
	 * Search site content related to the query.
	 *
	 * @param string    $query       Query text.
	 * @param array<int, int> $exclude_ids Already included IDs.
	 * @return array<int, \WP_Post>
	 */
	private function search_posts( string $query, array $exclude_ids ): array {
		$query = trim( wp_strip_all_tags( $query ) );
		$limit = max( 1, min( 10, (int) $this->settings->get( 'content_search_limit', 5 ) ) );

		$args = array(
			'post_type'              => $this->post_types(),
			'post_status'            => 'publish',
			'posts_per_page'         => $limit,
			'no_found_rows'          => true,
			'update_post_meta_cache' => false,
			'update_post_term_cache' => false,
			'ignore_sticky_posts'    => true,
		);

		if ( ! empty( $exclude_ids ) ) {
			$args['post__not_in'] = array_map( 'intval', $exclude_ids );
		}

		$exclude_ids_setting = $this->settings->get( 'content_exclude_ids', array() );
		if ( is_array( $exclude_ids_setting ) && ! empty( $exclude_ids_setting ) ) {
			$extra = array_map( 'intval', $exclude_ids_setting );
			$args['post__not_in'] = array_values( array_unique( array_merge( $args['post__not_in'] ?? array(), $extra ) ) );
		}

		if ( strlen( $query ) >= 3 ) {
			$args['s'] = $this->search_keywords( $query );
		} else {
			// No useful query: pull recent pages/posts as light fallback.
			$args['orderby'] = 'modified';
			$args['order']   = 'DESC';
		}

		$posts = get_posts( $args );
		return is_array( $posts ) ? $posts : array();
	}

	/**
	 * Allowed post types.
	 *
	 * @return array<int, string>
	 */
	private function post_types(): array {
		$types = $this->settings->get( 'content_post_types', array( 'page', 'post' ) );
		if ( ! is_array( $types ) || empty( $types ) ) {
			return array( 'page', 'post' );
		}
		$clean = array();
		foreach ( $types as $type ) {
			$type = sanitize_key( (string) $type );
			if ( '' !== $type && post_type_exists( $type ) ) {
				$clean[] = $type;
			}
		}
		return $clean ?: array( 'page', 'post' );
	}

	/**
	 * Reduce a long enquiry to searchable keywords.
	 *
	 * @param string $query Raw query.
	 */
	private function search_keywords( string $query ): string {
		$query = preg_replace( '/\s+/', ' ', $query ) ?? $query;
		if ( strlen( $query ) <= 120 ) {
			return $query;
		}
		$words = preg_split( '/\s+/', $query ) ?: array();
		$stop  = array( 'the', 'and', 'for', 'with', 'from', 'that', 'this', 'have', 'about', 'please', 'could', 'would', 'your', 'you', 'are', 'can' );
		$keep  = array();
		foreach ( $words as $word ) {
			$w = strtolower( preg_replace( '/[^a-z0-9\-]/i', '', $word ) ?? '' );
			if ( strlen( $w ) < 3 || in_array( $w, $stop, true ) ) {
				continue;
			}
			$keep[] = $w;
			if ( count( $keep ) >= 12 ) {
				break;
			}
		}
		return $keep ? implode( ' ', $keep ) : substr( $query, 0, 120 );
	}

	/**
	 * Format a post into a context chunk.
	 *
	 * @param \WP_Post $post Post.
	 */
	private function format_post( \WP_Post $post ): string {
		$title   = wp_specialchars_decode( get_the_title( $post ), ENT_QUOTES );
		$url     = (string) get_permalink( $post );
		$content = $post->post_content;

		// Prefer excerpt when present.
		if ( has_excerpt( $post ) ) {
			$content = $post->post_excerpt . "\n\n" . $content;
		}

		$content = $this->plain_text( $content );
		$per_post = max( 200, min( 4000, (int) $this->settings->get( 'content_per_post_chars', 1500 ) ) );
		$content  = $this->truncate( $content, $per_post );

		if ( '' === $content && '' === $title ) {
			return '';
		}

		return sprintf(
			"Title: %s\nURL: %s\nContent:\n%s",
			$title,
			$url,
			$content
		);
	}

	/**
	 * Strip to plain text suitable for prompts.
	 *
	 * @param string $html HTML or shortcodes.
	 */
	private function plain_text( string $html ): string {
		$html = strip_shortcodes( $html );
		$html = wp_strip_all_tags( $html );
		$html = html_entity_decode( $html, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
		$html = preg_replace( "/[ \t]+/", ' ', $html ) ?? $html;
		$html = preg_replace( "/\n{3,}/", "\n\n", $html ) ?? $html;
		return trim( $html );
	}

	/**
	 * Truncate string on a word boundary when possible.
	 *
	 * @param string $text Text.
	 * @param int    $max  Max length.
	 */
	private function truncate( string $text, int $max ): string {
		if ( strlen( $text ) <= $max ) {
			return $text;
		}
		$cut = substr( $text, 0, $max );
		$sp  = strrpos( $cut, ' ' );
		if ( false !== $sp && $sp > (int) ( $max * 0.6 ) ) {
			$cut = substr( $cut, 0, $sp );
		}
		return rtrim( $cut ) . '…';
	}
}
