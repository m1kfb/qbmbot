<?php
/**
 * Admin settings UI.
 *
 * @package QBMBot
 */

declare(strict_types=1);

namespace QBMBot;

/**
 * Registers QBMBOT admin menu and settings.
 */
final class Admin {

	/**
	 * @var Settings
	 */
	private Settings $settings;

	/**
	 * @var FAQ_Store
	 */
	private FAQ_Store $faq;

	/**
	 * @var Logger
	 */
	private Logger $logger;

	/**
	 * @var Rate_Limiter
	 */
	private Rate_Limiter $limiter;

	/**
	 * Constructor.
	 *
	 * @param Settings     $settings Settings.
	 * @param FAQ_Store    $faq      FAQ.
	 * @param Logger       $logger   Logger.
	 * @param Rate_Limiter $limiter  Limiter.
	 */
	public function __construct( Settings $settings, FAQ_Store $faq, Logger $logger, Rate_Limiter $limiter ) {
		$this->settings = $settings;
		$this->faq      = $faq;
		$this->logger   = $logger;
		$this->limiter  = $limiter;
	}

	/**
	 * Register hooks.
	 */
	public function register(): void {
		add_action( 'admin_menu', array( $this, 'menu' ) );
		add_action( 'admin_init', array( $this, 'handle_save' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue' ) );
		add_action( 'admin_notices', array( $this, 'maybe_notice_missing_ai_key' ) );
	}

	/**
	 * Warn admins when chat AI is not configured.
	 */
	public function maybe_notice_missing_ai_key(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( $screen && 'plugins' !== $screen->id && 0 !== strpos( (string) $screen->id, 'toplevel_page_qbmbot' ) ) {
			return;
		}

		if ( ! $this->settings->get( 'widget_enabled' ) ) {
			return;
		}

		$provider = (string) $this->settings->get( 'active_provider', 'openai' );
		$key_name = 'anthropic' === $provider ? 'anthropic_api_key' : 'openai_api_key';
		if ( '' !== trim( (string) $this->settings->get( $key_name, '' ) ) ) {
			return;
		}

		echo '<div class="notice notice-warning"><p>';
		echo wp_kses_post(
			sprintf(
				/* translators: %s: admin settings link */
				__( 'QBMBOT chat is enabled but no AI API key is configured. Chat will use offline fallback replies until you add a key under %s.', 'qbmbot' ),
				'<a href="' . esc_url( admin_url( 'admin.php?page=qbmbot&tab=providers' ) ) . '">' . esc_html__( 'AI Providers', 'qbmbot' ) . '</a>'
			)
		);
		echo '</p></div>';
	}

	/**
	 * Admin menu.
	 */
	public function menu(): void {
		add_menu_page(
			__( 'QBMBOT', 'qbmbot' ),
			__( 'QBMBOT', 'qbmbot' ),
			'manage_options',
			'qbmbot',
			array( $this, 'render_page' ),
			'dashicons-format-chat',
			58
		);
	}

	/**
	 * Enqueue admin assets.
	 *
	 * @param string $hook Hook suffix.
	 */
	public function enqueue( string $hook ): void {
		if ( 'toplevel_page_qbmbot' !== $hook ) {
			return;
		}
		wp_enqueue_style( 'qbmbot-admin', QBMBot_URL . 'admin/css/admin.css', array(), QBMBot_VERSION );
		wp_enqueue_script( 'qbmbot-admin', QBMBot_URL . 'admin/js/admin.js', array(), QBMBot_VERSION, true );
		wp_enqueue_style( 'wp-color-picker' );
		wp_enqueue_script( 'wp-color-picker' );
	}

	/**
	 * Persist settings from POST.
	 */
	public function handle_save(): void {
		if ( ! isset( $_POST['qbmbot_save'] ) ) {
			return;
		}
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		check_admin_referer( 'qbmbot_save_settings' );

		$tab = isset( $_POST['qbmbot_tab'] ) ? sanitize_key( wp_unslash( (string) $_POST['qbmbot_tab'] ) ) : 'general';

		switch ( $tab ) {
			case 'providers':
				$this->save_providers();
				break;
			case 'faq':
				$this->save_faq();
				break;
			case 'spam':
				$this->save_spam();
				break;
			case 'appearance':
				$this->save_appearance();
				break;
			case 'cf7':
				$this->save_cf7();
				break;
			case 'business':
				$this->save_business();
				break;
			case 'general':
			default:
				$this->save_general();
				break;
		}

		$this->settings->flush_cache();

		wp_safe_redirect(
			add_query_arg(
				array(
					'page'             => 'qbmbot',
					'tab'              => $tab,
					'qbmbot_updated'   => '1',
				),
				admin_url( 'admin.php' )
			)
		);
		exit;
	}

	/**
	 * Render settings page.
	 */
	public function render_page(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$tab      = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( (string) $_GET['tab'] ) ) : 'general'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$settings = $this->settings->all();
		$faqs     = $this->faq->all();
		$logs     = 'logs' === $tab ? $this->logger->recent( 50 ) : array();
		$usage    = $this->limiter->usage_snapshot();
		$cf7_forms = $this->list_cf7_forms();
		$content_posts = 'business' === $tab ? $this->list_content_posts() : array();

		include QBMBot_PATH . 'admin/views/settings.php';
	}

	/**
	 * Published pages/posts for content pinning UI.
	 *
	 * @return array<int, array{id:int,title:string,type:string}>
	 */
	private function list_content_posts(): array {
		$posts = get_posts(
			array(
				'post_type'              => array( 'page', 'post' ),
				'post_status'            => 'publish',
				'posts_per_page'         => 200,
				'orderby'                => 'title',
				'order'                  => 'ASC',
				'no_found_rows'          => true,
				'update_post_meta_cache' => false,
				'update_post_term_cache' => false,
			)
		);
		$out = array();
		foreach ( $posts as $post ) {
			$out[] = array(
				'id'    => (int) $post->ID,
				'title' => (string) $post->post_title,
				'type'  => (string) $post->post_type,
			);
		}
		return $out;
	}

	/**
	 * @return array<int, array{id:int,title:string}>
	 */
	private function list_cf7_forms(): array {
		if ( ! class_exists( 'WPCF7_ContactForm' ) ) {
			return array();
		}
		$forms = \WPCF7_ContactForm::find( array( 'posts_per_page' => 100 ) );
		$out   = array();
		foreach ( $forms as $form ) {
			$out[] = array(
				'id'    => (int) $form->id(),
				'title' => (string) $form->title(),
			);
		}
		return $out;
	}

	/**
	 * Save general tab.
	 */
	private function save_general(): void {
		$github_token = (string) $this->settings->get( 'github_token', '' );
		$github_in    = trim( (string) wp_unslash( (string) ( $_POST['github_token'] ?? '' ) ) );
		if ( '' !== $github_in && ! $this->is_masked_secret( $github_in ) ) {
			$github_token = sanitize_text_field( $github_in );
		}

		$this->settings->update(
			array(
				'widget_enabled'           => ! empty( $_POST['widget_enabled'] ),
				'cf7_enabled'              => ! empty( $_POST['cf7_enabled'] ),
				'global_prompt'            => sanitize_textarea_field( wp_unslash( (string) ( $_POST['global_prompt'] ?? '' ) ) ),
				'welcome_message'          => sanitize_text_field( wp_unslash( (string) ( $_POST['welcome_message'] ?? '' ) ) ),
				'chat_fallback_message'    => sanitize_textarea_field( wp_unslash( (string) ( $_POST['chat_fallback_message'] ?? '' ) ) ),
				'lead_capture_enabled'     => ! empty( $_POST['lead_capture_enabled'] ),
				'lead_require_phone'       => ! empty( $_POST['lead_require_phone'] ),
				'lead_notify_email'        => sanitize_email( wp_unslash( (string) ( $_POST['lead_notify_email'] ?? '' ) ) ),
				'lead_email_subject'       => sanitize_text_field( wp_unslash( (string) ( $_POST['lead_email_subject'] ?? '' ) ) ),
				'lead_capture_guidance'    => sanitize_textarea_field( wp_unslash( (string) ( $_POST['lead_capture_guidance'] ?? '' ) ) ),
				'github_token'             => $github_token,
				'blocked_message'          => sanitize_text_field( wp_unslash( (string) ( $_POST['blocked_message'] ?? '' ) ) ),
				'delete_data_on_uninstall' => ! empty( $_POST['delete_data_on_uninstall'] ),
			)
		);

		// New token should force a fresh release lookup.
		delete_transient( Updater::CACHE_KEY );
	}

	/**
	 * Save business profile + site content sources.
	 */
	private function save_business(): void {
		$pinned  = $this->parse_id_list( $_POST['content_pinned_ids'] ?? array() ); // phpcs:ignore WordPress.Security.NonceVerification.Missing,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		$exclude = $this->parse_id_list( $_POST['content_exclude_ids'] ?? array() ); // phpcs:ignore WordPress.Security.NonceVerification.Missing,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized

		$types = array();
		if ( isset( $_POST['content_post_types'] ) && is_array( $_POST['content_post_types'] ) ) {
			foreach ( wp_unslash( $_POST['content_post_types'] ) as $type ) { // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
				$type = sanitize_key( (string) $type );
				if ( in_array( $type, array( 'page', 'post' ), true ) ) {
					$types[] = $type;
				}
			}
		}
		if ( empty( $types ) ) {
			$types = array( 'page', 'post' );
		}

		$this->settings->update(
			array(
				'business_name'            => sanitize_text_field( wp_unslash( (string) ( $_POST['business_name'] ?? '' ) ) ),
				'business_trade'           => sanitize_text_field( wp_unslash( (string) ( $_POST['business_trade'] ?? '' ) ) ),
				'business_services'        => sanitize_textarea_field( wp_unslash( (string) ( $_POST['business_services'] ?? '' ) ) ),
				'business_service_area'    => sanitize_textarea_field( wp_unslash( (string) ( $_POST['business_service_area'] ?? '' ) ) ),
				'business_notes'           => sanitize_textarea_field( wp_unslash( (string) ( $_POST['business_notes'] ?? '' ) ) ),
				'business_refuse_topics'   => sanitize_textarea_field( wp_unslash( (string) ( $_POST['business_refuse_topics'] ?? '' ) ) ),
				'business_handoff_message' => sanitize_textarea_field( wp_unslash( (string) ( $_POST['business_handoff_message'] ?? '' ) ) ),
				'content_enabled'          => ! empty( $_POST['content_enabled'] ),
				'content_post_types'       => $types,
				'content_pinned_ids'       => $pinned,
				'content_exclude_ids'      => $exclude,
				'content_search_limit'     => max( 1, min( 10, (int) ( $_POST['content_search_limit'] ?? 5 ) ) ),
				'content_max_chars'        => max( 500, min( 20000, (int) ( $_POST['content_max_chars'] ?? 6000 ) ) ),
				'content_per_post_chars'   => max( 200, min( 4000, (int) ( $_POST['content_per_post_chars'] ?? 1500 ) ) ),
			)
		);
	}

	/**
	 * Parse checkbox or CSV ID lists.
	 *
	 * @param mixed $raw Raw input.
	 * @return array<int, int>
	 */
	private function parse_id_list( $raw ): array {
		$ids = array();
		if ( is_array( $raw ) ) {
			foreach ( wp_unslash( $raw ) as $id ) {
				$ids[] = (int) $id;
			}
		} elseif ( is_string( $raw ) && '' !== trim( $raw ) ) {
			foreach ( preg_split( '/[\s,]+/', wp_unslash( $raw ) ) ?: array() as $id ) {
				$ids[] = (int) $id;
			}
		}
		return array_values( array_unique( array_filter( $ids ) ) );
	}

	/**
	 * Save providers tab.
	 */
	private function save_providers(): void {
		$provider = sanitize_key( wp_unslash( (string) ( $_POST['active_provider'] ?? 'openai' ) ) );
		if ( ! in_array( $provider, array( 'openai', 'anthropic' ), true ) ) {
			$provider = 'openai';
		}

		$openai_key    = $this->settings->get( 'openai_api_key', '' );
		$anthropic_key = $this->settings->get( 'anthropic_api_key', '' );

		$openai_in = trim( (string) wp_unslash( (string) ( $_POST['openai_api_key'] ?? '' ) ) );
		if ( '' !== $openai_in && ! $this->is_masked_secret( $openai_in ) ) {
			$openai_key = sanitize_text_field( $openai_in );
		}

		$anthropic_in = trim( (string) wp_unslash( (string) ( $_POST['anthropic_api_key'] ?? '' ) ) );
		if ( '' !== $anthropic_in && ! $this->is_masked_secret( $anthropic_in ) ) {
			$anthropic_key = sanitize_text_field( $anthropic_in );
		}

		$this->settings->update(
			array(
				'active_provider'   => $provider,
				'openai_api_key'    => $openai_key,
				'openai_model'      => sanitize_text_field( wp_unslash( (string) ( $_POST['openai_model'] ?? 'gpt-4o-mini' ) ) ),
				'anthropic_api_key' => $anthropic_key,
				'anthropic_model'   => sanitize_text_field( wp_unslash( (string) ( $_POST['anthropic_model'] ?? 'claude-3-5-haiku-latest' ) ) ),
				'max_tokens'        => max( 50, min( 4000, (int) ( $_POST['max_tokens'] ?? 500 ) ) ),
				'temperature'       => max( 0, min( 2, (float) ( $_POST['temperature'] ?? 0.4 ) ) ),
			)
		);
	}

	/**
	 * Save FAQ repeater.
	 */
	private function save_faq(): void {
		$raw = isset( $_POST['faq'] ) && is_array( $_POST['faq'] ) ? wp_unslash( $_POST['faq'] ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		$items = array();
		foreach ( $raw as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}
			$items[] = array(
				'id'              => sanitize_key( (string) ( $row['id'] ?? '' ) ),
				'question'        => sanitize_text_field( (string) ( $row['question'] ?? '' ) ),
				'answer'          => sanitize_textarea_field( (string) ( $row['answer'] ?? '' ) ),
				'ai_instructions' => sanitize_textarea_field( (string) ( $row['ai_instructions'] ?? '' ) ),
				'enabled'         => ! empty( $row['enabled'] ),
				'order'           => (int) ( $row['order'] ?? 0 ),
			);
		}
		$this->faq->save( $items );
	}

	/**
	 * Save spam & limits.
	 */
	private function save_spam(): void {
		$this->settings->update(
			array(
				'spam_threshold'     => max( 1, min( 50, (int) ( $_POST['spam_threshold'] ?? 5 ) ) ),
				'min_message_length' => max( 1, min( 50, (int) ( $_POST['min_message_length'] ?? 2 ) ) ),
				'min_open_ms'        => max( 0, min( 10000, (int) ( $_POST['min_open_ms'] ?? 800 ) ) ),
				'honeypot_enabled'   => ! empty( $_POST['honeypot_enabled'] ),
				'use_akismet'        => ! empty( $_POST['use_akismet'] ),
				'rate_ip_limit'      => max( 1, min( 1000, (int) ( $_POST['rate_ip_limit'] ?? 10 ) ) ),
				'rate_ip_window'     => max( 60, min( DAY_IN_SECONDS, (int) ( $_POST['rate_ip_window'] ?? 600 ) ) ),
				'rate_session_limit' => max( 1, min( 1000, (int) ( $_POST['rate_session_limit'] ?? 30 ) ) ),
				'rate_daily_site_cap'=> max( 1, min( 100000, (int) ( $_POST['rate_daily_site_cap'] ?? 500 ) ) ),
			)
		);
	}

	/**
	 * Save appearance.
	 */
	private function save_appearance(): void {
		$this->settings->update(
			array(
				'appearance_primary'    => $this->sanitize_hex( (string) ( $_POST['appearance_primary'] ?? '#1a5f4a' ) ),
				'appearance_background' => $this->sanitize_hex( (string) ( $_POST['appearance_background'] ?? '#ffffff' ) ),
				'appearance_text'       => $this->sanitize_hex( (string) ( $_POST['appearance_text'] ?? '#1a1a1a' ) ),
				'appearance_panel_bg'   => $this->sanitize_hex( (string) ( $_POST['appearance_panel_bg'] ?? '#f7f7f5' ) ),
				'appearance_font'       => sanitize_text_field( wp_unslash( (string) ( $_POST['appearance_font'] ?? '' ) ) ),
				'appearance_radius'     => max( 0, min( 40, (int) ( $_POST['appearance_radius'] ?? 12 ) ) ),
				'appearance_logo'       => esc_url_raw( wp_unslash( (string) ( $_POST['appearance_logo'] ?? '' ) ) ),
				'appearance_title'      => sanitize_text_field( wp_unslash( (string) ( $_POST['appearance_title'] ?? '' ) ) ),
				'appearance_subtitle'   => sanitize_text_field( wp_unslash( (string) ( $_POST['appearance_subtitle'] ?? '' ) ) ),
				'appearance_position'   => $this->settings->normalized_position(
					sanitize_key( wp_unslash( (string) ( $_POST['appearance_position'] ?? 'left' ) ) )
				),
				'appearance_offset_x'   => max( 0, min( 120, (int) ( $_POST['appearance_offset_x'] ?? 20 ) ) ),
				'appearance_offset_y'   => max( 0, min( 120, (int) ( $_POST['appearance_offset_y'] ?? 20 ) ) ),
				'appearance_custom_css' => $this->sanitize_css( wp_unslash( (string) ( $_POST['appearance_custom_css'] ?? '' ) ) ),
			)
		);
	}

	/**
	 * Save CF7 tab.
	 */
	private function save_cf7(): void {
		$form_ids = array();
		if ( isset( $_POST['cf7_form_ids'] ) && is_array( $_POST['cf7_form_ids'] ) ) {
			foreach ( wp_unslash( $_POST['cf7_form_ids'] ) as $id ) { // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
				$form_ids[] = (int) $id;
			}
		}

		$this->settings->update(
			array(
				'cf7_form_ids'               => $form_ids,
				'cf7_field_name'             => sanitize_text_field( wp_unslash( (string) ( $_POST['cf7_field_name'] ?? 'your-name' ) ) ),
				'cf7_field_email'            => sanitize_text_field( wp_unslash( (string) ( $_POST['cf7_field_email'] ?? 'your-email' ) ) ),
				'cf7_field_message'          => sanitize_text_field( wp_unslash( (string) ( $_POST['cf7_field_message'] ?? 'your-message' ) ) ),
				'cf7_email_subject'          => sanitize_text_field( wp_unslash( (string) ( $_POST['cf7_email_subject'] ?? '' ) ) ),
				'cf7_from_name'              => sanitize_text_field( wp_unslash( (string) ( $_POST['cf7_from_name'] ?? '' ) ) ),
				'cf7_from_email'             => sanitize_email( wp_unslash( (string) ( $_POST['cf7_from_email'] ?? '' ) ) ),
				'cf7_fallback_body'          => sanitize_textarea_field( wp_unslash( (string) ( $_POST['cf7_fallback_body'] ?? '' ) ) ),
				'cf7_send_fallback_on_block' => ! empty( $_POST['cf7_send_fallback_on_block'] ),
				'cf7_ask_follow_up'          => ! empty( $_POST['cf7_ask_follow_up'] ),
				'cf7_ask_photos'             => ! empty( $_POST['cf7_ask_photos'] ),
				'cf7_follow_up_guidance'     => sanitize_textarea_field( wp_unslash( (string) ( $_POST['cf7_follow_up_guidance'] ?? '' ) ) ),
			)
		);
	}

	/**
	 * Detect masked secret placeholders.
	 *
	 * @param string $value Input.
	 */
	private function is_masked_secret( string $value ): bool {
		return (bool) preg_match( '/^\*+$/', $value ) || false !== strpos( $value, '••••' );
	}

	/**
	 * Sanitize hex color.
	 *
	 * @param string $color Color.
	 */
	private function sanitize_hex( string $color ): string {
		$color = sanitize_hex_color( wp_unslash( $color ) );
		return $color ? $color : '#1a5f4a';
	}

	/**
	 * Light CSS sanitization (strip tags / null bytes).
	 *
	 * @param string $css CSS.
	 */
	private function sanitize_css( string $css ): string {
		$css = str_replace( "\0", '', $css );
		$css = wp_strip_all_tags( $css );
		return $css;
	}

	/**
	 * Mask API key for display.
	 *
	 * @param string $key Key.
	 */
	public static function mask_key( string $key ): string {
		$key = trim( $key );
		if ( '' === $key ) {
			return '';
		}
		$len = strlen( $key );
		if ( $len <= 8 ) {
			return str_repeat( '•', $len );
		}
		return substr( $key, 0, 3 ) . str_repeat( '•', max( 6, $len - 7 ) ) . substr( $key, -4 );
	}
}
