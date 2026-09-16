<?php
/**
 * Plugin activation.
 *
 * @package QBMBot
 */

declare(strict_types=1);

namespace QBMBot;

/**
 * Runs on plugin activation.
 */
final class Activator {

	/**
	 * Activate the plugin: defaults + DB table.
	 */
	public static function activate(): void {
		Settings::ensure_defaults();
		Logger::create_table();
		flush_rewrite_rules();
	}
}
