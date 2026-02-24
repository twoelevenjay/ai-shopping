<?php
/**
 * HMAC signing for webhook events.
 *
 * @package AIShopping\Security
 */

namespace AIShopping\Security;

defined( 'ABSPATH' ) || exit;

/**
 * HMAC utility for signing webhook payloads.
 */
class HMAC {

	/**
	 * Sign a payload with a secret.
	 *
	 * @param string $payload JSON payload.
	 * @param string $secret  Webhook secret.
	 * @return string HMAC-SHA256 hex signature.
	 */
	public static function sign( $payload, $secret ) {
		return hash_hmac( 'sha256', $payload, $secret );
	}

	/**
	 * Verify an HMAC signature.
	 *
	 * @param string $payload   JSON payload.
	 * @param string $secret    Webhook secret.
	 * @param string $signature The provided signature.
	 * @return bool
	 */
	public static function verify( $payload, $secret, $signature ) {
		$expected = self::sign( $payload, $secret );
		return hash_equals( $expected, $signature );
	}
}
