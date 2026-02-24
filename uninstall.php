<?php
/**
 * Uninstall AI Shopping for WooCommerce.
 *
 * Removes all plugin data: custom tables, options, transients.
 *
 * @package AIShopping
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

global $wpdb;

// Drop custom tables.
$tables = array(
	$wpdb->prefix . 'ais_api_keys',
	$wpdb->prefix . 'ais_cart_sessions',
	$wpdb->prefix . 'ais_rate_limits',
);

foreach ( $tables as $table ) {
	$wpdb->query( "DROP TABLE IF EXISTS {$table}" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
}

// Delete options.
$options = array(
	'ais_version',
	'ais_db_version',
	'ais_enable_acp',
	'ais_enable_ucp',
	'ais_enable_mcp',
	'ais_rate_limit_read',
	'ais_rate_limit_write',
	'ais_enable_logging',
	'ais_allow_http',
	'ais_webhook_url',
	'ais_webhook_secret',
);

foreach ( $options as $option ) {
	delete_option( $option );
}

// Delete transients.
delete_transient( 'ais_extension_scan' );

// Clear scheduled events.
wp_clear_scheduled_hook( 'ais_daily_extension_scan' );
wp_clear_scheduled_hook( 'ais_cleanup_expired_carts' );
wp_clear_scheduled_hook( 'ais_cleanup_rate_limits' );
