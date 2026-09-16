<?php
/**
 * Plugin Name:       QBMBOT
 * Plugin URI:        https://github.com/m1kfb/qbmbot
 * Description:       AI chat widget and Contact Form 7 email auto-responder with spam protection and multi-provider AI.
 * Version:           1.1.4
 * Requires at least: 6.4
 * Requires PHP:      8.1
 * Author:            Queen B Marketing
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       qbmbot
 * Domain Path:       /languages
 *
 * @package QBMBot
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'QBMBot_VERSION', '1.1.4' );
define( 'QBMBot_FILE', __FILE__ );
define( 'QBMBot_PATH', plugin_dir_path( __FILE__ ) );
define( 'QBMBot_URL', plugin_dir_url( __FILE__ ) );
define( 'QBMBot_BASENAME', plugin_basename( __FILE__ ) );

require_once QBMBot_PATH . 'includes/class-autoloader.php';

QBMBot\Autoloader::register();

register_activation_hook( __FILE__, array( 'QBMBot\\Activator', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'QBMBot\\Deactivator', 'deactivate' ) );

add_action(
	'plugins_loaded',
	static function (): void {
		QBMBot\Plugin::instance()->boot();
	}
);
