<?php
/**
 * Usage / spam event logger.
 *
 * @package QBMBot
 */

declare(strict_types=1);

namespace QBMBot;

/**
 * Custom table logger for AI and spam events.
 */
final class Logger {

	/**
	 * Table name without prefix.
	 */
	public const TABLE = 'qbmbot_logs';

	/**
	 * Create the logs table.
	 */
	public static function create_table(): void {
		global $wpdb;
		$table           = $wpdb->prefix . self::TABLE;
		$charset_collate = $wpdb->get_charset_collate();

		$sql = "CREATE TABLE {$table} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			created_at datetime NOT NULL,
			channel varchar(20) NOT NULL DEFAULT 'chat',
			status varchar(20) NOT NULL DEFAULT 'ok',
			reason varchar(100) NOT NULL DEFAULT '',
			provider varchar(40) NOT NULL DEFAULT '',
			tokens int(11) NOT NULL DEFAULT 0,
			spam_score int(11) NOT NULL DEFAULT 0,
			ip_hash varchar(64) NOT NULL DEFAULT '',
			meta longtext NULL,
			PRIMARY KEY  (id),
			KEY created_at (created_at),
			KEY channel_status (channel, status)
		) {$charset_collate};";

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( $sql );
	}

	/**
	 * Insert a log row.
	 *
	 * @param array<string, mixed> $data Log data.
	 */
	public function log( array $data ): void {
		global $wpdb;
		$table = $wpdb->prefix . self::TABLE;

		$meta = $data['meta'] ?? null;
		if ( is_array( $meta ) ) {
			$meta = wp_json_encode( $meta );
		}

		$wpdb->insert(
			$table,
			array(
				'created_at' => current_time( 'mysql', true ),
				'channel'    => sanitize_key( (string) ( $data['channel'] ?? 'chat' ) ),
				'status'     => sanitize_key( (string) ( $data['status'] ?? 'ok' ) ),
				'reason'     => sanitize_text_field( (string) ( $data['reason'] ?? '' ) ),
				'provider'   => sanitize_text_field( (string) ( $data['provider'] ?? '' ) ),
				'tokens'     => (int) ( $data['tokens'] ?? 0 ),
				'spam_score' => (int) ( $data['spam_score'] ?? 0 ),
				'ip_hash'    => sanitize_text_field( (string) ( $data['ip_hash'] ?? '' ) ),
				'meta'       => $meta,
			),
			array( '%s', '%s', '%s', '%s', '%s', '%d', '%d', '%s', '%s' )
		);
	}

	/**
	 * Recent log rows.
	 *
	 * @param int $limit Max rows.
	 * @return array<int, object>
	 */
	public function recent( int $limit = 50 ): array {
		global $wpdb;
		$table = $wpdb->prefix . self::TABLE;
		$limit = max( 1, min( 200, $limit ) );

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} ORDER BY id DESC LIMIT %d", $limit ) );

		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * Hash an IP for privacy-friendly logging.
	 *
	 * @param string $ip IP address.
	 */
	public static function hash_ip( string $ip ): string {
		return hash_hmac( 'sha256', $ip, wp_salt( 'auth' ) );
	}
}
