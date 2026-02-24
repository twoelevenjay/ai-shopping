<?php
/**
 * API key authentication.
 *
 * @package AIShopping\Security
 */

namespace AIShopping\Security;

defined( 'ABSPATH' ) || exit;

/**
 * Handles API key generation, storage, and validation.
 */
class Auth {

	/**
	 * Custom table name (without prefix).
	 */
	const TABLE = 'ais_api_keys';

	/**
	 * Permission levels.
	 */
	const PERM_READ      = 'read';
	const PERM_READWRITE = 'read_write';
	const PERM_FULL      = 'full';

	/**
	 * Create the API keys table.
	 */
	public static function create_tables() {
		global $wpdb;
		$table   = $wpdb->prefix . self::TABLE;
		$charset = $wpdb->get_charset_collate();

		$sql = "CREATE TABLE {$table} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			user_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
			label VARCHAR(200) NOT NULL DEFAULT '',
			consumer_key VARCHAR(64) NOT NULL,
			consumer_secret_hash VARCHAR(64) NOT NULL,
			webhook_secret VARCHAR(64) NOT NULL DEFAULT '',
			permissions VARCHAR(20) NOT NULL DEFAULT 'read',
			rate_limit_read INT UNSIGNED NOT NULL DEFAULT 0,
			rate_limit_write INT UNSIGNED NOT NULL DEFAULT 0,
			last_used DATETIME DEFAULT NULL,
			created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
			is_revoked TINYINT(1) NOT NULL DEFAULT 0,
			PRIMARY KEY (id),
			KEY consumer_key (consumer_key)
		) {$charset};";

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( $sql );
	}

	/**
	 * Generate a new API key pair.
	 *
	 * @param string $label       Human-readable label.
	 * @param string $permissions Permission level.
	 * @param int    $user_id     WordPress user ID.
	 * @return array{consumer_key: string, consumer_secret: string, id: int}
	 */
	public static function generate_key( $label, $permissions = 'read', $user_id = 0 ) {
		global $wpdb;

		$consumer_key    = 'ais_' . wp_generate_password( 32, false );
		$consumer_secret = 'ais_' . wp_generate_password( 48, false );
		$webhook_secret  = wp_generate_password( 32, false );

		$wpdb->insert(
			$wpdb->prefix . self::TABLE,
			array(
				'user_id'              => $user_id ? $user_id : get_current_user_id(),
				'label'                => sanitize_text_field( $label ),
				'consumer_key'         => self::hash_key( $consumer_key ),
				'consumer_secret_hash' => self::hash_key( $consumer_secret ),
				'webhook_secret'       => $webhook_secret,
				'permissions'          => in_array( $permissions, array( self::PERM_READ, self::PERM_READWRITE, self::PERM_FULL ), true ) ? $permissions : self::PERM_READ,
				'created_at'           => current_time( 'mysql' ),
			),
			array( '%d', '%s', '%s', '%s', '%s', '%s', '%s' )
		);

		return array(
			'id'              => $wpdb->insert_id,
			'consumer_key'    => $consumer_key,
			'consumer_secret' => $consumer_secret,
			'webhook_secret'  => $webhook_secret,
		);
	}

	/**
	 * Validate a Bearer token (consumer_secret) from request.
	 *
	 * @param \WP_REST_Request $request The incoming request.
	 * @return array|false Key row on success, false on failure.
	 */
	public static function validate_request( $request ) {
		$auth_header = $request->get_header( 'Authorization' );
		if ( empty( $auth_header ) ) {
			return false;
		}

		if ( 0 !== strpos( $auth_header, 'Bearer ' ) ) {
			return false;
		}

		$token = substr( $auth_header, 7 );
		return self::validate_token( $token );
	}

	/**
	 * Validate a token string.
	 *
	 * @param string $token The consumer secret.
	 * @return array|false Key row on success, false on failure.
	 */
	public static function validate_token( $token ) {
		global $wpdb;

		$hash = self::hash_key( $token );
		$row  = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM {$wpdb->prefix}" . self::TABLE . ' WHERE consumer_secret_hash = %s AND is_revoked = 0',
				$hash
			),
			ARRAY_A
		);

		if ( ! $row ) {
			return false;
		}

		// Update last used.
		$wpdb->update(
			$wpdb->prefix . self::TABLE,
			array( 'last_used' => current_time( 'mysql' ) ),
			array( 'id' => $row['id'] ),
			array( '%s' ),
			array( '%d' )
		);

		return $row;
	}

	/**
	 * Check if a key has permission for an operation.
	 *
	 * @param array  $key_row   The key data.
	 * @param string $operation 'read' or 'write'.
	 * @return bool
	 */
	public static function has_permission( $key_row, $operation = 'read' ) {
		if ( empty( $key_row ) ) {
			return false;
		}

		$perms = $key_row['permissions'];

		if ( self::PERM_FULL === $perms ) {
			return true;
		}

		if ( self::PERM_READWRITE === $perms ) {
			return true;
		}

		// Read-only.
		return 'read' === $operation;
	}

	/**
	 * List all API keys (without secrets).
	 *
	 * @return array
	 */
	public static function list_keys() {
		global $wpdb;

		return $wpdb->get_results(
			"SELECT id, user_id, label, permissions, rate_limit_read, rate_limit_write, last_used, created_at, is_revoked FROM {$wpdb->prefix}" . self::TABLE . ' ORDER BY created_at DESC',
			ARRAY_A
		);
	}

	/**
	 * Revoke an API key.
	 *
	 * @param int $key_id The key ID.
	 * @return bool
	 */
	public static function revoke_key( $key_id ) {
		global $wpdb;

		return (bool) $wpdb->update(
			$wpdb->prefix . self::TABLE,
			array( 'is_revoked' => 1 ),
			array( 'id' => absint( $key_id ) ),
			array( '%d' ),
			array( '%d' )
		);
	}

	/**
	 * Delete an API key.
	 *
	 * @param int $key_id The key ID.
	 * @return bool
	 */
	public static function delete_key( $key_id ) {
		global $wpdb;

		return (bool) $wpdb->delete(
			$wpdb->prefix . self::TABLE,
			array( 'id' => absint( $key_id ) ),
			array( '%d' )
		);
	}

	/**
	 * Hash a key for storage.
	 *
	 * @param string $key The key to hash.
	 * @return string
	 */
	private static function hash_key( $key ) {
		return hash( 'sha256', $key );
	}
}
