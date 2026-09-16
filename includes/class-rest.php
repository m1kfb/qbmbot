<?php
/**
 * REST API endpoints.
 *
 * @package QBMBot
 */

declare(strict_types=1);

namespace QBMBot;

use QBMBot\AI\Prompt_Builder;
use QBMBot\AI\Router;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

/**
 * Chat REST controller.
 */
final class REST {

	public const NS = 'qbmbot/v1';

	/**
	 * @var Settings
	 */
	private Settings $settings;

	/**
	 * @var FAQ_Store
	 */
	private FAQ_Store $faq;

	/**
	 * @var Spam_Guard
	 */
	private Spam_Guard $spam;

	/**
	 * @var Rate_Limiter
	 */
	private Rate_Limiter $limiter;

	/**
	 * @var Router
	 */
	private Router $router;

	/**
	 * @var Prompt_Builder
	 */
	private Prompt_Builder $prompts;

	/**
	 * @var Logger
	 */
	private Logger $logger;

	/**
	 * Constructor.
	 *
	 * @param Settings       $settings Settings.
	 * @param FAQ_Store      $faq      FAQ.
	 * @param Spam_Guard     $spam     Spam.
	 * @param Rate_Limiter   $limiter  Limiter.
	 * @param Router         $router   Router.
	 * @param Prompt_Builder $prompts  Prompts.
	 * @param Logger         $logger   Logger.
	 */
	public function __construct(
		Settings $settings,
		FAQ_Store $faq,
		Spam_Guard $spam,
		Rate_Limiter $limiter,
		Router $router,
		Prompt_Builder $prompts,
		Logger $logger
	) {
		$this->settings = $settings;
		$this->faq      = $faq;
		$this->spam     = $spam;
		$this->limiter  = $limiter;
		$this->router   = $router;
		$this->prompts  = $prompts;
		$this->logger   = $logger;
	}

	/**
	 * Register routes.
	 */
	public function register(): void {
		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
	}

	/**
	 * Route definitions.
	 */
	public function register_routes(): void {
		register_rest_route(
			self::NS,
			'/chat',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'handle_chat' ),
				'permission_callback' => array( $this, 'can_chat' ),
				'args'                => array(
					'message'      => array(
						'required'          => true,
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_textarea_field',
					),
					'session_id'   => array(
						'required'          => true,
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_text_field',
					),
					'faq_id'       => array(
						'required'          => false,
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_key',
					),
					'honeypot'     => array(
						'required'          => false,
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_text_field',
					),
					'opened_at_ms' => array(
						'required' => false,
						'type'     => 'integer',
					),
				),
			)
		);

		register_rest_route(
			self::NS,
			'/health',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'handle_health' ),
				'permission_callback' => '__return_true',
			)
		);
	}

	/**
	 * Permission: nonce + widget enabled.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return bool|WP_Error
	 */
	public function can_chat( WP_REST_Request $request ) {
		if ( ! $this->settings->get( 'widget_enabled' ) ) {
			return new WP_Error( 'qbmbot_disabled', __( 'Chat is disabled.', 'qbmbot' ), array( 'status' => 403 ) );
		}

		$nonce = $request->get_header( 'X-WP-Nonce' );
		if ( ! $nonce ) {
			$nonce = (string) $request->get_param( '_wpnonce' );
		}
		if ( ! wp_verify_nonce( (string) $nonce, 'wp_rest' ) ) {
			return new WP_Error( 'qbmbot_bad_nonce', __( 'Invalid nonce.', 'qbmbot' ), array( 'status' => 403 ) );
		}

		return true;
	}

	/**
	 * Health check.
	 *
	 * @return WP_REST_Response
	 */
	public function handle_health(): WP_REST_Response {
		return new WP_REST_Response(
			array(
				'ok'       => true,
				'version'  => QBMBot_VERSION,
				'provider' => $this->router->active_slug(),
			),
			200
		);
	}

	/**
	 * Chat handler.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function handle_chat( WP_REST_Request $request ) {
		$message    = trim( (string) $request->get_param( 'message' ) );
		$session_id = (string) $request->get_param( 'session_id' );
		$faq_id     = (string) $request->get_param( 'faq_id' );
		$honeypot   = (string) $request->get_param( 'honeypot' );
		$opened_at  = (int) $request->get_param( 'opened_at_ms' );
		$ip         = $this->spam->client_ip();

		if ( '' === $session_id || strlen( $session_id ) > 64 ) {
			return new WP_Error( 'qbmbot_bad_session', __( 'Invalid session.', 'qbmbot' ), array( 'status' => 400 ) );
		}

		$spam = $this->spam->evaluate(
			array(
				'text'         => $message,
				'honeypot'     => $honeypot,
				'opened_at_ms' => $opened_at,
				'has_session'  => '' !== $session_id,
				'channel'      => 'chat',
			)
		);

		if ( $spam['blocked'] ) {
			$this->logger->log(
				array(
					'channel'    => 'chat',
					'status'     => 'blocked',
					'reason'     => 'spam:' . implode( ',', $spam['reasons'] ),
					'spam_score' => $spam['score'],
					'ip_hash'    => Logger::hash_ip( $ip ),
				)
			);
			return new WP_REST_Response(
				array(
					'reply'   => (string) $this->settings->get( 'blocked_message' ),
					'blocked' => true,
				),
				200
			);
		}

		$rate = $this->limiter->allow( $ip, $session_id );
		if ( ! $rate['allowed'] ) {
			$this->logger->log(
				array(
					'channel'    => 'chat',
					'status'     => 'blocked',
					'reason'     => 'rate:' . $rate['reason'],
					'spam_score' => $spam['score'],
					'ip_hash'    => Logger::hash_ip( $ip ),
				)
			);
			return new WP_Error(
				'qbmbot_rate_limited',
				__( 'Too many requests. Please try again later.', 'qbmbot' ),
				array( 'status' => 429 )
			);
		}

		// Optional static FAQ answer without AI when a display answer is set and no free-form follow-up needed.
		$faq_item = $faq_id ? $this->faq->find( $faq_id ) : $this->faq->match_question( $message );
		if ( $faq_item && ! empty( $faq_item['answer'] ) && empty( $faq_item['ai_instructions'] ) ) {
			$this->limiter->hit( $ip, $session_id );
			$this->append_history( $session_id, 'user', $message );
			$this->append_history( $session_id, 'assistant', (string) $faq_item['answer'] );
			$this->logger->log(
				array(
					'channel'    => 'chat',
					'status'     => 'static',
					'reason'     => 'faq_static',
					'spam_score' => $spam['score'],
					'ip_hash'    => Logger::hash_ip( $ip ),
				)
			);
			return new WP_REST_Response(
				array(
					'reply'   => (string) $faq_item['answer'],
					'blocked' => false,
				),
				200
			);
		}

		$this->limiter->hit( $ip, $session_id );

		$history = $this->get_history( $session_id );
		$history[] = array(
			'role'    => 'user',
			'content' => $message,
		);

		$system = $this->prompts->chat_system( $faq_id ?: null, $message );
		$result = $this->router->complete( $system, $history );

		if ( empty( $result['ok'] ) ) {
			$this->logger->log(
				array(
					'channel'    => 'chat',
					'status'     => 'error',
					'reason'     => (string) ( $result['error'] ?? 'ai_failed' ),
					'provider'   => (string) ( $result['provider'] ?? '' ),
					'spam_score' => $spam['score'],
					'ip_hash'    => Logger::hash_ip( $ip ),
				)
			);
			return new WP_Error(
				'qbmbot_ai_error',
				__( 'Unable to generate a reply right now.', 'qbmbot' ),
				array( 'status' => 502 )
			);
		}

		$reply = (string) $result['content'];
		$this->append_history( $session_id, 'user', $message );
		$this->append_history( $session_id, 'assistant', $reply );

		$this->logger->log(
			array(
				'channel'    => 'chat',
				'status'     => 'ok',
				'provider'   => (string) ( $result['provider'] ?? '' ),
				'tokens'     => (int) ( $result['tokens'] ?? 0 ),
				'spam_score' => $spam['score'],
				'ip_hash'    => Logger::hash_ip( $ip ),
			)
		);

		return new WP_REST_Response(
			array(
				'reply'   => $reply,
				'blocked' => false,
			),
			200
		);
	}

	/**
	 * Load conversation history from transient.
	 *
	 * @param string $session_id Session.
	 * @return array<int, array{role:string,content:string}>
	 */
	private function get_history( string $session_id ): array {
		$raw = get_transient( $this->history_key( $session_id ) );
		if ( ! is_array( $raw ) ) {
			return array();
		}
		$out = array();
		foreach ( $raw as $row ) {
			if ( empty( $row['role'] ) || empty( $row['content'] ) ) {
				continue;
			}
			$out[] = array(
				'role'    => ( 'assistant' === $row['role'] ) ? 'assistant' : 'user',
				'content' => (string) $row['content'],
			);
		}
		return array_slice( $out, -12 );
	}

	/**
	 * Append a turn to history.
	 *
	 * @param string $session_id Session.
	 * @param string $role       Role.
	 * @param string $content    Content.
	 */
	private function append_history( string $session_id, string $role, string $content ): void {
		$history   = $this->get_history( $session_id );
		$history[] = array(
			'role'    => $role,
			'content' => $content,
		);
		$history = array_slice( $history, -12 );
		set_transient( $this->history_key( $session_id ), $history, HOUR_IN_SECONDS );
	}

	/**
	 * Transient key for session history.
	 *
	 * @param string $session_id Session.
	 */
	private function history_key( string $session_id ): string {
		return 'qbmbot_hist_' . md5( $session_id );
	}
}
