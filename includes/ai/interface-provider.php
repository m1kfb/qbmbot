<?php
/**
 * AI provider contract.
 *
 * @package QBMBot
 */

declare(strict_types=1);

namespace QBMBot\AI;

/**
 * Provider interface for LLM backends.
 */
interface Provider {

	/**
	 * Provider slug.
	 */
	public function slug(): string;

	/**
	 * Whether credentials look configured.
	 */
	public function is_configured(): bool;

	/**
	 * Generate a completion.
	 *
	 * @param string               $system  System prompt.
	 * @param array<int, array{role:string,content:string}> $messages Conversation turns.
	 * @return array{ok:bool,content:string,tokens:int,error:string}
	 */
	public function complete( string $system, array $messages ): array;
}
