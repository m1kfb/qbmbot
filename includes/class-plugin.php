<?php
/**
 * Main plugin bootstrap.
 *
 * @package QBMBot
 */

declare(strict_types=1);

namespace QBMBot;

/**
 * Wires services and WordPress hooks.
 */
final class Plugin {

	/**
	 * Singleton instance.
	 *
	 * @var self|null
	 */
	private static ?self $instance = null;

	/**
	 * Get singleton.
	 */
	public static function instance(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Boot plugin services.
	 */
	public function boot(): void {
		load_plugin_textdomain( 'qbmbot', false, dirname( QBMBot_BASENAME ) . '/languages' );

		$settings = new Settings();
		$faq      = new FAQ_Store( $settings );
		$content  = new Content_Source( $settings );
		$logger   = new Logger();
		$spam     = new Spam_Guard( $settings );
		$limiter  = new Rate_Limiter( $settings );
		$router   = new AI\Router( $settings );
		$prompts  = new AI\Prompt_Builder( $settings, $faq, $content );
		$intake   = new Job_Intake();
		$fallback = new Chat_Fallback( $settings, $intake );
		$mailer   = new Mailer( $settings );
		$leads    = new Lead_Capture( $settings, $mailer, $logger, $intake );

		( new Assets( $settings, $faq ) )->register();
		( new REST( $settings, $faq, $spam, $limiter, $router, $prompts, $fallback, $leads, $logger ) )->register();
		( new CF7( $settings, $spam, $limiter, $router, $prompts, $mailer, $logger ) )->register();
		( new Admin( $settings, $faq, $logger, $limiter ) )->register();
		( new Updater() )->register();
	}
}
