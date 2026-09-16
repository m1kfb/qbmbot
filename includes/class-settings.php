<?php
/**
 * Settings API and defaults.
 *
 * @package QBMBot
 */

declare(strict_types=1);

namespace QBMBot;

/**
 * Reads and writes plugin options.
 */
final class Settings {

	public const OPTION_KEY = 'qbmbot_settings';

	/**
	 * Cached options.
	 *
	 * @var array<string, mixed>|null
	 */
	private ?array $cache = null;

	/**
	 * Ensure default options exist.
	 */
	public static function ensure_defaults(): void {
		$existing = get_option( self::OPTION_KEY, null );
		if ( null === $existing ) {
			add_option( self::OPTION_KEY, self::defaults() );
			return;
		}
		update_option( self::OPTION_KEY, wp_parse_args( (array) $existing, self::defaults() ) );
	}

	/**
	 * Default settings.
	 *
	 * @return array<string, mixed>
	 */
	public static function defaults(): array {
		return array(
			'widget_enabled'            => true,
			'cf7_enabled'               => true,
			'global_prompt'             => 'Speak as a friendly, professional member of this trades/SME business. Be brief, practical, and local. Encourage genuine enquiries via the contact form when a quote or booking is needed.',
			'business_name'             => '',
			'business_trade'            => '',
			'business_services'         => '',
			'business_service_area'     => '',
			'business_notes'            => '',
			'business_refuse_topics'    => 'general DIY how-tos, competitor recommendations, legal/medical advice, anything unrelated to our services',
			'business_handoff_message'  => 'I can only help with questions about our business and services. Please ask about what we offer, or leave a message via the contact form and the team will get back to you.',
			'content_enabled'           => true,
			'content_post_types'        => array( 'page', 'post' ),
			'content_pinned_ids'        => array(),
			'content_exclude_ids'       => array(),
			'content_search_limit'      => 5,
			'content_max_chars'         => 6000,
			'content_per_post_chars'    => 1500,
			'active_provider'           => 'openai',
			'openai_api_key'            => '',
			'openai_model'              => 'gpt-4o-mini',
			'anthropic_api_key'         => '',
			'anthropic_model'           => 'claude-3-5-haiku-latest',
			'max_tokens'                => 500,
			'temperature'               => 0.4,
			'spam_threshold'            => 5,
			'min_message_length'        => 2,
			'min_open_ms'               => 800,
			'honeypot_enabled'          => true,
			'use_akismet'               => true,
			'rate_ip_limit'             => 10,
			'rate_ip_window'            => 600,
			'rate_session_limit'        => 30,
			'rate_session_window'       => DAY_IN_SECONDS,
			'rate_daily_site_cap'       => 500,
			'cf7_form_ids'              => array(),
			'cf7_field_name'            => 'your-name',
			'cf7_field_email'           => 'your-email',
			'cf7_field_message'         => 'your-message',
			'cf7_email_subject'         => 'Re: Your enquiry',
			'cf7_from_name'             => '',
			'cf7_from_email'            => '',
			'cf7_fallback_body'         => "Thank you for getting in touch. We have received your message and will reply as soon as we can.\n\nKind regards",
			'cf7_send_fallback_on_block'=> true,
			'cf7_ask_follow_up'         => true,
			'cf7_ask_photos'            => true,
			'cf7_follow_up_guidance'    => "When the enquiry matches a service we offer, ask them to reply to this email with any missing practical details (location/address or area, urgency, access, property type, when the issue started, and anything else that helps us quote or book). If photos would genuinely help assess the job (leaks, damage, boilers, electrics, roofs, gardens, vehicles, etc.), politely ask them to reply with clear photos. Do not ask for photos for simple availability, pricing-policy, or admin questions. Skip follow-up requests if the enquiry is outside our services or area.",
			'appearance_primary'        => '#1a5f4a',
			'appearance_background'     => '#ffffff',
			'appearance_text'           => '#1a1a1a',
			'appearance_panel_bg'       => '#f7f7f5',
			'appearance_font'           => 'Georgia, "Times New Roman", serif',
			'appearance_radius'         => 12,
			'appearance_logo'           => '',
			'appearance_title'          => 'Chat with us',
			'appearance_subtitle'       => 'Ask a question and we will help.',
			'appearance_position'       => 'left',
			'appearance_offset_x'       => 20,
			'appearance_offset_y'       => 20,
			'appearance_custom_css'     => '',
			'welcome_message'           => 'Hi! How can we help today?',
			'chat_fallback_message'     => 'Thanks for your message. How can we help today?',
			'lead_capture_enabled'      => true,
			'lead_require_phone'        => true,
			'lead_notify_email'         => '',
			'lead_email_subject'        => 'New chat enquiry',
			'lead_capture_guidance'     => 'After you know the job type, ask practical follow-ups before contact details (e.g. rewire: room use, sockets/lights, house or flat). Never invent prices — say a team member will follow up.',
			'blocked_message'           => 'Sorry, chat is temporarily unavailable. Please try again later or use the contact form.',
			'delete_data_on_uninstall'  => false,
		);
	}

	/**
	 * Get all settings merged with defaults.
	 *
	 * @return array<string, mixed>
	 */
	public function all(): array {
		if ( null === $this->cache ) {
			$stored      = get_option( self::OPTION_KEY, array() );
			$this->cache = wp_parse_args( is_array( $stored ) ? $stored : array(), self::defaults() );
		}
		return $this->cache;
	}

	/**
	 * Get a single setting.
	 *
	 * @param string $key     Setting key.
	 * @param mixed  $default Fallback.
	 * @return mixed
	 */
	public function get( string $key, $default = null ) {
		$all = $this->all();
		return array_key_exists( $key, $all ) ? $all[ $key ] : $default;
	}

	/**
	 * Update settings.
	 *
	 * @param array<string, mixed> $data Partial settings.
	 */
	public function update( array $data ): void {
		$merged      = wp_parse_args( $data, $this->all() );
		$this->cache = $merged;
		update_option( self::OPTION_KEY, $merged );
	}

	/**
	 * Clear in-memory cache.
	 */
	public function flush_cache(): void {
		$this->cache = null;
	}

	/**
	 * Whether a CF7 form should get auto-replies.
	 *
	 * Empty list = all forms.
	 *
	 * @param int $form_id Form ID.
	 */
	public function is_cf7_form_enabled( int $form_id ): bool {
		if ( ! $this->get( 'cf7_enabled' ) ) {
			return false;
		}
		$ids = $this->get( 'cf7_form_ids', array() );
		if ( ! is_array( $ids ) || empty( $ids ) ) {
			return true;
		}
		$ids = array_map( 'intval', $ids );
		return in_array( $form_id, $ids, true );
	}

	/**
	 * Public appearance settings safe for frontend.
	 *
	 * @return array<string, mixed>
	 */
	public function public_appearance(): array {
		return array(
			'primary'    => (string) $this->get( 'appearance_primary' ),
			'background' => (string) $this->get( 'appearance_background' ),
			'text'       => (string) $this->get( 'appearance_text' ),
			'panelBg'    => (string) $this->get( 'appearance_panel_bg' ),
			'font'       => (string) $this->get( 'appearance_font' ),
			'radius'     => (int) $this->get( 'appearance_radius' ),
			'logo'       => (string) $this->get( 'appearance_logo' ),
			'title'      => (string) $this->get( 'appearance_title' ),
			'subtitle'   => (string) $this->get( 'appearance_subtitle' ),
			'position'   => $this->normalized_position( (string) $this->get( 'appearance_position', 'left' ) ),
			'offsetX'    => (int) $this->get( 'appearance_offset_x' ),
			'offsetY'    => (int) $this->get( 'appearance_offset_y' ),
			'welcome'    => (string) $this->get( 'welcome_message' ),
		);
	}

	/**
	 * Normalize widget horizontal position.
	 *
	 * @param string $position Raw position.
	 */
	public function normalized_position( string $position ): string {
		return 'right' === $position ? 'right' : 'left';
	}
}
