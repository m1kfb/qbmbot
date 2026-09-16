<?php
/**
 * Simple PSR-4-ish autoloader for the QBMBot namespace.
 *
 * @package QBMBot
 */

declare(strict_types=1);

namespace QBMBot;

/**
 * Maps QBMBot\* classes to includes/ files.
 */
final class Autoloader {

	/**
	 * Register the autoloader.
	 */
	public static function register(): void {
		spl_autoload_register( array( self::class, 'load' ) );
	}

	/**
	 * Load a class file if it belongs to this plugin.
	 *
	 * @param string $class Fully-qualified class name.
	 */
	public static function load( string $class ): void {
		$prefix = 'QBMBot\\';

		if ( 0 !== strpos( $class, $prefix ) ) {
			return;
		}

		$relative = substr( $class, strlen( $prefix ) );
		$relative = str_replace( '\\', '/', $relative );

		// Convert Class_Name / Interface_Name to class-name / interface-name.
		$parts     = explode( '/', $relative );
		$file_part = array_pop( $parts );
		$file_part = strtolower( str_replace( '_', '-', $file_part ) );

		if ( 0 === strpos( $file_part, 'interface-' ) ) {
			$filename = $file_part . '.php';
		} else {
			$filename = 'class-' . $file_part . '.php';
		}

		$subdir = '';
		if ( ! empty( $parts ) ) {
			$subdir = strtolower( implode( '/', $parts ) ) . '/';
		}

		$base = QBMBot_PATH . 'includes/' . $subdir;
		$path = $base . $filename;

		if ( ! is_readable( $path ) ) {
			// Interfaces may be named Provider → interface-provider.php.
			$alt = $base . 'interface-' . $file_part . '.php';
			if ( is_readable( $alt ) ) {
				$path = $alt;
			}
		}

		if ( is_readable( $path ) ) {
			require_once $path;
		}
	}
}
