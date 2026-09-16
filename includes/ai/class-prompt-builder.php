<?php
/**
 * Builds system prompts for chat and CF7 replies.
 *
 * @package QBMBot
 */

declare(strict_types=1);

namespace QBMBot\AI;

use QBMBot\Content_Source;
use QBMBot\FAQ_Store;
use QBMBot\Settings;

/**
 * Prompt assembly helpers.
 */
final class Prompt_Builder {

	/**
	 * Settings.
	 *
	 * @var Settings
	 */
	private Settings $settings;

	/**
	 * FAQ store.
	 *
	 * @var FAQ_Store
	 */
	private FAQ_Store $faq;

	/**
	 * Site content source.
	 *
	 * @var Content_Source
	 */
	private Content_Source $content;

	/**
	 * Constructor.
	 *
	 * @param Settings       $settings Settings.
	 * @param FAQ_Store      $faq      FAQ store.
	 * @param Content_Source $content  Content source.
	 */
	public function __construct( Settings $settings, FAQ_Store $faq, Content_Source $content ) {
		$this->settings = $settings;
		$this->faq      = $faq;
		$this->content  = $content;
	}

	/**
	 * System prompt for chat.
	 *
	 * @param string|null $faq_id Optional FAQ id.
	 * @param string|null $question_text Optional question for matching / content search.
	 */
	public function chat_system( ?string $faq_id = null, ?string $question_text = null ): string {
		$parts   = array();
		$parts[] = $this->business_identity_block();
		$parts[] = $this->scope_rules_block();
		$parts[] = (string) $this->settings->get( 'global_prompt', '' );

		$item = null;
		if ( $faq_id ) {
			$item = $this->faq->find( $faq_id );
		}
		if ( ! $item && $question_text ) {
			$item = $this->faq->match_question( $question_text );
		}

		if ( $item && ! empty( $item['ai_instructions'] ) ) {
			$parts[] = 'Instructions for this question: ' . (string) $item['ai_instructions'];
			if ( ! empty( $item['answer'] ) ) {
				$parts[] = 'Reference answer (adapt, do not invent conflicting facts): ' . (string) $item['answer'];
			}
		}

		$site = $this->content->context_for_query( (string) $question_text );
		if ( '' !== $site ) {
			$parts[] = $site;
		}

		$parts[] = 'Keep replies concise (2–4 short paragraphs max unless asked for detail). Stay in character as this business only.';

		return implode( "\n\n", array_filter( array_map( 'trim', $parts ) ) );
	}

	/**
	 * System prompt for CF7 email auto-reply.
	 *
	 * @param array<string, string> $fields name, email, message.
	 */
	public function cf7_system( array $fields ): string {
		$parts   = array();
		$parts[] = $this->business_identity_block();
		$parts[] = $this->scope_rules_block();
		$parts[] = (string) $this->settings->get( 'global_prompt', '' );
		$parts[] = $this->cf7_reply_instructions_block();

		$faq_bits = array();
		foreach ( $this->faq->all() as $item ) {
			if ( empty( $item['enabled'] ) ) {
				continue;
			}
			$line = '- ' . ( $item['question'] ?? '' );
			if ( ! empty( $item['ai_instructions'] ) ) {
				$line .= ' | ' . $item['ai_instructions'];
			}
			$faq_bits[] = $line;
		}
		if ( $faq_bits ) {
			$parts[] = "Known topics:\n" . implode( "\n", $faq_bits );
		}

		$message = (string) ( $fields['message'] ?? '' );
		$site    = $this->content->context_for_query( $message );
		if ( '' !== $site ) {
			$parts[] = $site;
		}

		return implode( "\n\n", array_filter( array_map( 'trim', $parts ) ) );
	}

	/**
	 * Core CF7 auto-reply instructions, including optional detail/photo follow-up.
	 */
	private function cf7_reply_instructions_block(): string {
		$lines   = array();
		$lines[] = 'Write a polite email auto-reply to a contact form enquiry for THIS business only.';
		$lines[] = 'Address the sender by name if available. Acknowledge their message.';
		$lines[] = 'Answer only using the business profile, FAQ, and site content.';
		$lines[] = 'Do not invent prices, guarantees, licences, or availability.';
		$lines[] = 'If the enquiry is off-topic or outside services/area, politely say you cannot help with that and invite them to leave details for the team. Do not ask for job photos in that case.';
		$lines[] = 'Sign off as this business. Output plain text email body only (no subject line, no markdown fences).';

		$ask_follow_up = (bool) $this->settings->get( 'cf7_ask_follow_up', true );
		$ask_photos    = (bool) $this->settings->get( 'cf7_ask_photos', true );

		if ( $ask_follow_up || $ask_photos ) {
			$lines[] = 'FOLLOW-UP REQUESTS (only when the enquiry clearly matches a service this business offers and is in scope):';
			$lines[] = '- Invite them to reply to this email with any extra relevant details still needed to help the team assess, quote, or book the job.';
			$lines[] = '- Ask only for details that are missing or unclear from their message; do not repeat facts they already provided.';
			$lines[] = '- Keep the ask short (a few bullet points or one short paragraph).';

			if ( $ask_photos ) {
				$lines[] = '- If photos would realistically help for this kind of job, also ask them to reply with clear photos/images attached.';
				$lines[] = '- Only request photos when visual evidence is useful (e.g. damage, leaks, installations, faults, site conditions). Skip photos for admin, hours, coverage-area, or purely informational questions.';
			} else {
				$lines[] = '- Do not ask for photos or images.';
			}

			$guidance = trim( (string) $this->settings->get( 'cf7_follow_up_guidance', '' ) );
			if ( '' !== $guidance ) {
				$lines[] = 'Additional follow-up guidance from the business: ' . $guidance;
			}
		}

		return implode( "\n", $lines );
	}

	/**
	 * User message for CF7 completion.
	 *
	 * @param array<string, string> $fields Mapped fields.
	 */
	public function cf7_user_message( array $fields ): string {
		return sprintf(
			"Name: %s\nEmail: %s\nMessage:\n%s",
			$fields['name'] ?? '',
			$fields['email'] ?? '',
			$fields['message'] ?? ''
		);
	}

	/**
	 * Business identity for grounding.
	 */
	private function business_identity_block(): string {
		$site_name = wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES );
		$name      = trim( (string) $this->settings->get( 'business_name', '' ) );
		if ( '' === $name ) {
			$name = $site_name;
		}

		$lines   = array();
		$lines[] = 'You are the on-site assistant for a single small/medium business.';
		$lines[] = 'Business name: ' . $name;
		$lines[] = 'Website: ' . $site_name . ' (' . home_url( '/' ) . ')';

		$trade = trim( (string) $this->settings->get( 'business_trade', '' ) );
		if ( '' !== $trade ) {
			$lines[] = 'Trade / industry: ' . $trade;
		}

		$services = trim( (string) $this->settings->get( 'business_services', '' ) );
		if ( '' !== $services ) {
			$lines[] = "Services offered:\n" . $services;
		}

		$area = trim( (string) $this->settings->get( 'business_service_area', '' ) );
		if ( '' !== $area ) {
			$lines[] = 'Service area: ' . $area;
		}

		$extra = trim( (string) $this->settings->get( 'business_notes', '' ) );
		if ( '' !== $extra ) {
			$lines[] = "Business facts:\n" . $extra;
		}

		return implode( "\n", $lines );
	}

	/**
	 * Hard scope rules so answers stay on this business.
	 */
	private function scope_rules_block(): string {
		$refuse = trim( (string) $this->settings->get( 'business_refuse_topics', '' ) );
		$handoff = trim( (string) $this->settings->get( 'business_handoff_message', '' ) );
		if ( '' === $handoff ) {
			$handoff = 'I can only help with questions about our business. Please ask about our services, or use the contact form and the team will get back to you.';
		}

		$rules   = array();
		$rules[] = 'SCOPE RULES (mandatory):';
		$rules[] = '1. Answer ONLY about this business: its services, service area, hours, process, and information published on this WordPress site or in the business profile/FAQ below.';
		$rules[] = '2. Do NOT provide general knowledge, DIY tutorials, industry advice for other companies, news, or comparisons with competitors.';
		$rules[] = '3. Do NOT recommend, describe, or promote other businesses, brands, or tradespeople.';
		$rules[] = '4. Do NOT invent prices, quotes, licences, insurance details, availability, or guarantees that are not explicitly stated in the provided business profile, FAQ, or site content.';
		$rules[] = '5. If the user asks something outside scope, briefly refuse and use this handoff: "' . $handoff . '"';
		$rules[] = '6. Prefer site content and business profile over assumptions. If information is missing, say you are not sure and suggest contacting the business.';

		if ( '' !== $refuse ) {
			$rules[] = '7. Also refuse these topics: ' . $refuse;
		}

		return implode( "\n", $rules );
	}
}
