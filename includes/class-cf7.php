<?php
/**
 * Contact Form 7 email auto-reply integration.
 *
 * @package QBMBot
 */

declare(strict_types=1);

namespace QBMBot;

use QBMBot\AI\Prompt_Builder;
use QBMBot\AI\Router;
use WPCF7_ContactForm;
use WPCF7_Submission;

/**
 * Hooks into WPCF7 for AI email auto-replies.
 */
final class CF7 {

	/**
	 * @var Settings
	 */
	private Settings $settings;

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
	 * @var Mailer
	 */
	private Mailer $mailer;

	/**
	 * @var Logger
	 */
	private Logger $logger;

	/**
	 * Constructor.
	 *
	 * @param Settings       $settings Settings.
	 * @param Spam_Guard     $spam     Spam guard.
	 * @param Rate_Limiter   $limiter  Rate limiter.
	 * @param Router         $router   AI router.
	 * @param Prompt_Builder $prompts  Prompts.
	 * @param Mailer         $mailer   Mailer.
	 * @param Logger         $logger   Logger.
	 */
	public function __construct(
		Settings $settings,
		Spam_Guard $spam,
		Rate_Limiter $limiter,
		Router $router,
		Prompt_Builder $prompts,
		Mailer $mailer,
		Logger $logger
	) {
		$this->settings = $settings;
		$this->spam     = $spam;
		$this->limiter  = $limiter;
		$this->router   = $router;
		$this->prompts  = $prompts;
		$this->mailer   = $mailer;
		$this->logger   = $logger;
	}

	/**
	 * Register hooks.
	 */
	public function register(): void {
		add_action( 'wpcf7_mail_sent', array( $this, 'on_mail_sent' ), 20, 1 );
		add_action( 'admin_notices', array( $this, 'maybe_notice_missing_cf7' ) );
	}

	/**
	 * Admin notice when CF7 is enabled in settings but plugin missing.
	 */
	public function maybe_notice_missing_cf7(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		if ( ! $this->settings->get( 'cf7_enabled' ) ) {
			return;
		}
		if ( defined( 'WPCF7_VERSION' ) ) {
			return;
		}
		echo '<div class="notice notice-warning"><p>';
		echo esc_html__( 'QBMBOT: Contact Form 7 auto-replies are enabled but Contact Form 7 is not active.', 'qbmbot' );
		echo '</p></div>';
	}

	/**
	 * Handle successful CF7 mail.
	 *
	 * @param WPCF7_ContactForm $contact_form Form object.
	 */
	public function on_mail_sent( $contact_form ): void {
		if ( ! $contact_form instanceof WPCF7_ContactForm ) {
			return;
		}

		$form_id = (int) $contact_form->id();
		if ( ! $this->settings->is_cf7_form_enabled( $form_id ) ) {
			return;
		}

		$submission = WPCF7_Submission::get_instance();
		if ( ! $submission ) {
			return;
		}

		$posted = $submission->get_posted_data();
		if ( ! is_array( $posted ) ) {
			return;
		}

		$fields = $this->map_fields( $posted );
		if ( ! is_email( $fields['email'] ) ) {
			return;
		}

		$ip = $this->spam->client_ip();

		$spam = $this->spam->evaluate(
			array(
				'text'        => $fields['message'],
				'email'       => $fields['email'],
				'name'        => $fields['name'],
				'honeypot'    => '',
				'has_session' => true,
				'channel'     => 'cf7',
			)
		);

		$rate = $this->limiter->allow( $ip, 'cf7_' . $form_id );

		if ( $spam['blocked'] || ! $rate['allowed'] ) {
			$reason = $spam['blocked'] ? 'spam:' . implode( ',', $spam['reasons'] ) : 'rate:' . $rate['reason'];
			$this->logger->log(
				array(
					'channel'    => 'cf7',
					'status'     => 'blocked',
					'reason'     => $reason,
					'spam_score' => $spam['score'],
					'ip_hash'    => Logger::hash_ip( $ip ),
					'meta'       => array( 'form_id' => $form_id ),
				)
			);

			if ( $this->settings->get( 'cf7_send_fallback_on_block' ) ) {
				$body = (string) $this->settings->get( 'cf7_fallback_body', '' );
				$this->mailer->send_auto_reply( $fields['email'], $body );
			}
			return;
		}

		$this->limiter->hit( $ip, 'cf7_' . $form_id );

		$system = $this->prompts->cf7_system( $fields );
		$user   = $this->prompts->cf7_user_message( $fields );
		$result = $this->router->complete(
			$system,
			array(
				array(
					'role'    => 'user',
					'content' => $user,
				),
			)
		);

		if ( ! empty( $result['ok'] ) && '' !== $result['content'] ) {
			$body   = $result['content'];
			$status = 'ok';
			$reason = '';
		} else {
			$body   = (string) $this->settings->get( 'cf7_fallback_body', '' );
			$status = 'fallback';
			$reason = (string) ( $result['error'] ?? 'ai_failed' );
		}

		$sent = $this->mailer->send_auto_reply( $fields['email'], $body );

		$this->logger->log(
			array(
				'channel'    => 'cf7',
				'status'     => $sent ? $status : 'mail_failed',
				'reason'     => $reason,
				'provider'   => (string) ( $result['provider'] ?? '' ),
				'tokens'     => (int) ( $result['tokens'] ?? 0 ),
				'spam_score' => $spam['score'],
				'ip_hash'    => Logger::hash_ip( $ip ),
				'meta'       => array( 'form_id' => $form_id ),
			)
		);
	}

	/**
	 * Map posted CF7 data using configured field names.
	 *
	 * @param array<string, mixed> $posted Posted data.
	 * @return array{name:string,email:string,message:string}
	 */
	private function map_fields( array $posted ): array {
		$name_key    = (string) $this->settings->get( 'cf7_field_name', 'your-name' );
		$email_key   = (string) $this->settings->get( 'cf7_field_email', 'your-email' );
		$message_key = (string) $this->settings->get( 'cf7_field_message', 'your-message' );

		return array(
			'name'    => $this->stringify( $posted[ $name_key ] ?? '' ),
			'email'   => sanitize_email( $this->stringify( $posted[ $email_key ] ?? '' ) ),
			'message' => $this->stringify( $posted[ $message_key ] ?? '' ),
		);
	}

	/**
	 * Flatten CF7 field values (arrays for checkboxes/selects).
	 *
	 * @param mixed $value Field value.
	 */
	private function stringify( $value ): string {
		if ( is_array( $value ) ) {
			$value = implode( ', ', array_map( 'strval', $value ) );
		}
		return sanitize_textarea_field( (string) $value );
	}
}
