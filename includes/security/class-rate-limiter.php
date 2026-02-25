<?php
/**
 * Rate limiter.
 *
 * @package AIShopping\Security
 */

namespace AIShopping\Security;

defined( 'ABSPATH' ) || exit;

/**
 * Token-bucket rate limiter using a custom DB table, keyed by IP address.
 */
class Rate_Limiter {

	const TABLE = 'ais_rate_limits';

	/**
	 * Create the rate limits table.
	 */
	public static function create_tables() {
		global $wpdb;
		$table   = $wpdb->prefix . self::TABLE;
		$charset = $wpdb->get_charset_collate();

		$sql = "CREATE TABLE {$table} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			ip_address VARCHAR(45) NOT NULL,
			bucket VARCHAR(10) NOT NULL DEFAULT 'read',
			tokens INT UNSIGNED NOT NULL DEFAULT 0,
			last_refill DATETIME NOT NULL,
			PRIMARY KEY (id),
			UNIQUE KEY ip_bucket (ip_address, bucket)
		) {$charset};";

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( $sql );
	}

	/**
	 * Check and consume a rate limit token.
	 *
	 * @param string $operation 'read' or 'write'.
	 * @return array{allowed: bool, limit: int, remaining: int, reset: int}
	 */
	public static function check( $operation = 'read' ) {
		$bucket = 'write' === $operation ? 'write' : 'read';

		$default_option = 'write' === $operation ? 'ais_rate_limit_write' : 'ais_rate_limit_read';
		$limit          = (int) get_option( $default_option, 'write' === $operation ? 30 : 60 );

		if ( 0 === $limit ) {
			// Rate limiting disabled.
			return array(
				'allowed'   => true,
				'limit'     => 0,
				'remaining' => 0,
				'reset'     => 0,
			);
		}

		$ip = self::get_client_ip();

		global $wpdb;
		$table = $wpdb->prefix . self::TABLE;
		$now   = current_time( 'mysql' );

		// Get or create bucket.
		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM {$table} WHERE ip_address = %s AND bucket = %s",
				$ip,
				$bucket
			),
			ARRAY_A
		);

		if ( ! $row ) {
			$wpdb->insert(
				$table,
				array(
					'ip_address'  => $ip,
					'bucket'      => $bucket,
					'tokens'      => $limit - 1,
					'last_refill' => $now,
				),
				array( '%s', '%s', '%d', '%s' )
			);

			return array(
				'allowed'   => true,
				'limit'     => $limit,
				'remaining' => $limit - 1,
				'reset'     => time() + 60,
			);
		}

		// Refill tokens based on elapsed time.
		$elapsed = time() - strtotime( $row['last_refill'] );
		$refill  = (int) floor( $elapsed / 60 ) * $limit;
		$tokens  = min( $limit, (int) $row['tokens'] + $refill );

		$last_refill = $refill > 0 ? $now : $row['last_refill'];

		if ( $tokens <= 0 ) {
			$reset = strtotime( $row['last_refill'] ) + 60;
			return array(
				'allowed'   => false,
				'limit'     => $limit,
				'remaining' => 0,
				'reset'     => $reset,
			);
		}

		// Consume a token.
		$wpdb->update(
			$table,
			array(
				'tokens'      => $tokens - 1,
				'last_refill' => $last_refill,
			),
			array(
				'ip_address' => $ip,
				'bucket'     => $bucket,
			),
			array( '%d', '%s' ),
			array( '%s', '%s' )
		);

		return array(
			'allowed'   => true,
			'limit'     => $limit,
			'remaining' => $tokens - 1,
			'reset'     => time() + 60,
		);
	}

	/**
	 * Get the client IP address.
	 *
	 * @return string
	 */
	private static function get_client_ip() {
		$headers = array( 'HTTP_X_FORWARDED_FOR', 'HTTP_X_REAL_IP', 'REMOTE_ADDR' );
		foreach ( $headers as $header ) {
			if ( ! empty( $_SERVER[ $header ] ) ) {
				// X-Forwarded-For may contain multiple IPs — take the first.
				$ip = strtok( sanitize_text_field( wp_unslash( $_SERVER[ $header ] ) ), ',' );
				$ip = trim( $ip );
				if ( filter_var( $ip, FILTER_VALIDATE_IP ) ) {
					return $ip;
				}
			}
		}
		return '0.0.0.0';
	}

	/**
	 * Clean up stale rate limit entries.
	 */
	public static function cleanup() {
		global $wpdb;
		$table = $wpdb->prefix . self::TABLE;
		$wpdb->query( "DELETE FROM {$table} WHERE last_refill < DATE_SUB(NOW(), INTERVAL 1 DAY)" );
	}
}
