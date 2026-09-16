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
	 * @var Chat_Fallback
	 */
	private Chat_Fallback $fallback;

	/**
	 * @var Lead_Capture
	 */
	private Lead_Capture $leads;

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
	 * @param Chat_Fallback  $fallback Fallback replies.
	 * @param Lead_Capture   $leads    Lead capture.
	 * @param Logger         $logger   Logger.
	 */
	public function __construct(
		Settings $settings,
		FAQ_Store $faq,
		Spam_Guard $spam,
		Rate_Limiter $limiter,
		Router $router,
		Prompt_Builder $prompts,
		Chat_Fallback $fallback,
		Lead_Capture $leads,
		Logger $logger
	) {
		$this->settings = $settings;
		$this->faq      = $faq;
		$this->spam     = $spam;
		$this->limiter  = $limiter;
		$this->router   = $router;
		$this->prompts  = $prompts;
		$this->fallback = $fallback;
		$this->leads    = $leads;
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
			$lead_result = $this->maybe_process_lead( $session_id, $message, $ip );
			$this->logger->log(
				array(
					'channel'    => 'chat',
					'status'     => 'static',
					'reason'     => 'faq_static',
					'spam_score' => $spam['score'],
					'ip_hash'    => Logger::hash_ip( $ip ),
				)
			);
			return $this->chat_response( (string) $faq_item['answer'], $lead_result );
		}

		$this->limiter->hit( $ip, $session_id );

		$lead_before = $this->leads->enabled() ? $this->leads->get_lead( $session_id ) : null;
		$history     = $this->get_history( $session_id );
		$history[]   = array(
			'role'    => 'user',
			'content' => $message,
		);

		// Capture lead fields before composing a reply so we never re-ask for details already given.
		$lead_processed = $this->process_lead_turn( $session_id, $message, $history, $ip );
		$lead_after     = is_array( $lead_processed ) ? $lead_processed['lead'] : $lead_before;

		if ( ! $this->router->is_active_configured() ) {
			return $this->respond_with_fallback(
				$message,
				$session_id,
				$ip,
				$spam['score'],
				'missing_api_key',
				'',
				$lead_after,
				$lead_before,
				$lead_processed
			);
		}

		$system = $this->prompts->chat_system( $faq_id ?: null, $message, $lead_after );
		$result = $this->router->complete( $system, $history );

		if ( empty( $result['ok'] ) ) {
			return $this->respond_with_fallback(
				$message,
				$session_id,
				$ip,
				$spam['score'],
				(string) ( $result['error'] ?? 'ai_failed' ),
				(string) ( $result['provider'] ?? '' ),
				$lead_after,
				$lead_before,
				$lead_processed
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

		return $this->chat_response( $reply, $this->lead_status( $lead_processed ) );
	}

	/**
	 * Return a helpful offline reply when AI is unavailable.
	 *
	 * @param string                                                         $message        User message.
	 * @param string                                                         $session_id     Session id.
	 * @param string                                                         $ip             Client IP.
	 * @param int                                                            $spam_score     Spam score.
	 * @param string                                                         $reason         Failure reason.
	 * @param string                                                         $provider       Provider slug.
	 * @param array<string, mixed>|null                                      $lead_after     Lead after this turn.
	 * @param array<string, mixed>|null                                      $lead_before    Lead before this turn.
	 * @param array{sent:bool,lead:array<string,mixed>,missing:array<int,string>}|null $lead_processed Processed lead payload.
	 */
	private function respond_with_fallback(
		string $message,
		string $session_id,
		string $ip,
		int $spam_score,
		string $reason,
		string $provider,
		?array $lead_after = null,
		?array $lead_before = null,
		?array $lead_processed = null
	): WP_REST_Response {
		$reply = $this->fallback->reply( $message, $lead_after, $lead_before );

		$this->append_history( $session_id, 'user', $message );
		$this->append_history( $session_id, 'assistant', $reply );

		$this->logger->log(
			array(
				'channel'    => 'chat',
				'status'     => 'fallback',
				'reason'     => $reason,
				'provider'   => $provider,
				'spam_score' => $spam_score,
				'ip_hash'    => Logger::hash_ip( $ip ),
			)
		);

		return $this->chat_response( $reply, $this->lead_status( $lead_processed ) );
	}

	/**
	 * Process lead capture for this turn when enabled.
	 *
	 * @param string                                    $session_id Session.
	 * @param string                                    $message    User message.
	 * @param array<int, array{role:string,content:string}> $history History including this user message.
	 * @param string                                    $ip         IP.
	 * @return array{sent:bool,lead:array<string,mixed>,missing:array<int,string>}|null
	 */
	private function process_lead_turn( string $session_id, string $message, array $history, string $ip ): ?array {
		if ( ! $this->leads->enabled() ) {
			return null;
		}
		return $this->leads->process_turn( $session_id, $message, $history, $ip );
	}

	/**
	 * Slim lead status for the REST payload.
	 *
	 * @param array{sent:bool,lead:array<string,mixed>,missing:array<int,string>}|null $processed Processed lead.
	 * @return array{sent:bool,missing:array<int,string>}|null
	 */
	private function lead_status( ?array $processed ): ?array {
		if ( null === $processed ) {
			return null;
		}
		return array(
			'sent'    => ! empty( $processed['sent'] ),
			'missing' => $processed['missing'],
		);
	}

	/**
	 * Process lead capture for FAQ static answers.
	 *
	 * @param string $session_id Session.
	 * @param string $message    User message.
	 * @param string $ip         IP.
	 * @return array{sent:bool,missing:array<int,string>}|null
	 */
	private function maybe_process_lead( string $session_id, string $message, string $ip ): ?array {
		$history   = $this->get_history( $session_id );
		$processed = $this->process_lead_turn( $session_id, $message, $history, $ip );
		return $this->lead_status( $processed );
	}

	/**
	 * Standard chat success payload.
	 *
	 * @param string                                    $reply Assistant reply.
	 * @param array{sent:bool,missing:array<int,string>}|null $lead  Lead status.
	 */
	private function chat_response( string $reply, ?array $lead = null ): WP_REST_Response {
		$payload = array(
			'reply'   => $reply,
			'blocked' => false,
		);
		if ( null !== $lead ) {
			$payload['lead'] = array(
				'sent'    => ! empty( $lead['sent'] ),
				'missing' => $lead['missing'],
			);
		}
		return new WP_REST_Response( $payload, 200 );
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
