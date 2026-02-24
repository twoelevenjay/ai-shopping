<?php
/**
 * Headless cart session management.
 *
 * @package AIShopping\Cart
 */

namespace AIShopping\Cart;

defined( 'ABSPATH' ) || exit;

/**
 * Token-based cart sessions stored in a custom table, independent of WC browser sessions.
 */
class Cart_Session {

	const TABLE = 'ais_cart_sessions';

	/**
	 * Default cart TTL: 24 hours.
	 */
	const DEFAULT_TTL = 86400;

	/**
	 * Create the cart sessions table.
	 */
	public static function create_tables() {
		global $wpdb;
		$table   = $wpdb->prefix . self::TABLE;
		$charset = $wpdb->get_charset_collate();

		$sql = "CREATE TABLE {$table} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			cart_token VARCHAR(64) NOT NULL,
			api_key_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
			cart_data LONGTEXT NOT NULL,
			customer_data LONGTEXT NOT NULL DEFAULT '',
			expires_at DATETIME NOT NULL,
			created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
			updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY (id),
			UNIQUE KEY cart_token (cart_token),
			KEY expires_at (expires_at)
		) {$charset};";

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( $sql );
	}

	/**
	 * Create a new cart session.
	 *
	 * @param int $api_key_id The API key ID.
	 * @return string The cart token.
	 */
	public static function create( $api_key_id = 0 ) {
		global $wpdb;

		$token = wp_generate_password( 48, false );

		$wpdb->insert(
			$wpdb->prefix . self::TABLE,
			array(
				'cart_token'    => $token,
				'api_key_id'   => $api_key_id,
				'cart_data'     => wp_json_encode( array( 'items' => array(), 'coupons' => array() ) ),
				'customer_data' => wp_json_encode( array() ),
				'expires_at'   => gmdate( 'Y-m-d H:i:s', time() + self::DEFAULT_TTL ),
				'created_at'   => current_time( 'mysql' ),
				'updated_at'   => current_time( 'mysql' ),
			),
			array( '%s', '%d', '%s', '%s', '%s', '%s', '%s' )
		);

		return $token;
	}

	/**
	 * Load a cart session by token.
	 *
	 * @param string $token Cart token.
	 * @return array|null Cart data or null if not found/expired.
	 */
	public static function load( $token ) {
		global $wpdb;

		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM {$wpdb->prefix}" . self::TABLE . ' WHERE cart_token = %s AND expires_at > NOW()',
				$token
			),
			ARRAY_A
		);

		if ( ! $row ) {
			return null;
		}

		return array(
			'id'            => (int) $row['id'],
			'token'         => $row['cart_token'],
			'cart_data'     => json_decode( $row['cart_data'], true ),
			'customer_data' => json_decode( $row['customer_data'], true ),
			'expires_at'    => $row['expires_at'],
		);
	}

	/**
	 * Save cart data.
	 *
	 * @param string $token     Cart token.
	 * @param array  $cart_data Cart data array.
	 * @return bool
	 */
	public static function save( $token, $cart_data ) {
		global $wpdb;

		return (bool) $wpdb->update(
			$wpdb->prefix . self::TABLE,
			array(
				'cart_data'  => wp_json_encode( $cart_data ),
				'updated_at' => current_time( 'mysql' ),
				'expires_at' => gmdate( 'Y-m-d H:i:s', time() + self::DEFAULT_TTL ),
			),
			array( 'cart_token' => $token ),
			array( '%s', '%s', '%s' ),
			array( '%s' )
		);
	}

	/**
	 * Save customer data (addresses, etc.).
	 *
	 * @param string $token         Cart token.
	 * @param array  $customer_data Customer data.
	 * @return bool
	 */
	public static function save_customer_data( $token, $customer_data ) {
		global $wpdb;

		return (bool) $wpdb->update(
			$wpdb->prefix . self::TABLE,
			array(
				'customer_data' => wp_json_encode( $customer_data ),
				'updated_at'    => current_time( 'mysql' ),
			),
			array( 'cart_token' => $token ),
			array( '%s', '%s' ),
			array( '%s' )
		);
	}

	/**
	 * Delete a cart session.
	 *
	 * @param string $token Cart token.
	 * @return bool
	 */
	public static function delete( $token ) {
		global $wpdb;

		return (bool) $wpdb->delete(
			$wpdb->prefix . self::TABLE,
			array( 'cart_token' => $token ),
			array( '%s' )
		);
	}

	/**
	 * Clean up expired sessions.
	 */
	public static function cleanup_expired() {
		global $wpdb;

		$wpdb->query( "DELETE FROM {$wpdb->prefix}" . self::TABLE . ' WHERE expires_at < NOW()' );
	}

	/**
	 * Get the cart token from the request.
	 *
	 * @param \WP_REST_Request $request The request.
	 * @return string|null
	 */
	public static function get_token_from_request( $request ) {
		$token = $request->get_header( 'X-Cart-Token' );
		if ( $token ) {
			return sanitize_text_field( $token );
		}

		// Also check query param as fallback.
		$param = $request->get_param( 'cart_token' );
		return $param ? sanitize_text_field( $param ) : null;
	}

	/**
	 * Use WooCommerce's cart to calculate totals for a headless cart.
	 *
	 * Temporarily loads items into WC()->cart, calculates, then extracts results.
	 *
	 * @param array $cart_data     Cart data from session.
	 * @param array $customer_data Customer data (addresses).
	 * @return array Calculated cart data with totals.
	 */
	public static function calculate_totals( $cart_data, $customer_data = array() ) {
		// Ensure WC is loaded.
		if ( ! function_exists( 'WC' ) ) {
			return $cart_data;
		}

		// Initialize WC session and cart if needed.
		if ( ! WC()->session ) {
			WC()->session = new \WC_Session_Handler();
			WC()->session->init();
		}

		if ( ! WC()->cart ) {
			WC()->cart = new \WC_Cart();
		}

		if ( ! WC()->customer ) {
			WC()->customer = new \WC_Customer( 0, true );
		}

		// Set customer address if provided.
		if ( ! empty( $customer_data['shipping_address'] ) ) {
			$addr = $customer_data['shipping_address'];
			WC()->customer->set_shipping_country( $addr['country'] ?? '' );
			WC()->customer->set_shipping_state( $addr['state'] ?? '' );
			WC()->customer->set_shipping_postcode( $addr['postcode'] ?? '' );
			WC()->customer->set_shipping_city( $addr['city'] ?? '' );
		}

		if ( ! empty( $customer_data['billing_address'] ) ) {
			$addr = $customer_data['billing_address'];
			WC()->customer->set_billing_country( $addr['country'] ?? '' );
			WC()->customer->set_billing_state( $addr['state'] ?? '' );
			WC()->customer->set_billing_postcode( $addr['postcode'] ?? '' );
			WC()->customer->set_billing_city( $addr['city'] ?? '' );
		}

		// Empty the WC cart and load our items.
		WC()->cart->empty_cart();

		$key_map = array();
		if ( ! empty( $cart_data['items'] ) ) {
			foreach ( $cart_data['items'] as $idx => $item ) {
				$wc_key = WC()->cart->add_to_cart(
					$item['product_id'],
					$item['quantity'],
					$item['variation_id'] ?? 0,
					$item['variation'] ?? array()
				);
				if ( $wc_key ) {
					$key_map[ $wc_key ] = $idx;
				}
			}
		}

		// Apply coupons.
		if ( ! empty( $cart_data['coupons'] ) ) {
			foreach ( $cart_data['coupons'] as $code ) {
				WC()->cart->apply_coupon( $code );
			}
		}

		// Calculate.
		WC()->cart->calculate_totals();

		// Extract calculated item data.
		$items = array();
		foreach ( WC()->cart->get_cart() as $wc_key => $wc_item ) {
			$product = $wc_item['data'];
			$items[] = array(
				'key'            => $wc_key,
				'product_id'     => $wc_item['product_id'],
				'variation_id'   => $wc_item['variation_id'] ?? 0,
				'variation'      => $wc_item['variation'] ?? array(),
				'quantity'       => $wc_item['quantity'],
				'name'           => $product->get_name(),
				'sku'            => $product->get_sku(),
				'price'          => $product->get_price(),
				'line_subtotal'  => (float) $wc_item['line_subtotal'],
				'line_total'     => (float) $wc_item['line_total'],
				'line_tax'       => (float) $wc_item['line_tax'],
				'image'          => wp_get_attachment_url( $product->get_image_id() ) ?: '',
			);
		}

		// Extract totals.
		$result = array(
			'items'            => $items,
			'coupons'          => array_keys( WC()->cart->get_coupons() ),
			'item_count'       => WC()->cart->get_cart_contents_count(),
			'subtotal'         => (float) WC()->cart->get_subtotal(),
			'discount_total'   => (float) WC()->cart->get_discount_total(),
			'shipping_total'   => (float) WC()->cart->get_shipping_total(),
			'tax_total'        => (float) WC()->cart->get_total_tax(),
			'total'            => (float) WC()->cart->get_total( 'raw' ),
			'needs_shipping'   => WC()->cart->needs_shipping(),
			'currency'         => get_woocommerce_currency(),
		);

		// Clean up.
		WC()->cart->empty_cart();

		return $result;
	}
}
