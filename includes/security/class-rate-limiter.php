<?php
/**
 * Rate limiter.
 *
 * @package AIShopping\Security
 */

namespace AIShopping\Security;

defined( 'ABSPATH' ) || exit;

/**
 * Token-bucket rate limiter using a custom DB table.
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
			key_id BIGINT UNSIGNED NOT NULL,
			bucket VARCHAR(10) NOT NULL DEFAULT 'read',
			tokens INT UNSIGNED NOT NULL DEFAULT 0,
			last_refill DATETIME NOT NULL,
			PRIMARY KEY (id),
			UNIQUE KEY key_bucket (key_id, bucket)
		) {$charset};";

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( $sql );
	}

	/**
	 * Check and consume a rate limit token.
	 *
	 * @param array  $key_row   API key row from Auth.
	 * @param string $operation 'read' or 'write'.
	 * @return array{allowed: bool, limit: int, remaining: int, reset: int}
	 */
	public static function check( $key_row, $operation = 'read' ) {
		$bucket    = 'write' === $operation ? 'write' : 'read';
		$key_field = 'write' === $operation ? 'rate_limit_write' : 'rate_limit_read';

		// Per-key override or global default.
		$limit = ! empty( $key_row[ $key_field ] ) ? (int) $key_row[ $key_field ] : 0;
		if ( 0 === $limit ) {
			$default_option = 'write' === $operation ? 'ais_rate_limit_write' : 'ais_rate_limit_read';
			$limit          = (int) get_option( $default_option, 'write' === $operation ? 30 : 60 );
		}

		if ( 0 === $limit ) {
			// Rate limiting disabled.
			return array(
				'allowed'   => true,
				'limit'     => 0,
				'remaining' => 0,
				'reset'     => 0,
			);
		}

		global $wpdb;
		$table = $wpdb->prefix . self::TABLE;
		$now   = current_time( 'mysql' );

		// Get or create bucket.
		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM {$table} WHERE key_id = %d AND bucket = %s",
				$key_row['id'],
				$bucket
			),
			ARRAY_A
		);

		if ( ! $row ) {
			$wpdb->insert(
				$table,
				array(
					'key_id'      => $key_row['id'],
					'bucket'      => $bucket,
					'tokens'      => $limit - 1,
					'last_refill' => $now,
				),
				array( '%d', '%s', '%d', '%s' )
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
				'key_id' => $key_row['id'],
				'bucket' => $bucket,
			),
			array( '%d', '%s' ),
			array( '%d', '%s' )
		);

		return array(
			'allowed'   => true,
			'limit'     => $limit,
			'remaining' => $tokens - 1,
			'reset'     => time() + 60,
		);
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
