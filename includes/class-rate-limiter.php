<?php
/**
 * Rate limiting for AI calls.
 *
 * @package QBMBot
 */

declare(strict_types=1);

namespace QBMBot;

/**
 * Transient-based rate limiter.
 */
final class Rate_Limiter {

	/**
	 * Settings.
	 *
	 * @var Settings
	 */
	private Settings $settings;

	/**
	 * Constructor.
	 *
	 * @param Settings $settings Settings.
	 */
	public function __construct( Settings $settings ) {
		$this->settings = $settings;
	}

	/**
	 * Check whether a request is allowed.
	 *
	 * @param string $ip        Client IP.
	 * @param string $session_id Session id.
	 * @return array{allowed:bool,reason:string}
	 */
	public function allow( string $ip, string $session_id ): array {
		$ip_limit  = (int) $this->settings->get( 'rate_ip_limit', 10 );
		$ip_window = (int) $this->settings->get( 'rate_ip_window', 600 );
		if ( $this->count( $this->ip_key( $ip ) ) >= $ip_limit ) {
			return array( 'allowed' => false, 'reason' => 'ip_limit' );
		}

		$session_limit  = (int) $this->settings->get( 'rate_session_limit', 30 );
		$session_window = (int) $this->settings->get( 'rate_session_window', DAY_IN_SECONDS );
		if ( '' !== $session_id && $this->count( $this->session_key( $session_id ) ) >= $session_limit ) {
			return array( 'allowed' => false, 'reason' => 'session_limit' );
		}

		$daily_cap = (int) $this->settings->get( 'rate_daily_site_cap', 500 );
		if ( $this->count( $this->daily_key() ) >= $daily_cap ) {
			return array( 'allowed' => false, 'reason' => 'daily_cap' );
		}

		return array( 'allowed' => true, 'reason' => '' );
	}

	/**
	 * Record a successful (or attempted) AI-bound request.
	 *
	 * @param string $ip         Client IP.
	 * @param string $session_id Session id.
	 */
	public function hit( string $ip, string $session_id ): void {
		$ip_window      = (int) $this->settings->get( 'rate_ip_window', 600 );
		$session_window = (int) $this->settings->get( 'rate_session_window', DAY_IN_SECONDS );

		$this->increment( $this->ip_key( $ip ), $ip_window );
		if ( '' !== $session_id ) {
			$this->increment( $this->session_key( $session_id ), $session_window );
		}
		$this->increment( $this->daily_key(), DAY_IN_SECONDS );
	}

	/**
	 * Current usage counters for admin display.
	 *
	 * @return array<string, int>
	 */
	public function usage_snapshot(): array {
		return array(
			'daily_used'  => $this->count( $this->daily_key() ),
			'daily_cap'   => (int) $this->settings->get( 'rate_daily_site_cap', 500 ),
			'ip_limit'    => (int) $this->settings->get( 'rate_ip_limit', 10 ),
			'session_lim' => (int) $this->settings->get( 'rate_session_limit', 30 ),
		);
	}

	/**
	 * @param string $key Transient key.
	 */
	private function count( string $key ): int {
		$val = get_transient( $key );
		return is_numeric( $val ) ? (int) $val : 0;
	}

	/**
	 * @param string $key  Transient key.
	 * @param int    $ttl  TTL seconds.
	 */
	private function increment( string $key, int $ttl ): void {
		$ttl = max( 60, $ttl );
		$val = $this->count( $key ) + 1;
		set_transient( $key, $val, $ttl );
	}

	/**
	 * @param string $ip IP.
	 */
	private function ip_key( string $ip ): string {
		return 'qbmbot_rl_ip_' . md5( $ip );
	}

	/**
	 * @param string $session Session id.
	 */
	private function session_key( string $session ): string {
		return 'qbmbot_rl_sess_' . md5( $session );
	}

	/**
	 * Site-wide daily key.
	 */
	private function daily_key(): string {
		return 'qbmbot_rl_day_' . gmdate( 'Ymd' );
	}
}
