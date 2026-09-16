<?php
/**
 * Routes requests to the active AI provider.
 *
 * @package QBMBot
 */

declare(strict_types=1);

namespace QBMBot\AI;

use QBMBot\Settings;

/**
 * Multi-provider router.
 */
final class Router {

	/**
	 * Settings.
	 *
	 * @var Settings
	 */
	private Settings $settings;

	/**
	 * Providers keyed by slug.
	 *
	 * @var array<string, Provider>
	 */
	private array $providers;

	/**
	 * Constructor.
	 *
	 * @param Settings $settings Settings.
	 */
	public function __construct( Settings $settings ) {
		$this->settings  = $settings;
		$this->providers = array(
			'openai'    => new OpenAI_Provider( $settings ),
			'anthropic' => new Anthropic_Provider( $settings ),
		);
	}

	/**
	 * Active provider slug.
	 */
	public function active_slug(): string {
		$slug = (string) $this->settings->get( 'active_provider', 'openai' );
		return isset( $this->providers[ $slug ] ) ? $slug : 'openai';
	}

	/**
	 * Active provider instance.
	 */
	public function active(): Provider {
		return $this->providers[ $this->active_slug() ];
	}

	/**
	 * Whether the active provider has credentials configured.
	 */
	public function is_active_configured(): bool {
		return $this->active()->is_configured();
	}

	/**
	 * Complete via active provider.
	 *
	 * @param string               $system   System prompt.
	 * @param array<int, array{role:string,content:string}> $messages Messages.
	 * @return array{ok:bool,content:string,tokens:int,error:string,provider:string}
	 */
	public function complete( string $system, array $messages ): array {
		$provider = $this->active();
		$result   = $provider->complete( $system, $messages );
		$result['provider'] = $provider->slug();
		return $result;
	}

	/**
	 * Available provider slugs.
	 *
	 * @return array<int, string>
	 */
	public function slugs(): array {
		return array_keys( $this->providers );
	}
}
