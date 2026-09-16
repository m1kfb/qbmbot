<?php
/**
 * Anthropic Messages API provider.
 *
 * @package QBMBot
 */

declare(strict_types=1);

namespace QBMBot\AI;

use QBMBot\Settings;

/**
 * Calls the Anthropic Messages API.
 */
final class Anthropic_Provider implements Provider {

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
	 * {@inheritdoc}
	 */
	public function slug(): string {
		return 'anthropic';
	}

	/**
	 * {@inheritdoc}
	 */
	public function is_configured(): bool {
		return '' !== trim( (string) $this->settings->get( 'anthropic_api_key', '' ) );
	}

	/**
	 * {@inheritdoc}
	 */
	public function complete( string $system, array $messages ): array {
		$key = trim( (string) $this->settings->get( 'anthropic_api_key', '' ) );
		if ( '' === $key ) {
			return array(
				'ok'      => false,
				'content' => '',
				'tokens'  => 0,
				'error'   => 'missing_api_key',
			);
		}

		$api_messages = array();
		foreach ( $messages as $msg ) {
			$role = ( 'assistant' === ( $msg['role'] ?? '' ) ) ? 'assistant' : 'user';
			$api_messages[] = array(
				'role'    => $role,
				'content' => (string) ( $msg['content'] ?? '' ),
			);
		}

		if ( empty( $api_messages ) ) {
			return array(
				'ok'      => false,
				'content' => '',
				'tokens'  => 0,
				'error'   => 'empty_messages',
			);
		}

		$response = wp_remote_post(
			'https://api.anthropic.com/v1/messages',
			array(
				'timeout' => 45,
				'headers' => array(
					'x-api-key'         => $key,
					'anthropic-version' => '2023-06-01',
					'Content-Type'      => 'application/json',
				),
				'body'    => wp_json_encode(
					array(
						'model'       => (string) $this->settings->get( 'anthropic_model', 'claude-3-5-haiku-latest' ),
						'max_tokens'  => (int) $this->settings->get( 'max_tokens', 500 ),
						'temperature' => (float) $this->settings->get( 'temperature', 0.4 ),
						'system'      => $system,
						'messages'    => $api_messages,
					)
				),
			)
		);

		if ( is_wp_error( $response ) ) {
			return array(
				'ok'      => false,
				'content' => '',
				'tokens'  => 0,
				'error'   => $response->get_error_message(),
			);
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		$data = json_decode( (string) wp_remote_retrieve_body( $response ), true );

		if ( $code < 200 || $code >= 300 || ! is_array( $data ) ) {
			$err = is_array( $data ) ? (string) ( $data['error']['message'] ?? 'http_error' ) : 'http_error';
			return array(
				'ok'      => false,
				'content' => '',
				'tokens'  => 0,
				'error'   => $err,
			);
		}

		$content = '';
		if ( ! empty( $data['content'] ) && is_array( $data['content'] ) ) {
			foreach ( $data['content'] as $block ) {
				if ( isset( $block['type'], $block['text'] ) && 'text' === $block['type'] ) {
					$content .= (string) $block['text'];
				}
			}
		}

		$tokens = (int) ( $data['usage']['input_tokens'] ?? 0 ) + (int) ( $data['usage']['output_tokens'] ?? 0 );

		return array(
			'ok'      => '' !== trim( $content ),
			'content' => trim( $content ),
			'tokens'  => $tokens,
			'error'   => '' !== trim( $content ) ? '' : 'empty_response',
		);
	}
}
