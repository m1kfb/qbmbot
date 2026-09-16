<?php
/**
 * OpenAI chat completions provider.
 *
 * @package QBMBot
 */

declare(strict_types=1);

namespace QBMBot\AI;

use QBMBot\Settings;

/**
 * Calls the OpenAI Chat Completions API.
 */
final class OpenAI_Provider implements Provider {

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
		return 'openai';
	}

	/**
	 * {@inheritdoc}
	 */
	public function is_configured(): bool {
		return '' !== trim( (string) $this->settings->get( 'openai_api_key', '' ) );
	}

	/**
	 * {@inheritdoc}
	 */
	public function complete( string $system, array $messages ): array {
		$key = trim( (string) $this->settings->get( 'openai_api_key', '' ) );
		if ( '' === $key ) {
			return array(
				'ok'      => false,
				'content' => '',
				'tokens'  => 0,
				'error'   => 'missing_api_key',
			);
		}

		$body_messages = array(
			array(
				'role'    => 'system',
				'content' => $system,
			),
		);
		foreach ( $messages as $msg ) {
			$role = ( 'assistant' === ( $msg['role'] ?? '' ) ) ? 'assistant' : 'user';
			$body_messages[] = array(
				'role'    => $role,
				'content' => (string) ( $msg['content'] ?? '' ),
			);
		}

		$response = wp_remote_post(
			'https://api.openai.com/v1/chat/completions',
			array(
				'timeout' => 45,
				'headers' => array(
					'Authorization' => 'Bearer ' . $key,
					'Content-Type'  => 'application/json',
				),
				'body'    => wp_json_encode(
					array(
						'model'       => (string) $this->settings->get( 'openai_model', 'gpt-4o-mini' ),
						'messages'    => $body_messages,
						'max_tokens'  => (int) $this->settings->get( 'max_tokens', 500 ),
						'temperature' => (float) $this->settings->get( 'temperature', 0.4 ),
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

		$content = (string) ( $data['choices'][0]['message']['content'] ?? '' );
		$tokens  = (int) ( $data['usage']['total_tokens'] ?? 0 );

		return array(
			'ok'      => '' !== trim( $content ),
			'content' => trim( $content ),
			'tokens'  => $tokens,
			'error'   => '' !== trim( $content ) ? '' : 'empty_response',
		);
	}
}
