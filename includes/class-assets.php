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
		// Immediately before footer scripts (Divi / minify-safe ordering).
		add_action( 'wp_print_footer_scripts', array( $this, 'render_mount' ), 0 );
		add_action( 'wp_footer', array( $this, 'render_mount' ), 999 );
	}

	/**
	 * Whether the chat widget should load on this request.
	 */
	private function should_display(): bool {
		if ( is_admin() && ! wp_doing_ajax() ) {
			return false;
		}
		return (bool) $this->settings->get( 'widget_enabled', true );
	}

	/**
	 * Widget config passed to the frontend script.
	 *
	 * @return array<string, mixed>
	 */
	private function widget_config(): array {
		return array(
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
		);
	}

	/**
	 * Enqueue public assets.
	 */
	public function enqueue_public(): void {
		if ( ! $this->should_display() ) {
			return;
		}

		wp_enqueue_style(
			'qbmbot-chat',
			QBMBot_URL . 'public/css/chat-widget.css',
			array(),
			QBMBot_VERSION
		);

		$this->add_appearance_css_vars();

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

		wp_localize_script( 'qbmbot-chat', 'qbmbotConfig', $this->widget_config() );
	}

	/**
	 * Mount point in footer (printed once per request).
	 */
	public function render_mount(): void {
		static $rendered = false;

		if ( $rendered || ! $this->should_display() ) {
			return;
		}

		$config   = $this->widget_config();
		$rendered = true;
		$position = $this->settings->normalized_position(
			(string) ( $config['appearance']['position'] ?? 'left' )
		);

		printf(
			'<div id="qbmbot-root" class="qbmbot-root qbmbot-root--%3$s" aria-live="polite" data-rest-url="%1$s" data-nonce="%2$s"></div>',
			esc_url( (string) $config['restUrl'] ),
			esc_attr( (string) $config['nonce'] ),
			esc_attr( $position )
		);

		// Fallback if a minifier drops wp_localize_script output.
		printf(
			'<script type="application/json" id="qbmbot-config">%s</script>',
			esc_html(
				(string) wp_json_encode(
					$config,
					JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT
				)
			)
		);
	}

	/**
	 * CSS variables for appearance (bundled with widget stylesheet).
	 */
	private function add_appearance_css_vars(): void {
		$a = $this->settings->public_appearance();
		$font = str_replace( array( ';', '{', '}' ), '', (string) $a['font'] );

		$css = sprintf(
			'.qbmbot-root{--qbmbot-primary:%1$s;--qbmbot-bg:%2$s;--qbmbot-text:%3$s;--qbmbot-panel:%4$s;--qbmbot-font:%5$s;--qbmbot-radius:%6$dpx;--qbmbot-offset-x:%7$dpx;--qbmbot-offset-y:%8$dpx;display:block!important;visibility:visible!important;}',
			(string) $a['primary'],
			(string) $a['background'],
			(string) $a['text'],
			(string) $a['panelBg'],
			$font,
			(int) $a['radius'],
			(int) $a['offsetX'],
			(int) $a['offsetY']
		);

		wp_add_inline_style( 'qbmbot-chat', $css );
	}
}
