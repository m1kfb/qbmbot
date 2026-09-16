<?php
/**
 * Spam / bot detection before AI calls.
 *
 * @package QBMBot
 */

declare(strict_types=1);

namespace QBMBot;

/**
 * Scores messages for spam and bot signals.
 */
final class Spam_Guard {

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
	 * Evaluate a payload. Returns score and whether blocked.
	 *
	 * @param array<string, mixed> $context Context keys: text, email, honeypot, opened_at_ms, has_session, channel.
	 * @return array{blocked:bool,score:int,reasons:array<int,string>}
	 */
	public function evaluate( array $context ): array {
		$score   = 0;
		$reasons = array();
		$text    = trim( (string) ( $context['text'] ?? '' ) );
		$min_len = (int) $this->settings->get( 'min_message_length', 2 );

		if ( '' === $text || mb_strlen( $text ) < $min_len ) {
			$score += 5;
			$reasons[] = 'too_short';
		}

		if ( ! empty( $this->settings->get( 'honeypot_enabled' ) ) && '' !== trim( (string) ( $context['honeypot'] ?? '' ) ) ) {
			$score += 10;
			$reasons[] = 'honeypot';
		}

		$min_open = (int) $this->settings->get( 'min_open_ms', 800 );
		$opened   = isset( $context['opened_at_ms'] ) ? (int) $context['opened_at_ms'] : null;
		if ( null !== $opened && $opened > 0 ) {
			$elapsed = (int) ( microtime( true ) * 1000 ) - $opened;
			if ( $elapsed < $min_open ) {
				$score += 4;
				$reasons[] = 'too_fast';
			}
		} elseif ( 'chat' === ( $context['channel'] ?? '' ) ) {
			$score += 2;
			$reasons[] = 'missing_timing';
		}

		if ( empty( $context['has_session'] ) && 'chat' === ( $context['channel'] ?? '' ) ) {
			$score += 3;
			$reasons[] = 'missing_session';
		}

		$ua = isset( $_SERVER['HTTP_USER_AGENT'] ) ? (string) wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) : '';
		if ( '' === $ua || preg_match( '/bot|crawl|spider|scrapy|curl|wget|python-requests/i', $ua ) ) {
			$score += 3;
			$reasons[] = 'suspicious_ua';
		}

		$link_count = preg_match_all( '#https?://#i', $text );
		if ( $link_count >= 3 ) {
			$score += 4;
			$reasons[] = 'link_dump';
		} elseif ( $link_count >= 1 && mb_strlen( $text ) < 40 ) {
			$score += 2;
			$reasons[] = 'short_with_link';
		}

		if ( preg_match( '/\b(viagra|casino|crypto\s*invest|nigerian\s*prince|seo\s*backlinks)\b/i', $text ) ) {
			$score += 5;
			$reasons[] = 'spam_keywords';
		}

		if ( preg_match( '/(.)\1{8,}/u', $text ) ) {
			$score += 2;
			$reasons[] = 'repeated_chars';
		}

		if ( ! empty( $this->settings->get( 'use_akismet' ) ) && $this->akismet_says_spam( $context ) ) {
			$score += 6;
			$reasons[] = 'akismet';
		}

		$threshold = (int) $this->settings->get( 'spam_threshold', 5 );

		return array(
			'blocked' => $score >= $threshold,
			'score'   => $score,
			'reasons' => $reasons,
		);
	}

	/**
	 * Ask Akismet if available.
	 *
	 * @param array<string, mixed> $context Context.
	 */
	private function akismet_says_spam( array $context ): bool {
		if ( ! function_exists( 'akismet_http_post' ) && ! class_exists( 'Akismet' ) ) {
			return false;
		}

		$api_key = defined( 'WPCOM_API_KEY' ) ? WPCOM_API_KEY : (string) get_option( 'wordpress_api_key', '' );
		if ( '' === $api_key && class_exists( 'Akismet' ) && method_exists( 'Akismet', 'get_api_key' ) ) {
			$api_key = (string) \Akismet::get_api_key();
		}
		if ( '' === $api_key ) {
			return false;
		}

		$blog = get_option( 'home' );
		$data = array(
			'blog'                 => $blog,
			'user_ip'              => $this->client_ip(),
			'user_agent'           => isset( $_SERVER['HTTP_USER_AGENT'] ) ? (string) wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) : '',
			'referrer'             => isset( $_SERVER['HTTP_REFERER'] ) ? (string) wp_unslash( $_SERVER['HTTP_REFERER'] ) : '',
			'comment_type'         => 'contact-form',
			'comment_author'       => (string) ( $context['name'] ?? '' ),
			'comment_author_email' => (string) ( $context['email'] ?? '' ),
			'comment_content'      => (string) ( $context['text'] ?? '' ),
		);

		$query = http_build_query( $data );

		if ( class_exists( 'Akismet' ) && method_exists( 'Akismet', 'http_post' ) ) {
			$response = \Akismet::http_post( $query, 'comment-check' );
			return is_array( $response ) && isset( $response[1] ) && 'true' === $response[1];
		}

		return false;
	}

	/**
	 * Best-effort client IP.
	 */
	public function client_ip(): string {
		$keys = array( 'HTTP_CF_CONNECTING_IP', 'HTTP_X_FORWARDED_FOR', 'REMOTE_ADDR' );
		foreach ( $keys as $key ) {
			if ( empty( $_SERVER[ $key ] ) ) {
				continue;
			}
			$raw = (string) wp_unslash( $_SERVER[ $key ] );
			$ip  = trim( explode( ',', $raw )[0] );
			if ( filter_var( $ip, FILTER_VALIDATE_IP ) ) {
				return $ip;
			}
		}
		return '0.0.0.0';
	}
}
