<?php
/**
 * Plugin deactivation.
 *
 * @package QBMBot
 */

declare(strict_types=1);

namespace QBMBot;

/**
 * Runs on plugin deactivation.
 */
final class Deactivator {

	/**
	 * Deactivate the plugin.
	 */
	public static function deactivate(): void {
		flush_rewrite_rules();
	}
}
