<?php
/**
 * Frontend and admin asset loading.
 *
 * @package QBMBot
 */

declare(strict_types=1);

namespace QBMBot;

/**
 * Enqueues chat widget assets (Divi-safe: no Divi script deps).
 */
final class Assets {

	/**
	 * @var Settings
	 */
	private Settings $settings;

	/**
	 * @var FAQ_Store
	 */
	private FAQ_Store $faq;

	/**
	 * Constructor.
	 *
	 * @param Settings  $settings Settings.
	 * @param FAQ_Store $faq      FAQ.
	 */
	public function __construct( Settings $settings, FAQ_Store $faq ) {
		$this->settings = $settings;
		$this->faq      = $faq;
	}

	/**
	 * Register hooks.
	 */
	public function register(): void {
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_public' ) );
		add_action( 'wp_footer', array( $this, 'render_mount' ), 5 );
		add_action( 'wp_head', array( $this, 'inline_css_vars' ), 20 );
	}

	/**
	 * Enqueue public assets.
	 */
	public function enqueue_public(): void {
		if ( is_admin() || ! $this->settings->get( 'widget_enabled' ) ) {
			return;
		}

		wp_enqueue_style(
			'qbmbot-chat',
			QBMBot_URL . 'public/css/chat-widget.css',
			array(),
			QBMBot_VERSION
		);

		$custom = trim( (string) $this->settings->get( 'appearance_custom_css', '' ) );
		if ( '' !== $custom ) {
			wp_add_inline_style( 'qbmbot-chat', $custom );
		}

		wp_enqueue_script(
			'qbmbot-chat',
			QBMBot_URL . 'public/js/chat-widget.js',
			array(),
			QBMBot_VERSION,
			true
		);

		wp_localize_script(
			'qbmbot-chat',
			'qbmbotConfig',
			array(
				'restUrl'    => esc_url_raw( rest_url( 'qbmbot/v1/chat' ) ),
				'nonce'      => wp_create_nonce( 'wp_rest' ),
				'faqs'       => $this->faq->public_items(),
				'appearance' => $this->settings->public_appearance(),
				'i18n'       => array(
					'placeholder' => __( 'Type your message…', 'qbmbot' ),
					'send'        => __( 'Send', 'qbmbot' ),
					'error'       => __( 'Something went wrong. Please try again.', 'qbmbot' ),
					'rateLimited' => __( 'Too many messages. Please wait a moment.', 'qbmbot' ),
					'openChat'    => __( 'Open chat', 'qbmbot' ),
					'closeChat'   => __( 'Close chat', 'qbmbot' ),
				),
			)
		);
	}

	/**
	 * Mount point in footer.
	 */
	public function render_mount(): void {
		if ( is_admin() || ! $this->settings->get( 'widget_enabled' ) ) {
			return;
		}
		echo '<div id="qbmbot-root" class="qbmbot-root" aria-live="polite"></div>';
	}

	/**
	 * CSS variables for appearance.
	 */
	public function inline_css_vars(): void {
		if ( is_admin() || ! $this->settings->get( 'widget_enabled' ) ) {
			return;
		}
		$a = $this->settings->public_appearance();
		printf(
			'<style id="qbmbot-vars">.qbmbot-root{--qbmbot-primary:%1$s;--qbmbot-bg:%2$s;--qbmbot-text:%3$s;--qbmbot-panel:%4$s;--qbmbot-font:%5$s;--qbmbot-radius:%6$dpx;--qbmbot-offset-x:%7$dpx;--qbmbot-offset-y:%8$dpx;}</style>',
			esc_attr( (string) $a['primary'] ),
			esc_attr( (string) $a['background'] ),
			esc_attr( (string) $a['text'] ),
			esc_attr( (string) $a['panelBg'] ),
			esc_attr( (string) $a['font'] ),
			(int) $a['radius'],
			(int) $a['offsetX'],
			(int) $a['offsetY']
		);
	}
}
