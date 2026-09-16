<?php
/**
 * Uninstall QBMBOT.
 *
 * @package QBMBot
 */

declare(strict_types=1);

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

$options = get_option( 'qbmbot_settings', array() );
$delete  = ! empty( $options['delete_data_on_uninstall'] );

if ( ! $delete ) {
	return;
}

delete_option( 'qbmbot_settings' );
delete_option( 'qbmbot_faq' );

global $wpdb;
$table = $wpdb->prefix . 'qbmbot_logs';
// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
$wpdb->query( "DROP TABLE IF EXISTS {$table}" );

$transients = $wpdb->get_col(
	$wpdb->prepare(
		"SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s",
		$wpdb->esc_like( '_transient_qbmbot_' ) . '%',
		$wpdb->esc_like( '_transient_timeout_qbmbot_' ) . '%'
	)
);

foreach ( $transients as $name ) {
	if ( 0 === strpos( $name, '_transient_timeout_' ) ) {
		$key = substr( $name, strlen( '_transient_timeout_' ) );
		delete_transient( $key );
	} elseif ( 0 === strpos( $name, '_transient_' ) ) {
		$key = substr( $name, strlen( '_transient_' ) );
		delete_transient( $key );
	}
}
